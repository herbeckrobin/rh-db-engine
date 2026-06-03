<?php

declare(strict_types=1);

namespace RhDbEngine;


final class Importer
{
    /** @var array<int, string> */
    private const ALLOWED_ENTRIES = ['db.sql', 'manifest.json'];

    public function __construct(
        private readonly Storage $storage,
        private readonly SearchReplace $searchReplace
    ) {
    }

    /**
     * Importiert ein Backup aus einer ZIP-Datei im `rh-blueprint-data/backups/` Ordner.
     *
     * Wenn `$tableFilter` gesetzt ist, werden nur Tabellen-Statements eingespielt für die
     * das Predicate `true` liefert, alle anderen werden übersprungen. Statements ohne
     * Tabellenbezug (SET NAMES, START TRANSACTION) laufen immer.
     * Wenn `$tableFilter === null`, wird die komplette SQL importiert (Vollimport, z.B. für DB-Tools).
     *
     * @param (callable(string): bool)|null $tableFilter fn(vollqualifizierter Tabellenname): bool
     * @return array<string, mixed> Manifest-Daten aus dem Backup
     * @throws \RuntimeException
     */
    public function importFromFile(string $zipPath, ?callable $tableFilter = null, bool $includeUploads = true): array
    {
        global $wpdb;

        @set_time_limit(0);
        if (function_exists('wp_raise_memory_limit')) {
            wp_raise_memory_limit('admin');
        }

        if (!is_readable($zipPath)) {
            throw new \RuntimeException('Backup-Datei nicht lesbar: ' . $zipPath);
        }

        $this->storage->ensureReady();

        $extractDir = $this->storage->reserveTempFile('import') . '.d';
        wp_mkdir_p($extractDir);

        $this->extractZipSafely($zipPath, $extractDir);

        $sqlFile = trailingslashit($extractDir) . 'db.sql';
        $manifestFile = trailingslashit($extractDir) . 'manifest.json';

        if (!is_readable($sqlFile) || !is_readable($manifestFile)) {
            $this->cleanupDir($extractDir);
            throw new \RuntimeException('Backup enthält weder db.sql noch manifest.json.');
        }

        /** @var array<string, mixed> $manifest */
        $manifest = (array) json_decode((string) file_get_contents($manifestFile), true);

        $sourcePrefix = isset($manifest['db_prefix']) ? (string) $manifest['db_prefix'] : '';
        if ($sourcePrefix === '') {
            $this->cleanupDir($extractDir);
            throw new \RuntimeException('Backup-Manifest enthält keinen db_prefix. Aelteres Backup ohne Prefix-Info kann nicht sicher importiert werden.');
        }

        $targetPrefix = (string) $wpdb->prefix;

        try {
            $this->importSqlFile($sqlFile, $sourcePrefix, $targetPrefix, $tableFilter);
            $this->rewriteMetaKeys($sourcePrefix, $targetPrefix);
            $this->rewriteUrls($manifest);

            // Wenn das Backup uploads/ enthält (laut Manifest), extrahiere sie ins
            // wp-content/uploads/ Verzeichnis, sofern der Aufrufer das nicht abwählt.
            $extractUploads = !empty($manifest['includes_uploads']) && $includeUploads;
            if ($extractUploads) {
                $this->extractUploadsFromZip($zipPath);
            }
        } finally {
            $this->cleanupDir($extractDir);
        }

        if ($sourcePrefix !== $targetPrefix) {
            error_log(sprintf('[RHBP] DB-Import: Prefix umgeschrieben %s -> %s', $sourcePrefix, $targetPrefix));
        }

        return $manifest;
    }

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

            // Zip-Slip: normalisieren und Path-Traversal verhindern
            $normalized = str_replace('\\', '/', $name);
            if (str_contains($normalized, '..')) {
                continue;
            }

            $baseName = basename($normalized);
            if (!in_array($baseName, self::ALLOWED_ENTRIES, true)) {
                // Uploads werden (falls vorhanden) separat in einer zukuenftigen Phase behandelt.
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
    private function importSqlFile(string $sqlFile, string $sourcePrefix, string $targetPrefix, ?callable $tableFilter = null): void
    {
        global $wpdb;

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

        $handle = fopen($sqlFile, 'rb');
        if ($handle === false) {
            throw new \RuntimeException('SQL-Datei nicht lesbar.');
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
            }
        }

        fclose($handle);

        if (trim($buffer) !== '') {
            if ($this->shouldExecuteStatement($buffer, $tableFilter)) {
                $wpdb->query($buffer);
            }
        }
    }

    /**
     * Entscheidet ob ein komplettes SQL-Statement ausgefuehrt werden soll.
     *
     * Wenn kein Filter gesetzt ist: immer ausfuehren (Vollimport).
     * Wenn Filter gesetzt: Tabellennamen aus DROP/CREATE/INSERT extrahieren
     * und gegen das Predicate prüfen. Statements ohne Tabellen-Match
     * (z.B. SET NAMES, START TRANSACTION) werden immer ausgefuehrt.
     *
     * @param (callable(string): bool)|null $tableFilter
     */
    private function shouldExecuteStatement(string $statement, ?callable $tableFilter): bool
    {
        if ($tableFilter === null) {
            return true;
        }

        $table = $this->extractTableFromStatement($statement);
        if ($table === null) {
            // Kein Tabellen-Statement (z.B. SET NAMES utf8mb4), immer ausfuehren
            return true;
        }

        return $tableFilter($table);
    }

    /**
     * Extrahiert den Tabellennamen aus einem DROP/CREATE/INSERT-Statement.
     * Gibt `null` zurück wenn das Statement keine Tabelle referenziert.
     */
    private function extractTableFromStatement(string $statement): ?string
    {
        $trimmed = ltrim($statement);

        // DROP TABLE IF EXISTS `tablename`
        if (preg_match('/^DROP TABLE (?:IF EXISTS )?`([^`]+)`/i', $trimmed, $m)) {
            return $m[1];
        }

        // CREATE TABLE `tablename`
        if (preg_match('/^CREATE TABLE (?:IF NOT EXISTS )?`([^`]+)`/i', $trimmed, $m)) {
            return $m[1];
        }

        // INSERT INTO `tablename`
        if (preg_match('/^INSERT INTO `([^`]+)`/i', $trimmed, $m)) {
            return $m[1];
        }

        // LOCK TABLES, UNLOCK TABLES, ALTER TABLE etc., wir lassen sie durch
        return null;
    }

    /**
     * Schreibt User-Meta- und Options-Keys um die WordPress mit dem Tabellen-Prefix präfixt.
     * Diese liegen als Daten in den Tabellen, werden also vom SQL-Rewrite nicht erfasst.
     */
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
     * @param array<string, mixed> $manifest
     */
    private function rewriteUrls(array $manifest): void
    {
        global $wpdb;

        $oldSiteUrl = isset($manifest['site_url']) ? (string) $manifest['site_url'] : '';
        $oldHomeUrl = isset($manifest['home_url']) ? (string) $manifest['home_url'] : '';
        $newSiteUrl = (string) get_site_url();
        $newHomeUrl = (string) get_home_url();

        $pairs = [];
        if ($oldSiteUrl !== '' && $oldSiteUrl !== $newSiteUrl) {
            $pairs[$oldSiteUrl] = $newSiteUrl;
        }
        if ($oldHomeUrl !== '' && $oldHomeUrl !== $newHomeUrl) {
            $pairs[$oldHomeUrl] = $newHomeUrl;
        }

        if ($pairs === []) {
            return;
        }

        $prefix = $wpdb->prefix;
        $like = str_replace('_', '\\_', $prefix) . '%';
        /** @var array<int, string> $tables */
        $tables = (array) $wpdb->get_col(
            $wpdb->prepare('SHOW TABLES LIKE %s', $like)
        );

        foreach ($tables as $table) {
            $this->rewriteTable((string) $table, $pairs);
        }
    }

    /**
     * @param array<string, string> $pairs
     */
    private function rewriteTable(string $table, array $pairs): void
    {
        global $wpdb;

        $tableEsc = '`' . str_replace('`', '``', $table) . '`';

        /** @var array<int, array<string, string>> $columns */
        $columns = (array) $wpdb->get_results("SHOW COLUMNS FROM {$tableEsc}", ARRAY_A);

        $textColumns = [];
        $primaryKey = null;
        foreach ($columns as $col) {
            $type = strtolower((string) ($col['Type'] ?? ''));
            $field = (string) ($col['Field'] ?? '');
            if ($field === '') {
                continue;
            }
            if (str_contains($type, 'char') || str_contains($type, 'text') || str_contains($type, 'blob')) {
                $textColumns[] = $field;
            }
            if (($col['Key'] ?? '') === 'PRI' && $primaryKey === null) {
                $primaryKey = $field;
            }
        }

        if ($textColumns === [] || $primaryKey === null) {
            return;
        }

        $selectCols = array_merge([$primaryKey], $textColumns);
        $selectList = implode(', ', array_map(
            static fn (string $c): string => '`' . str_replace('`', '``', $c) . '`',
            $selectCols
        ));

        $offset = 0;
        $chunk = 200;
        while (true) {
            /** @var array<int, array<string, mixed>> $rows */
            $rows = (array) $wpdb->get_results(
                $wpdb->prepare("SELECT {$selectList} FROM {$tableEsc} LIMIT %d OFFSET %d", $chunk, $offset),
                ARRAY_A
            );
            if ($rows === []) {
                break;
            }

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

            $offset += $chunk;
            if (count($rows) < $chunk) {
                break;
            }
        }
    }

    private function cleanupDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $files = glob(trailingslashit($dir) . '*') ?: [];
        foreach ($files as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
        @rmdir($dir);
    }

    /**
     * Extrahiert das uploads/ Verzeichnis aus dem Backup-ZIP nach wp-content/uploads/.
     * Existierende Files werden überschrieben. Path-Traversal ist via realpath-Prefix-Check
     * verhindert (Zip-Slip-Schutz).
     */
    private function extractUploadsFromZip(string $zipPath): void
    {
        if (!class_exists(\ZipArchive::class)) {
            return;
        }

        $uploadDir = wp_upload_dir();
        $uploadBase = (string) $uploadDir['basedir'];
        if ($uploadBase === '') {
            return;
        }
        wp_mkdir_p($uploadBase);
        $uploadBaseReal = realpath($uploadBase);
        if ($uploadBaseReal === false) {
            return;
        }

        $zip = new \ZipArchive();
        if ($zip->open($zipPath) !== true) {
            return;
        }

        $extracted = 0;
        $skipped = 0;

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            if ($stat === false) {
                continue;
            }

            $name = (string) $stat['name'];
            if ($name === '' || str_ends_with($name, '/')) {
                continue;
            }

            // Nur Eintraege unter uploads/ verarbeiten
            $normalized = str_replace('\\', '/', $name);
            if (!str_starts_with($normalized, 'uploads/')) {
                continue;
            }

            // Zip-Slip
            if (str_contains($normalized, '..')) {
                $skipped++;
                continue;
            }

            $relPath = substr($normalized, strlen('uploads/'));
            if ($relPath === '') {
                continue;
            }

            $targetPath = trailingslashit($uploadBaseReal) . $relPath;
            $targetDir = dirname($targetPath);
            wp_mkdir_p($targetDir);

            // Prüft dass das Ziel WIRKLICH unter uploadBase liegt (gegen Symlink-Tricks)
            $targetDirReal = realpath($targetDir);
            if ($targetDirReal === false || !str_starts_with($targetDirReal, $uploadBaseReal)) {
                $skipped++;
                continue;
            }

            $stream = $zip->getStream($name);
            if ($stream === false) {
                $skipped++;
                continue;
            }

            $out = fopen($targetPath, 'wb');
            if ($out === false) {
                fclose($stream);
                $skipped++;
                continue;
            }

            stream_copy_to_stream($stream, $out);
            fclose($stream);
            fclose($out);
            $extracted++;
        }

        $zip->close();

        error_log(sprintf('[RHBP] Uploads extrahiert: %d Files, %d übersprungen', $extracted, $skipped));
    }
}
