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
     * Erstellt ein ZIP mit db.sql + manifest.json (+ optional uploads/).
     *
     * @param array<int, string> $excludedTables Vollstaendige Tabellen-Namen (mit Prefix), die nicht im Dump landen
     * @return string Absoluter Pfad zur ZIP-Datei.
     * @throws \RuntimeException bei Fehlern
     */
    public function createBackup(bool $includeUploads = false, array $excludedTables = []): string
    {
        @set_time_limit(0);
        if (function_exists('wp_raise_memory_limit')) {
            wp_raise_memory_limit('admin');
        }

        $this->storage->ensureReady();

        $sqlFile = $this->storage->reserveTempFile('db');
        $this->writeSqlDump($sqlFile, $excludedTables);

        $manifest = $this->buildManifest($sqlFile, $includeUploads);
        $manifestFile = $this->storage->reserveTempFile('manifest');
        file_put_contents($manifestFile, (string) wp_json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        $zipName = sprintf('backup-%s.zip', gmdate('Ymd-His'));
        $zipPath = trailingslashit($this->storage->backupsPath()) . $zipName;

        $this->buildZip($zipPath, $sqlFile, $manifestFile, $includeUploads);

        @unlink($sqlFile);
        @unlink($manifestFile);

        return $zipPath;
    }

    /**
     * @param array<int, string> $excludedTables
     */
    private function writeSqlDump(string $targetFile, array $excludedTables = []): void
    {
        global $wpdb;

        $handle = fopen($targetFile, 'wb');
        if ($handle === false) {
            throw new \RuntimeException('Konnte SQL-Dump-Datei nicht öffnen.');
        }

        $excludedMap = array_flip(array_map('strval', $excludedTables));

        $header = sprintf(
            "-- RH Blueprint DB Export\n-- Date: %s\n-- Site: %s\n-- Prefix: %s\n-- Excluded tables: %s\n\nSET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n\n",
            gmdate('c'),
            (string) get_site_url(),
            $wpdb->prefix,
            $excludedTables === [] ? '(none)' : implode(', ', $excludedTables)
        );
        fwrite($handle, $header);

        $prefix = $wpdb->prefix;
        $like = str_replace('_', '\\_', $prefix) . '%';
        /** @var array<int, string> $tables */
        $tables = (array) $wpdb->get_col(
            $wpdb->prepare('SHOW TABLES LIKE %s', $like)
        );

        foreach ($tables as $table) {
            $name = (string) $table;
            if (isset($excludedMap[$name])) {
                fwrite($handle, sprintf("-- Skipped (excluded): %s\n\n", $name));
                continue;
            }
            $this->dumpTable($handle, $name);
        }

        fwrite($handle, "\nSET FOREIGN_KEY_CHECKS=1;\n");
        fclose($handle);
    }

    /**
     * @param resource $handle
     */
    private function dumpTable($handle, string $table): void
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

        $offset = 0;
        while (true) {
            /** @var array<int, array<string, mixed>> $rows */
            $rows = (array) $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT * FROM {$tableEsc} LIMIT %d OFFSET %d",
                    self::CHUNK_SIZE,
                    $offset
                ),
                ARRAY_A
            );

            if ($rows === []) {
                break;
            }

            foreach ($rows as $row) {
                fwrite($handle, $this->buildInsert($table, $row) . "\n");
            }

            $offset += self::CHUNK_SIZE;
            if (count($rows) < self::CHUNK_SIZE) {
                break;
            }
        }

        fwrite($handle, "\n");
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
                // _real_escape() wickelt intern alle %-Zeichen in einen wpdb-Placeholder-Marker
                // ({HASH}%{HASH}) damit wpdb::prepare() sie nicht als Format-Specifier behandelt.
                // Für SQL-Dumps die NICHT durch prepare() gehen (sondern direkt per query() repliziert
                // werden) müssen die Marker wieder entfernt werden, sonst landen sie persistent in der
                // Ziel-DB. Beispiel: permalink_structure '/%postname%/' würde sonst zu '/{HASH}postname{HASH}/'.
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
            'db_size' => filesize($sqlFile) ?: 0,
            'includes_uploads' => $includeUploads,
            'created_at' => gmdate('c'),
        ];
    }

    private function buildZip(string $zipPath, string $sqlFile, string $manifestFile, bool $includeUploads): void
    {
        if (!class_exists(\ZipArchive::class)) {
            throw new \RuntimeException('ZipArchive-Klasse nicht verfügbar. Bitte ZIP-PHP-Extension aktivieren.');
        }

        $zip = new \ZipArchive();
        $status = $zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        if ($status !== true) {
            throw new \RuntimeException('Konnte ZIP nicht erstellen: ' . (string) $status);
        }

        $zip->addFile($sqlFile, 'db.sql');
        $zip->addFile($manifestFile, 'manifest.json');

        if ($includeUploads) {
            $uploads = wp_upload_dir();
            $uploadBase = (string) $uploads['basedir'];
            if ($uploadBase !== '' && is_dir($uploadBase)) {
                $this->addDirectoryToZip($zip, $uploadBase, 'uploads');
            }
        }

        $zip->close();
    }

    private function addDirectoryToZip(\ZipArchive $zip, string $dirPath, string $zipPrefix): void
    {
        $dirPath = rtrim($dirPath, DIRECTORY_SEPARATOR);
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dirPath, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if (!$file instanceof \SplFileInfo || !$file->isFile()) {
                continue;
            }

            $real = $file->getRealPath();
            if ($real === false) {
                continue;
            }

            $rel = ltrim(str_replace($dirPath, '', $real), DIRECTORY_SEPARATOR);
            $zip->addFile($real, trailingslashit($zipPrefix) . $rel);
        }
    }
}
