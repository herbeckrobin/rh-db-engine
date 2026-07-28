<?php

declare(strict_types=1);

namespace RhDbEngine;


final class Exporter
{
    public const CHUNK_SIZE = 500;

    /**
     * Primärschlüssel-Spalten je Tabelle, gilt nur für diesen Request.
     *
     * @var array<string, array<int, string>>
     */
    private array $primaryKeyCache = [];

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
     * @param string|null $targetDir Zielordner für das ZIP. Null = der normale backups/-Ordner.
     * @return string Absoluter Pfad zur ZIP-Datei.
     * @throws \RuntimeException
     */
    public function createBackup(bool $includeUploads = false, array $excludedTables = [], ?string $targetDir = null): string
    {
        $workdir = $this->storage->jobWorkdir('export-' . wp_generate_password(8, false, false));
        $cursor = ExportCursor::start($workdir, $includeUploads, $excludedTables, $targetDir);

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
        // set_time_limit steckt auf vielen Shared-Hostern in disable_functions. Dann ist die
        // Funktion undefiniert, und das @ fängt den resultierenden Error NICHT ab (nur Warnungen).
        // Ohne diesen Guard stirbt jeder Export in der ersten Zeile.
        if (function_exists('set_time_limit')) {
            set_time_limit(0);
        }
        if (function_exists('wp_raise_memory_limit')) {
            wp_raise_memory_limit('admin');
        }

        $this->storage->ensureReady();
        $deadline = microtime(true) + max(0.1, $budgetSeconds);

        // Siehe Storage::touchJobWorkdir(): hält den laufenden Job für die GC sichtbar.
        $this->storage->touchJobWorkdir($cursor->workdir);

        while (!$cursor->isDone() && microtime(true) < $deadline) {
            // Ausdrückliche Haltestelle: wer nur den Dump und das Manifest braucht und das
            // Archiv selbst erzeugt, hält hier an. Über das Zeitbudget wäre das nicht
            // verlässlich zu treffen, weil ein Aufruf mehrere Phasen abarbeiten kann.
            if ($cursor->stopBefore !== null && $cursor->phase === $cursor->stopBefore) {
                return $cursor;
            }

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

        if ($cursor->isDone()) {
            $this->verifyArchive($cursor);
        }

        return $cursor;
    }

    /**
     * Prüft am Ende des Exports, ob das erzeugte Archiv wirklich brauchbar ist.
     *
     * Die frühere Erfolgsbedingung war "die Datei existiert". Bei voller Platte oder
     * fehlgeschlagenem close() existiert aber auch ein leeres oder abgeschnittenes ZIP,
     * und der Export meldete Erfolg. Das fällt sonst erst im Ernstfall auf, wenn das
     * Backup gebraucht wird.
     *
     * @throws \RuntimeException
     */
    private function verifyArchive(ExportCursor $cursor): void
    {
        $zipPath = $cursor->zipPath;

        if ($zipPath === null || !is_file($zipPath) || filesize($zipPath) === 0) {
            throw new \RuntimeException('Export fehlgeschlagen: keine oder leere ZIP-Datei erzeugt.');
        }

        $zip = new \ZipArchive();
        if ($zip->open($zipPath, \ZipArchive::CHECKCONS) !== true) {
            throw new \RuntimeException('Export fehlgeschlagen: erzeugtes Backup-ZIP ist beschädigt.');
        }

        $missing = [];
        foreach (['db.sql', 'manifest.json'] as $required) {
            if ($zip->locateName($required) === false) {
                $missing[] = $required;
            }
        }
        $zip->close();

        if ($missing !== []) {
            throw new \RuntimeException('Export fehlgeschlagen: im Backup fehlt ' . implode(' und ', $missing) . '.');
        }

        // Das fertige Backup enthält die komplette Datenbank, es geht niemanden ausser
        // den Eigentümer etwas an.
        $this->storage->protectFile($zipPath);
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

        // Der Zwischen-Dump liegt im Klartext unter jobs/ und enthält alles, auch Hashes.
        $this->storage->protectFile($cursor->sqlPath);

        try {
            if (!$cursor->headerWritten) {
                $this->write($handle, $this->sqlHeader($cursor->excludedTables));
                $cursor->headerWritten = true;
            }

            $tables = $this->prefixedTables();
            $count = count($tables);
            $excludedMap = array_flip(array_map('strval', $cursor->excludedTables));

            while ($cursor->tableIndex < $count) {
                $table = $tables[$cursor->tableIndex];

                if (isset($excludedMap[$table])) {
                    $this->write($handle, sprintf("-- Skipped (excluded): %s\n\n", $table));
                    $cursor->tableIndex++;
                    $cursor->rowOffset = 0;
                    $cursor->rowKey = null;
                    continue;
                }

                if ($cursor->rowOffset === 0) {
                    $this->writeTableHeader($handle, $table);
                }

                $rows = $this->dumpTableRowsChunk($handle, $table, $cursor, self::CHUNK_SIZE);

                if ($rows < self::CHUNK_SIZE) {
                    $this->write($handle, "\n");
                    $cursor->tableIndex++;
                    $cursor->rowOffset = 0;
                    $cursor->rowKey = null;
                } else {
                    $cursor->rowOffset += self::CHUNK_SIZE;
                }

                if (microtime(true) >= $deadline) {
                    return;
                }
            }

            $this->write($handle, "\nSET FOREIGN_KEY_CHECKS=1;\n");
        } finally {
            fclose($handle);
        }

        $cursor->phase = ExportCursor::PHASE_MANIFEST;
    }

    private function stepManifest(ExportCursor $cursor): void
    {
        $cursor->manifestPath = trailingslashit($cursor->workdir) . 'manifest.json';
        $manifest = $this->buildManifest((string) $cursor->sqlPath, $cursor->includeUploads);
        $json = (string) wp_json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        if (file_put_contents($cursor->manifestPath, $json) !== strlen($json)) {
            throw new \RuntimeException('Manifest konnte nicht geschrieben werden (Plattenplatz oder Schreibrechte prüfen).');
        }

        $cursor->phase = ExportCursor::PHASE_ZIP_DB;
    }

    private function stepZipDb(ExportCursor $cursor): void
    {
        if (!class_exists(\ZipArchive::class)) {
            throw new \RuntimeException('ZipArchive-Klasse nicht verfügbar. Bitte ZIP-PHP-Extension aktivieren.');
        }

        if ($cursor->zipPath === null) {
            $zipName = sprintf('backup-%s-%s.zip', gmdate('Ymd-His'), wp_generate_password(20, false, false));
            $targetDir = $cursor->targetDir ?? $this->storage->backupsPath();
            if (!is_dir($targetDir)) {
                wp_mkdir_p($targetDir);
            }
            $cursor->zipPath = trailingslashit($targetDir) . $zipName;
        }

        $zip = new \ZipArchive();
        $status = $zip->open($cursor->zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        if ($status !== true) {
            throw new \RuntimeException('Konnte ZIP nicht erstellen: ' . (string) $status);
        }

        if (!$zip->addFile((string) $cursor->sqlPath, 'db.sql')
            || !$zip->addFile((string) $cursor->manifestPath, 'manifest.json')) {
            $zip->close();
            throw new \RuntimeException('Konnte Datenbank-Dump nicht ins Backup-ZIP übernehmen.');
        }

        // close() schreibt die Daten erst tatsächlich. Ein ignorierter Fehlschlag hier
        // hinterlässt ein leeres oder abgeschnittenes Archiv, das wie ein gültiges Backup aussieht.
        if (!$zip->close()) {
            throw new \RuntimeException('Backup-ZIP konnte nicht geschrieben werden (Plattenplatz prüfen).');
        }

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

        $closed = false;
        try {
            for ($i = $cursor->uploadsFileIndex; $i < $total; $i++) {
                $cursor->uploadsFileIndex = $i;

                $real = $files[$i];
                if (!is_file($real)) {
                    continue;
                }

                $rel = ltrim(str_replace($uploadBase, '', $real), DIRECTORY_SEPARATOR);
                if (!$zip->addFile($real, 'uploads/' . $rel)) {
                    throw new \RuntimeException('Mediendatei konnte nicht ins Backup übernommen werden: ' . $rel);
                }

                if (microtime(true) >= $deadline) {
                    $cursor->uploadsFileIndex = $i + 1;
                    // close() schreibt die in diesem Tick hinzugefügten Dateien tatsächlich ins ZIP.
                    $closed = true;
                    if (!$zip->close()) {
                        throw new \RuntimeException('Backup-ZIP konnte nicht geschrieben werden (Plattenplatz prüfen).');
                    }
                    return;
                }
            }

            $cursor->uploadsFileIndex = $total;
            $closed = true;
            if (!$zip->close()) {
                throw new \RuntimeException('Backup-ZIP konnte nicht geschrieben werden (Plattenplatz prüfen).');
            }
        } finally {
            if (!$closed) {
                $zip->close();
            }
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
     * Schreibt in den Dump und wirft, wenn nicht alles ankam.
     *
     * Ungeprüfte fwrite-Aufrufe sind bei voller Platte der Weg zu einem abgeschnittenen
     * Dump, der als erfolgreiches Backup gemeldet wird und erst im Ernstfall auffällt.
     *
     * @param resource $handle
     * @throws \RuntimeException
     */
    private function write($handle, string $data): void
    {
        $written = fwrite($handle, $data);
        if ($written === false || $written !== strlen($data)) {
            throw new \RuntimeException('Schreiben in den SQL-Dump fehlgeschlagen (Plattenplatz oder Schreibrechte prüfen).');
        }
    }

    /**
     * @param resource $handle
     */
    private function writeTableHeader($handle, string $table): void
    {
        global $wpdb;

        $tableEsc = $this->quoteIdentifier($table);

        /** @var array<int, mixed>|null $create */
        $create = $wpdb->get_row("SHOW CREATE TABLE {$tableEsc}", ARRAY_N);

        // Ohne CREATE-Anweisung würde der Dump die Tabelle beim Restore löschen und nie
        // wieder anlegen. Lieber hier abbrechen als ein Backup ausliefern, das Daten vernichtet.
        if (!is_array($create) || !isset($create[1]) || !is_string($create[1])) {
            throw new \RuntimeException(sprintf(
                'Tabellen-Definition von %s konnte nicht gelesen werden: %s',
                $table,
                $wpdb->last_error !== '' ? $wpdb->last_error : 'unbekannter Datenbankfehler'
            ));
        }

        $this->write($handle, sprintf("\n-- Table: %s\n", $table));
        $this->write($handle, sprintf("DROP TABLE IF EXISTS %s;\n", $tableEsc));
        $this->write($handle, $create[1] . ";\n\n");
    }

    /**
     * Dumpt den nächsten Abschnitt einer Tabelle und schreibt den Blätter-Stand in den Cursor.
     *
     * Geblättert wird über den Primärschlüssel, nicht über OFFSET. Der Dump läuft über viele
     * Ticks und damit über Minuten: mit OFFSET verschiebt jede in dieser Zeit eingefügte oder
     * gelöschte Zeile das Fenster, wodurch Zeilen übersprungen oder doppelt gedumpt werden.
     * Über den Schlüssel bleibt der Stand stabil, weil er an einem Wert hängt und nicht an
     * einer Position.
     *
     * Tabellen ohne Primärschlüssel (bei Plugins vereinzelt) fallen auf OFFSET zurück, dann
     * gilt die alte Einschränkung. Das steht als Hinweis im Dump.
     *
     * @param resource $handle
     * @return int Anzahl gelesener Zeilen in diesem Abschnitt (< CHUNK_SIZE, dann ist die Tabelle fertig).
     */
    private function dumpTableRowsChunk($handle, string $table, ExportCursor $cursor, int $chunkSize): int
    {
        global $wpdb;

        $tableEsc = $this->quoteIdentifier($table);
        $keyColumns = $this->primaryKeyColumns($table);

        if ($keyColumns === []) {
            if ($cursor->rowOffset === 0) {
                $this->write($handle, "-- Hinweis: Tabelle ohne Primärschlüssel, geblättert über OFFSET.\n");
            }

            $sql = $wpdb->prepare(
                "SELECT * FROM {$tableEsc} LIMIT %d OFFSET %d",
                $chunkSize,
                $cursor->rowOffset
            );
        } else {
            $columnsEsc = array_map([$this, 'quoteIdentifier'], $keyColumns);
            $orderBy = implode(', ', $columnsEsc);
            $where = '';
            $params = [];

            if ($cursor->rowKey !== null && count($cursor->rowKey) === count($keyColumns)) {
                // Zusammengesetzte Schlüssel über den Zeilen-Vergleich, das ist genau die
                // Ordnung des ORDER BY. Betrifft in WordPress nur wp_term_relationships.
                $tuple = count($columnsEsc) === 1
                    ? $columnsEsc[0]
                    : '(' . implode(', ', $columnsEsc) . ')';
                $placeholders = count($columnsEsc) === 1
                    ? '%s'
                    : '(' . implode(', ', array_fill(0, count($columnsEsc), '%s')) . ')';

                $where = " WHERE {$tuple} > {$placeholders}";
                $params = array_values($cursor->rowKey);
            }

            $sql = $wpdb->prepare(
                "SELECT * FROM {$tableEsc}{$where} ORDER BY {$orderBy} LIMIT %d",
                [...$params, $chunkSize]
            );
        }

        /** @var array<int, array<string, mixed>> $rows */
        $rows = (array) $wpdb->get_results($sql, ARRAY_A);

        foreach ($rows as $row) {
            $this->write($handle, $this->buildInsert($table, $row) . "\n");
        }

        if ($keyColumns !== [] && $rows !== []) {
            /** @var array<string, mixed> $last */
            $last = $rows[array_key_last($rows)];
            $next = [];
            foreach ($keyColumns as $column) {
                $next[] = (string) ($last[$column] ?? '');
            }
            $cursor->rowKey = $next;
        }

        return count($rows);
    }

    /**
     * Spalten des Primärschlüssels in Schlüssel-Reihenfolge, leer wenn die Tabelle keinen hat.
     *
     * @return array<int, string>
     */
    private function primaryKeyColumns(string $table): array
    {
        global $wpdb;

        if (isset($this->primaryKeyCache[$table])) {
            return $this->primaryKeyCache[$table];
        }

        $tableEsc = $this->quoteIdentifier($table);

        /** @var array<int, array<string, mixed>> $keys */
        $keys = (array) $wpdb->get_results("SHOW KEYS FROM {$tableEsc}", ARRAY_A);

        // Nach Schlüsselnamen gruppieren, den Primärschlüssel zuerst.
        $kandidaten = [];
        foreach ($keys as $key) {
            $name = (string) ($key['Key_name'] ?? '');
            $spalte = (string) ($key['Column_name'] ?? '');

            if ($name === '' || $spalte === '') {
                continue;
            }

            // Ein Schlüssel taugt nur zum Blättern, wenn er eindeutig ist und keine
            // leeren Werte zulässt. Bei einer nullbaren Spalte wäre die Reihenfolge
            // nicht eindeutig und der Vergleich mit dem letzten Wert unzuverlässig.
            if ((int) ($key['Non_unique'] ?? 1) !== 0) {
                continue;
            }

            if (($key['Null'] ?? '') === 'YES') {
                $kandidaten[$name] = null;
                continue;
            }

            if (array_key_exists($name, $kandidaten) && $kandidaten[$name] === null) {
                continue;
            }

            $kandidaten[$name][(int) ($key['Seq_in_index'] ?? 0)] = $spalte;
        }

        // Der Primärschlüssel ist die erste Wahl. Fehlt er, tut es jeder andere
        // eindeutige Schlüssel: Hauptsache, es gibt eine stabile Ordnung. Sonst bliebe
        // nur das Blättern über eine Sprungmarke, das bei jeder Änderung während des
        // Laufs Zeilen überspringt oder doppelt schreibt.
        $reihenfolge = array_merge(['PRIMARY'], array_keys($kandidaten));

        foreach ($reihenfolge as $name) {
            $spalten = $kandidaten[$name] ?? null;

            if (! is_array($spalten) || $spalten === []) {
                continue;
            }

            ksort($spalten);

            return $this->primaryKeyCache[$table] = array_values($spalten);
        }

        return $this->primaryKeyCache[$table] = [];
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
                $this->write($out, $real . "\n");
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
