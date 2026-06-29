<?php

declare(strict_types=1);

namespace RhDbEngine;


final class Importer
{
    /** @var array<int, string> */
    private const ALLOWED_ENTRIES = ['db.sql', 'manifest.json'];

    private const URL_REWRITE_CHUNK = 200;

    public function __construct(
        private readonly Storage $storage,
        private readonly SearchReplace $searchReplace
    ) {
    }

    /**
     * Importiert ein Backup aus einer ZIP-Datei in einem Rutsch (Vollimport).
     *
     * Dünner Wrapper um die resume-fähige {@see importStep()}-State-Machine mit unbegrenztem
     * Zeitbudget: alle Phasen laufen nacheinander durch, das Verhalten ist identisch zum
     * früheren monolithischen Import. Wird von rh-backup (Restore) und vom Sync-Rollback genutzt.
     *
     * @param (callable(string): bool)|null $tableFilter fn(vollqualifizierter Tabellenname): bool
     * @return array<string, mixed> Manifest-Daten aus dem Backup
     * @throws \RuntimeException
     */
    public function importFromFile(string $zipPath, ?callable $tableFilter = null, bool $includeUploads = true): array
    {
        if (!is_readable($zipPath)) {
            throw new \RuntimeException('Backup-Datei nicht lesbar: ' . $zipPath);
        }

        $workdir = $this->storage->jobWorkdir('import-' . wp_generate_password(8, false, false));
        $cursor = ImportCursor::start($zipPath, $workdir);

        try {
            do {
                $cursor = $this->importStep($cursor, PHP_INT_MAX, $tableFilter, $includeUploads);
            } while (!$cursor->isDone());

            return $cursor->manifest;
        } finally {
            $this->cleanupDir($workdir);
        }
    }

    /**
     * Verarbeitet einen Import-Häppchen bis das Zeitbudget erschöpft ist, und gibt den
     * fortgeschrittenen Cursor zurück. Solange `!$cursor->isDone()`, muss der Aufrufer
     * erneut aufrufen (mit demselben, zurückgegebenen Cursor und demselben Table-Filter).
     *
     * Phasen: extract -> sql -> meta_rewrite -> url_rewrite -> uploads -> done.
     * Der Cursor steht in der sql-Phase immer auf einer Statement-Grenze (fseek-Resume),
     * ein durch das Budget unterbrochener Import ist konsistent fortsetzbar.
     *
     * @param float $budgetSeconds Zeitbudget für diesen Häppchen. Sub-Sekunden-Werte sind erlaubt
     *                             (jeder Tick macht mindestens einen Fortschritt, der Deadline-Check
     *                             greift erst nach einer vollständigen Einheit).
     * @param (callable(string): bool)|null $tableFilter Wird PRO Tick übergeben (nicht im Cursor
     *                                                    gespeichert), damit der Cursor serialisierbar bleibt.
     * @throws \RuntimeException
     */
    public function importStep(ImportCursor $cursor, float $budgetSeconds, ?callable $tableFilter = null, bool $includeUploads = true): ImportCursor
    {
        @set_time_limit(0);
        if (function_exists('wp_raise_memory_limit')) {
            wp_raise_memory_limit('admin');
        }

        $deadline = microtime(true) + max(0.1, $budgetSeconds);

        while (!$cursor->isDone() && microtime(true) < $deadline) {
            switch ($cursor->phase) {
                case ImportCursor::PHASE_EXTRACT:
                    $this->stepExtract($cursor);
                    break;
                case ImportCursor::PHASE_SQL:
                    $this->stepSql($cursor, $tableFilter, $deadline);
                    break;
                case ImportCursor::PHASE_META_REWRITE:
                    $this->stepMetaRewrite($cursor);
                    break;
                case ImportCursor::PHASE_URL_REWRITE:
                    $this->stepUrlRewrite($cursor, $deadline);
                    break;
                case ImportCursor::PHASE_UPLOADS:
                    $this->stepUploads($cursor, $includeUploads, $deadline);
                    break;
                default:
                    $cursor->phase = ImportCursor::PHASE_DONE;
            }
        }

        return $cursor;
    }

    // ============================================================
    // Phasen
    // ============================================================

    private function stepExtract(ImportCursor $cursor): void
    {
        $this->storage->ensureReady();

        $extractDir = trailingslashit($cursor->workdir) . 'extracted';
        wp_mkdir_p($extractDir);

        $this->extractZipSafely($cursor->zipPath, $extractDir);

        $sqlFile = trailingslashit($extractDir) . 'db.sql';
        $manifestFile = trailingslashit($extractDir) . 'manifest.json';

        if (!is_readable($sqlFile) || !is_readable($manifestFile)) {
            throw new \RuntimeException('Backup enthält weder db.sql noch manifest.json.');
        }

        /** @var array<string, mixed> $manifest */
        $manifest = (array) json_decode((string) file_get_contents($manifestFile), true);

        $sourcePrefix = isset($manifest['db_prefix']) ? (string) $manifest['db_prefix'] : '';
        if ($sourcePrefix === '') {
            throw new \RuntimeException('Backup-Manifest enthält keinen db_prefix. Aelteres Backup ohne Prefix-Info kann nicht sicher importiert werden.');
        }

        global $wpdb;
        $cursor->manifest = $manifest;
        $cursor->sourcePrefix = $sourcePrefix;
        $cursor->targetPrefix = (string) $wpdb->prefix;
        $cursor->includesUploads = !empty($manifest['includes_uploads']);
        $cursor->phase = ImportCursor::PHASE_SQL;
        $cursor->sqlByteOffset = 0;
    }

    /**
     * @param (callable(string): bool)|null $tableFilter
     */
    private function stepSql(ImportCursor $cursor, ?callable $tableFilter, float $deadline): void
    {
        global $wpdb;

        $sqlFile = trailingslashit($cursor->workdir) . 'extracted/db.sql';
        $handle = fopen($sqlFile, 'rb');
        if ($handle === false) {
            throw new \RuntimeException('SQL-Datei nicht lesbar.');
        }

        $sourcePrefix = $cursor->sourcePrefix;
        $targetPrefix = $cursor->targetPrefix;
        $needsRewrite = $sourcePrefix !== '' && $sourcePrefix !== $targetPrefix;
        $rewritePatterns = [];
        $rewriteReplacement = '';
        if ($needsRewrite) {
            $quoted = preg_quote($sourcePrefix, '/');
            $rewritePatterns = [
                '/^(DROP TABLE IF EXISTS )`' . $quoted . '/',
                '/^(CREATE TABLE )`' . $quoted . '/',
                '/^(INSERT INTO )`' . $quoted . '/',
            ];
            $rewriteReplacement = '$1`' . $targetPrefix;
        }

        try {
            if ($cursor->sqlByteOffset > 0) {
                fseek($handle, $cursor->sqlByteOffset);
            }

            $buffer = '';
            while (!feof($handle)) {
                $chunk = fgets($handle, 65535);
                if ($chunk === false) {
                    break;
                }

                $trimmed = ltrim($chunk);
                if ($trimmed === '' || str_starts_with($trimmed, '--')) {
                    continue;
                }

                if ($needsRewrite) {
                    $rewritten = preg_replace($rewritePatterns, $rewriteReplacement, $chunk);
                    if (is_string($rewritten)) {
                        $chunk = $rewritten;
                    }
                }

                $buffer .= $chunk;

                if (str_ends_with(rtrim($chunk), ';')) {
                    if ($this->shouldExecuteStatement($buffer, $tableFilter)) {
                        $wpdb->query($buffer);
                    }
                    $buffer = '';

                    // Cursor steht jetzt auf einer Statement-Grenze: sicherer Resume-Punkt.
                    if (microtime(true) >= $deadline) {
                        $cursor->sqlByteOffset = ftell($handle) ?: $cursor->sqlByteOffset;
                        return;
                    }
                }
            }

            // Letztes Statement ohne abschließendes Semikolon (Defensive).
            if (trim($buffer) !== '' && $this->shouldExecuteStatement($buffer, $tableFilter)) {
                $wpdb->query($buffer);
            }
        } finally {
            fclose($handle);
        }

        $cursor->phase = ImportCursor::PHASE_META_REWRITE;
    }

    private function stepMetaRewrite(ImportCursor $cursor): void
    {
        $this->rewriteMetaKeys($cursor->sourcePrefix, $cursor->targetPrefix);
        $cursor->phase = ImportCursor::PHASE_URL_REWRITE;
        $cursor->urlRewriteTableIndex = 0;
        $cursor->urlRewriteRowOffset = 0;
    }

    private function stepUrlRewrite(ImportCursor $cursor, float $deadline): void
    {
        $pairs = $this->urlRewritePairs($cursor->manifest);
        if ($pairs === []) {
            $cursor->phase = ImportCursor::PHASE_UPLOADS;
            return;
        }

        $tables = $this->prefixedTables();
        $count = count($tables);

        while ($cursor->urlRewriteTableIndex < $count) {
            $table = $tables[$cursor->urlRewriteTableIndex];
            $rows = $this->rewriteTableChunk($table, $pairs, $cursor->urlRewriteRowOffset, self::URL_REWRITE_CHUNK);

            if ($rows < self::URL_REWRITE_CHUNK) {
                // Tabelle fertig, weiter zur nächsten.
                $cursor->urlRewriteTableIndex++;
                $cursor->urlRewriteRowOffset = 0;
            } else {
                $cursor->urlRewriteRowOffset += self::URL_REWRITE_CHUNK;
            }

            if (microtime(true) >= $deadline) {
                return;
            }
        }

        $cursor->phase = ImportCursor::PHASE_UPLOADS;
    }

    private function stepUploads(ImportCursor $cursor, bool $includeUploads, float $deadline): void
    {
        if (!$cursor->includesUploads || !$includeUploads) {
            $cursor->phase = ImportCursor::PHASE_DONE;
            return;
        }

        if (!class_exists(\ZipArchive::class)) {
            $cursor->phase = ImportCursor::PHASE_DONE;
            return;
        }

        $uploadDir = wp_upload_dir();
        $uploadBase = (string) $uploadDir['basedir'];
        if ($uploadBase === '') {
            $cursor->phase = ImportCursor::PHASE_DONE;
            return;
        }
        wp_mkdir_p($uploadBase);
        $uploadBaseReal = realpath($uploadBase);
        if ($uploadBaseReal === false) {
            $cursor->phase = ImportCursor::PHASE_DONE;
            return;
        }

        $zip = new \ZipArchive();
        if ($zip->open($cursor->zipPath) !== true) {
            $cursor->phase = ImportCursor::PHASE_DONE;
            return;
        }

        try {
            $numFiles = $zip->numFiles;
            for ($i = $cursor->uploadsFileIndex; $i < $numFiles; $i++) {
                $cursor->uploadsFileIndex = $i;

                $stat = $zip->statIndex($i);
                if ($stat === false) {
                    continue;
                }

                $name = (string) $stat['name'];
                if ($name === '' || str_ends_with($name, '/')) {
                    continue;
                }

                $normalized = str_replace('\\', '/', $name);
                if (!str_starts_with($normalized, 'uploads/') || str_contains($normalized, '..')) {
                    continue;
                }

                $relPath = substr($normalized, strlen('uploads/'));
                if ($relPath === '') {
                    continue;
                }

                $targetPath = trailingslashit($uploadBaseReal) . $relPath;
                $targetDir = dirname($targetPath);
                wp_mkdir_p($targetDir);

                $targetDirReal = realpath($targetDir);
                if ($targetDirReal === false || !str_starts_with($targetDirReal, $uploadBaseReal)) {
                    continue;
                }

                $stream = $zip->getStream($name);
                if ($stream === false) {
                    continue;
                }

                $out = fopen($targetPath, 'wb');
                if ($out === false) {
                    fclose($stream);
                    continue;
                }

                stream_copy_to_stream($stream, $out);
                fclose($stream);
                fclose($out);

                if (microtime(true) >= $deadline) {
                    $cursor->uploadsFileIndex = $i + 1;
                    return;
                }
            }
        } finally {
            $zip->close();
        }

        $cursor->phase = ImportCursor::PHASE_DONE;
    }

    // ============================================================
    // Helfer (unverändert aus der monolithischen Variante übernommen)
    // ============================================================

    private function extractZipSafely(string $zipPath, string $destination): void
    {
        if (!class_exists(\ZipArchive::class)) {
            throw new \RuntimeException('ZipArchive-Klasse nicht verfügbar.');
        }

        $zip = new \ZipArchive();
        $status = $zip->open($zipPath);
        if ($status !== true) {
            throw new \RuntimeException('ZIP konnte nicht geöffnet werden: ' . (string) $status);
        }

        $destination = trailingslashit($destination);
        $destReal = realpath($destination) ?: $destination;

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            if ($stat === false) {
                continue;
            }

            $name = (string) $stat['name'];
            if ($name === '' || str_ends_with($name, '/')) {
                continue;
            }

            $normalized = str_replace('\\', '/', $name);
            if (str_contains($normalized, '..')) {
                continue;
            }

            $baseName = basename($normalized);
            if (!in_array($baseName, self::ALLOWED_ENTRIES, true)) {
                continue;
            }

            $stream = $zip->getStream($name);
            if ($stream === false) {
                continue;
            }

            $targetPath = $destination . $baseName;
            $out = fopen($targetPath, 'wb');
            if ($out === false) {
                fclose($stream);
                continue;
            }

            stream_copy_to_stream($stream, $out);
            fclose($stream);
            fclose($out);

            $realTarget = realpath($targetPath);
            if ($realTarget === false || !str_starts_with($realTarget, $destReal)) {
                @unlink($targetPath);
                continue;
            }
        }

        $zip->close();
    }

    /**
     * @param (callable(string): bool)|null $tableFilter
     */
    private function shouldExecuteStatement(string $statement, ?callable $tableFilter): bool
    {
        if ($tableFilter === null) {
            return true;
        }

        $table = $this->extractTableFromStatement($statement);
        if ($table === null) {
            return true;
        }

        return $tableFilter($table);
    }

    private function extractTableFromStatement(string $statement): ?string
    {
        $trimmed = ltrim($statement);

        if (preg_match('/^DROP TABLE (?:IF EXISTS )?`([^`]+)`/i', $trimmed, $m)) {
            return $m[1];
        }

        if (preg_match('/^CREATE TABLE (?:IF NOT EXISTS )?`([^`]+)`/i', $trimmed, $m)) {
            return $m[1];
        }

        if (preg_match('/^INSERT INTO `([^`]+)`/i', $trimmed, $m)) {
            return $m[1];
        }

        return null;
    }

    private function rewriteMetaKeys(string $sourcePrefix, string $targetPrefix): void
    {
        global $wpdb;

        if ($sourcePrefix === '' || $sourcePrefix === $targetPrefix) {
            return;
        }

        $usermetaKeys = [
            'capabilities',
            'user_level',
            'user-settings',
            'user-settings-time',
            'dashboard_quick_press_last_post_id',
            'session_tokens',
        ];

        $usermetaTable = $targetPrefix . 'usermeta';
        foreach ($usermetaKeys as $key) {
            $wpdb->update(
                $usermetaTable,
                ['meta_key' => $targetPrefix . $key],
                ['meta_key' => $sourcePrefix . $key]
            );
        }

        $wpdb->update(
            $targetPrefix . 'options',
            ['option_name' => $targetPrefix . 'user_roles'],
            ['option_name' => $sourcePrefix . 'user_roles']
        );
    }

    /**
     * Baut die Search-Replace-Paare Quelle -> Ziel aus dem Manifest.
     *
     * Deckt bewusst mehrere Schreibweisen derselben Quell-URL ab, damit eingebrannte
     * absolute URLs im Content vollstaendig umgeschrieben werden:
     *  - beide Schemata der Quelle (http:// und https://), da z.B. DDEV sich als https
     *    meldet, Theme-Assets aber als http:// eingebrannt sein können.
     *  - die JSON-escaped Slash-Form (http:\/\/...), wie sie in Block-Markup-Attributen
     *    von Synced Patterns / wp_block steht.
     * Längste From-Strings zuerst, damit keine Teilersetzung eine andere blockiert.
     *
     * @param array<string, mixed> $manifest
     * @return array<string, string> from => to
     */
    private function urlRewritePairs(array $manifest): array
    {
        $oldSiteUrl = isset($manifest['site_url']) ? (string) $manifest['site_url'] : '';
        $oldHomeUrl = isset($manifest['home_url']) ? (string) $manifest['home_url'] : '';
        $newSiteUrl = (string) get_site_url();
        $newHomeUrl = (string) get_home_url();

        $pairs = [];
        $this->addUrlVariants($pairs, $oldSiteUrl, $newSiteUrl);
        $this->addUrlVariants($pairs, $oldHomeUrl, $newHomeUrl);

        uksort($pairs, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));

        return $pairs;
    }

    /**
     * Fügt für ein Quell/Ziel-Paar alle relevanten Schreibvarianten hinzu: beide Schemata
     * (http/https) der Quelle und je die JSON-escaped Slash-Form, jeweils auf die Ziel-URL.
     *
     * @param array<string, string> $pairs
     */
    private function addUrlVariants(array &$pairs, string $old, string $new): void
    {
        if ($old === '' || $new === '') {
            return;
        }

        $oldRest = preg_replace('#^https?://#i', '', rtrim($old, '/'));
        $newClean = rtrim($new, '/');
        if ($oldRest === null || $oldRest === '') {
            return;
        }

        foreach (['http://', 'https://'] as $scheme) {
            $from = $scheme . $oldRest;
            if ($from === $newClean) {
                continue;
            }
            $pairs[$from] = $newClean;

            $escFrom = str_replace('/', '\\/', $from);
            if ($escFrom !== $from) {
                $pairs[$escFrom] = str_replace('/', '\\/', $newClean);
            }
        }
    }

    /**
     * @return array<int, string>
     */
    private function prefixedTables(): array
    {
        global $wpdb;

        $prefix = $wpdb->prefix;
        $like = str_replace('_', '\\_', $prefix) . '%';
        /** @var array<int, string> $tables */
        $tables = (array) $wpdb->get_col(
            $wpdb->prepare('SHOW TABLES LIKE %s', $like)
        );

        return array_values(array_map('strval', $tables));
    }

    /**
     * Verarbeitet einen Chunk (LIMIT/OFFSET) des URL-Rewrites einer Tabelle.
     *
     * @param array<string, string> $pairs
     * @return int Anzahl gelesener Zeilen in diesem Chunk (< $chunkSize => Tabelle fertig).
     */
    private function rewriteTableChunk(string $table, array $pairs, int $offset, int $chunkSize): int
    {
        global $wpdb;

        $tableEsc = '`' . str_replace('`', '``', $table) . '`';

        /** @var array<int, array<string, string>> $columns */
        $columns = (array) $wpdb->get_results("SHOW COLUMNS FROM {$tableEsc}", ARRAY_A);

        $isPostsTable = ($table === $wpdb->posts);

        $textColumns = [];
        $primaryKey = null;
        foreach ($columns as $col) {
            $type = strtolower((string) ($col['Type'] ?? ''));
            $field = (string) ($col['Field'] ?? '');
            if ($field === '') {
                continue;
            }
            if (($col['Key'] ?? '') === 'PRI' && $primaryKey === null) {
                $primaryKey = $field;
            }
            // guid ist ein permanenter Identifier, kein anzuzeigender Link, nicht umschreiben.
            if ($isPostsTable && $field === 'guid') {
                continue;
            }
            if (str_contains($type, 'char') || str_contains($type, 'text') || str_contains($type, 'blob')) {
                $textColumns[] = $field;
            }
        }

        if ($textColumns === [] || $primaryKey === null) {
            return 0;
        }

        $selectCols = array_merge([$primaryKey], $textColumns);
        $selectList = implode(', ', array_map(
            static fn (string $c): string => '`' . str_replace('`', '``', $c) . '`',
            $selectCols
        ));

        /** @var array<int, array<string, mixed>> $rows */
        $rows = (array) $wpdb->get_results(
            $wpdb->prepare("SELECT {$selectList} FROM {$tableEsc} LIMIT %d OFFSET %d", $chunkSize, $offset),
            ARRAY_A
        );

        foreach ($rows as $row) {
            $updates = [];
            foreach ($textColumns as $col) {
                $original = $row[$col] ?? null;
                if (!is_string($original) || $original === '') {
                    continue;
                }
                $replaced = $original;
                foreach ($pairs as $from => $to) {
                    $replaced = $this->searchReplace->recursiveReplace($replaced, $from, $to);
                }
                if ($replaced !== $original) {
                    $updates[$col] = $replaced;
                }
            }

            if ($updates !== []) {
                $wpdb->update($table, $updates, [$primaryKey => $row[$primaryKey]]);
            }
        }

        return count($rows);
    }

    private function cleanupDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = glob(trailingslashit($dir) . '*') ?: [];
        foreach ($items as $item) {
            if (is_dir($item)) {
                $this->cleanupDir($item);
            } elseif (is_file($item)) {
                @unlink($item);
            }
        }
        @rmdir($dir);
    }
}
