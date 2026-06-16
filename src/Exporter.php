<?php

declare(strict_types=1);

namespace RhDbEngine;


final class Exporter
{
    public const CHUNK_SIZE = 500;

    public function __construct(private readonly Storage $storage)
    {
    }

    /**
     * Erstellt ein ZIP mit db.sql + manifest.json (+ optional uploads/) in einem Rutsch.
     *
     * Dünner Wrapper um die resume-fähige {@see exportStep()}-State-Machine mit unbegrenztem
     * Zeitbudget. Das ZIP landet wie bisher im backups/-Ordner mit nicht erratbarem Namen.
     *
     * @param array<int, string> $excludedTables Vollqualifizierte Tabellennamen, die nicht gedumpt werden.
     * @return string Absoluter Pfad zur ZIP-Datei.
     * @throws \RuntimeException
     */
    public function createBackup(bool $includeUploads = false, array $excludedTables = []): string
    {
        $workdir = $this->storage->jobWorkdir('export-' . wp_generate_password(8, false, false));
        $cursor = ExportCursor::start($workdir, $includeUploads, $excludedTables);

        try {
            do {
                $cursor = $this->exportStep($cursor, PHP_INT_MAX);
            } while (!$cursor->isDone());

            if ($cursor->zipPath === null || !is_file($cursor->zipPath)) {
                throw new \RuntimeException('Export fehlgeschlagen: keine ZIP-Datei erzeugt.');
            }

            return $cursor->zipPath;
        } finally {
            // Temporäre SQL/Manifest/Listendateien aufräumen, das ZIP in backups/ bleibt.
            $this->cleanupDir($workdir);
        }
    }

    /**
     * Verarbeitet einen Export-Häppchen bis das Zeitbudget erschöpft ist.
     *
     * Phasen: sql -> manifest -> zip_db -> zip_uploads -> done.
     * Der SQL-Dump ist tabellen-/zeilenweise resume-fähig, das uploads-ZIP datei-weise.
     *
     * @param float $budgetSeconds Zeitbudget (Sub-Sekunden erlaubt, jeder Tick macht Fortschritt).
     * @throws \RuntimeException
     */
    public function exportStep(ExportCursor $cursor, float $budgetSeconds): ExportCursor
    {
        @set_time_limit(0);
        if (function_exists('wp_raise_memory_limit')) {
            wp_raise_memory_limit('admin');
        }

        $this->storage->ensureReady();
        $deadline = microtime(true) + max(0.1, $budgetSeconds);

        while (!$cursor->isDone() && microtime(true) < $deadline) {
            switch ($cursor->phase) {
                case ExportCursor::PHASE_SQL:
                    $this->stepSql($cursor, $deadline);
                    break;
                case ExportCursor::PHASE_MANIFEST:
                    $this->stepManifest($cursor);
                    break;
                case ExportCursor::PHASE_ZIP_DB:
                    $this->stepZipDb($cursor);
                    break;
                case ExportCursor::PHASE_ZIP_UPLOADS:
                    $this->stepZipUploads($cursor, $deadline);
                    break;
                default:
                    $cursor->phase = ExportCursor::PHASE_DONE;
            }
        }

        return $cursor;
    }

    // ============================================================
    // Phasen
    // ============================================================

    private function stepSql(ExportCursor $cursor, float $deadline): void
    {
        if ($cursor->sqlPath === null) {
            $cursor->sqlPath = trailingslashit($cursor->workdir) . 'db.sql';
        }

        $handle = fopen($cursor->sqlPath, $cursor->headerWritten ? 'ab' : 'wb');
        if ($handle === false) {
            throw new \RuntimeException('Konnte SQL-Dump-Datei nicht öffnen.');
        }

        try {
            if (!$cursor->headerWritten) {
                fwrite($handle, $this->sqlHeader($cursor->excludedTables));
                $cursor->headerWritten = true;
            }

            $tables = $this->prefixedTables();
            $count = count($tables);
            $excludedMap = array_flip(array_map('strval', $cursor->excludedTables));

            while ($cursor->tableIndex < $count) {
                $table = $tables[$cursor->tableIndex];

                if (isset($excludedMap[$table])) {
                    fwrite($handle, sprintf("-- Skipped (excluded): %s\n\n", $table));
                    $cursor->tableIndex++;
                    $cursor->rowOffset = 0;
                    continue;
                }

                if ($cursor->rowOffset === 0) {
                    $this->writeTableHeader($handle, $table);
                }

                $rows = $this->dumpTableRowsChunk($handle, $table, $cursor->rowOffset, self::CHUNK_SIZE);

                if ($rows < self::CHUNK_SIZE) {
                    fwrite($handle, "\n");
                    $cursor->tableIndex++;
                    $cursor->rowOffset = 0;
                } else {
                    $cursor->rowOffset += self::CHUNK_SIZE;
                }

                if (microtime(true) >= $deadline) {
                    return;
                }
            }

            fwrite($handle, "\nSET FOREIGN_KEY_CHECKS=1;\n");
        } finally {
            fclose($handle);
        }

        $cursor->phase = ExportCursor::PHASE_MANIFEST;
    }

    private function stepManifest(ExportCursor $cursor): void
    {
        $cursor->manifestPath = trailingslashit($cursor->workdir) . 'manifest.json';
        $manifest = $this->buildManifest((string) $cursor->sqlPath, $cursor->includeUploads);
        file_put_contents($cursor->manifestPath, (string) wp_json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        $cursor->phase = ExportCursor::PHASE_ZIP_DB;
    }

    private function stepZipDb(ExportCursor $cursor): void
    {
        if (!class_exists(\ZipArchive::class)) {
            throw new \RuntimeException('ZipArchive-Klasse nicht verfügbar. Bitte ZIP-PHP-Extension aktivieren.');
        }

        if ($cursor->zipPath === null) {
            $zipName = sprintf('backup-%s-%s.zip', gmdate('Ymd-His'), wp_generate_password(20, false, false));
            $cursor->zipPath = trailingslashit($this->storage->backupsPath()) . $zipName;
        }

        $zip = new \ZipArchive();
        $status = $zip->open($cursor->zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        if ($status !== true) {
            throw new \RuntimeException('Konnte ZIP nicht erstellen: ' . (string) $status);
        }

        $zip->addFile((string) $cursor->sqlPath, 'db.sql');
        $zip->addFile((string) $cursor->manifestPath, 'manifest.json');
        $zip->close();

        $cursor->phase = ExportCursor::PHASE_ZIP_UPLOADS;
    }

    private function stepZipUploads(ExportCursor $cursor, float $deadline): void
    {
        if (!$cursor->includeUploads) {
            $cursor->phase = ExportCursor::PHASE_DONE;
            return;
        }

        $uploads = wp_upload_dir();
        $uploadBase = rtrim((string) $uploads['basedir'], DIRECTORY_SEPARATOR);
        if ($uploadBase === '' || !is_dir($uploadBase)) {
            $cursor->phase = ExportCursor::PHASE_DONE;
            return;
        }

        $listFile = trailingslashit($cursor->workdir) . 'uploads-list.txt';
        if (!is_file($listFile)) {
            $this->materializeUploadList($uploadBase, $listFile);
        }

        $files = file($listFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        $total = count($files);
        if ($total === 0) {
            $cursor->phase = ExportCursor::PHASE_DONE;
            return;
        }

        $zip = new \ZipArchive();
        if ($zip->open($cursor->zipPath ?? '') !== true) {
            throw new \RuntimeException('Konnte ZIP für Uploads nicht öffnen.');
        }

        try {
            for ($i = $cursor->uploadsFileIndex; $i < $total; $i++) {
                $cursor->uploadsFileIndex = $i;

                $real = $files[$i];
                if (!is_file($real)) {
                    continue;
                }

                $rel = ltrim(str_replace($uploadBase, '', $real), DIRECTORY_SEPARATOR);
                $zip->addFile($real, 'uploads/' . $rel);

                if (microtime(true) >= $deadline) {
                    $cursor->uploadsFileIndex = $i + 1;
                    return;
                }
            }

            $cursor->uploadsFileIndex = $total;
        } finally {
            // close() schreibt die in diesem Tick hinzugefügten Dateien tatsächlich ins ZIP.
            $zip->close();
        }

        $cursor->phase = ExportCursor::PHASE_DONE;
    }

    // ============================================================
    // Helfer
    // ============================================================

    /**
     * @param array<int, string> $excludedTables
     */
    private function sqlHeader(array $excludedTables): string
    {
        global $wpdb;

        return sprintf(
            "-- RH Blueprint DB Export\n-- Date: %s\n-- Site: %s\n-- Prefix: %s\n-- Excluded tables: %s\n\nSET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n\n",
            gmdate('c'),
            (string) get_site_url(),
            $wpdb->prefix,
            $excludedTables === [] ? '(none)' : implode(', ', $excludedTables)
        );
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
     * @param resource $handle
     */
    private function writeTableHeader($handle, string $table): void
    {
        global $wpdb;

        $tableEsc = $this->quoteIdentifier($table);

        fwrite($handle, sprintf("\n-- Table: %s\n", $table));
        fwrite($handle, sprintf("DROP TABLE IF EXISTS %s;\n", $tableEsc));

        /** @var array<int, mixed>|null $create */
        $create = $wpdb->get_row("SHOW CREATE TABLE {$tableEsc}", ARRAY_N);
        if (is_array($create) && isset($create[1]) && is_string($create[1])) {
            fwrite($handle, $create[1] . ";\n\n");
        }
    }

    /**
     * @param resource $handle
     * @return int Anzahl gelesener Zeilen in diesem Chunk (< CHUNK_SIZE => Tabelle fertig).
     */
    private function dumpTableRowsChunk($handle, string $table, int $offset, int $chunkSize): int
    {
        global $wpdb;

        $tableEsc = $this->quoteIdentifier($table);

        /** @var array<int, array<string, mixed>> $rows */
        $rows = (array) $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$tableEsc} LIMIT %d OFFSET %d",
                $chunkSize,
                $offset
            ),
            ARRAY_A
        );

        foreach ($rows as $row) {
            fwrite($handle, $this->buildInsert($table, $row) . "\n");
        }

        return count($rows);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function buildInsert(string $table, array $row): string
    {
        global $wpdb;

        $columns = array_map([$this, 'quoteIdentifier'], array_keys($row));
        $values = [];
        foreach ($row as $value) {
            if ($value === null) {
                $values[] = 'NULL';
            } else {
                $escaped = $wpdb->remove_placeholder_escape($wpdb->_real_escape((string) $value));
                $values[] = "'" . $escaped . "'";
            }
        }

        return sprintf(
            'INSERT INTO %s (%s) VALUES (%s);',
            $this->quoteIdentifier($table),
            implode(', ', $columns),
            implode(', ', $values)
        );
    }

    private function quoteIdentifier(string $name): string
    {
        return '`' . str_replace('`', '``', $name) . '`';
    }

    /**
     * @return array<string, mixed>
     */
    private function buildManifest(string $sqlFile, bool $includeUploads): array
    {
        global $wpdb;

        return [
            'plugin_version' => (string) apply_filters(
                'rh-db-engine/manifest_creator_version',
                defined('RHDBENGINE_VERSION') ? RHDBENGINE_VERSION : '0.0.0'
            ),
            'wp_version' => get_bloginfo('version'),
            'site_url' => get_site_url(),
            'home_url' => get_home_url(),
            'db_prefix' => $wpdb->prefix,
            'db_size' => is_file($sqlFile) ? (filesize($sqlFile) ?: 0) : 0,
            'includes_uploads' => $includeUploads,
            'created_at' => gmdate('c'),
        ];
    }

    private function materializeUploadList(string $uploadBase, string $listFile): void
    {
        $out = fopen($listFile, 'wb');
        if ($out === false) {
            throw new \RuntimeException('Konnte Uploads-Liste nicht schreiben.');
        }

        try {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($uploadBase, \FilesystemIterator::SKIP_DOTS)
            );

            foreach ($iterator as $file) {
                if (!$file instanceof \SplFileInfo || !$file->isFile()) {
                    continue;
                }
                $real = $file->getRealPath();
                if ($real === false) {
                    continue;
                }
                fwrite($out, $real . "\n");
            }
        } finally {
            fclose($out);
        }
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
                // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Cleanup einer temporären Export-Datei, ein Fehlschlag ist unkritisch.
                @unlink($item);
            }
        }
        // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Cleanup eines temporären Export-Verzeichnisses, ein Fehlschlag ist unkritisch.
        @rmdir($dir);
    }
}
