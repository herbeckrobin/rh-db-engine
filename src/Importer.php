<?php

declare(strict_types=1);

namespace RhDbEngine;


final class Importer
{
    /** @var array<int, string> */
    private const ALLOWED_ENTRIES = ['db.sql', 'manifest.json'];

    private const URL_REWRITE_CHUNK = 200;

    /**
     * Lesegröße für den SQL-Dump. Rein eine Puffergröße, KEINE Statement-Grenze:
     * das Statement-Ende bestimmt der Quote-State-Scanner (siehe findStatementEnd()).
     */
    private const SQL_READ_CHUNK = 65536;

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
        // set_time_limit steckt auf vielen Shared-Hostern in disable_functions. Dann ist die
        // Funktion undefiniert, und das @ fängt den resultierenden Error NICHT ab (nur Warnungen).
        // Ohne diesen Guard stirbt jeder Import in der ersten Zeile.
        if (function_exists('set_time_limit')) {
            set_time_limit(0);
        }
        if (function_exists('wp_raise_memory_limit')) {
            wp_raise_memory_limit('admin');
        }

        $deadline = microtime(true) + max(0.1, $budgetSeconds);

        // Hält das Job-Verzeichnis für die Garbage Collection sichtbar am Leben. Ohne das
        // altert ein laufender Job aus Sicht der GC weiter (die mtime eines Verzeichnisses
        // ändert sich beim Lesen der db.sql nicht), und ein langer Transfer wird mitten im
        // Lauf aufgeräumt.
        $this->storage->touchJobWorkdir($cursor->workdir);

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

        if ($cursor->isDone()) {
            $this->flushCaches();
        }

        return $cursor;
    }

    /**
     * Räumt nach dem Import die Caches ab.
     *
     * Der Import schreibt am Objekt-Cache vorbei direkt in die Datenbank. Ohne diesen
     * Schritt liest WordPress auf Sites mit Redis oder Memcached weiter die alten Options,
     * Posts und Terms. Das Ergebnis ist ein Mischzustand aus altem Cache und neuer
     * Datenbank, der je nach Laufzeit stundenlang anhält und wie ein kaputter Restore aussieht.
     */
    private function flushCaches(): void
    {
        if (function_exists('wp_cache_flush')) {
            wp_cache_flush();
        }

        // Permalink-Regeln aus dem Dump passen nicht zwingend zu den hier aktiven
        // Post-Types und Taxonomien.
        if (function_exists('flush_rewrite_rules')) {
            flush_rewrite_rules(false);
        }

        /**
         * Anknüpfpunkt für Page-Caches (Plugin- oder Server-Ebene).
         */
        do_action('rh-db-engine/import_finished');
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

        // Ziel-URLs JETZT festhalten, solange die Datenbank noch der Zielseite gehört.
        // Läuft der Import über mehrere Hintergrund-Requests, liest ein späterer Tick
        // die Options frisch aus der Datenbank, und dort steht dann bereits die URL der
        // Quellseite. Die Umschreibung würde Quelle auf Quelle abbilden und damit
        // wirkungslos bleiben, die Zielseite bliebe auf die Quell-Domain verdrahtet.
        $cursor->targetSiteUrl = (string) get_site_url();
        $cursor->targetHomeUrl = (string) get_home_url();
        $cursor->includesUploads = !empty($manifest['includes_uploads']);
        $cursor->phase = ImportCursor::PHASE_SQL;
        $cursor->sqlByteOffset = 0;
    }

    /**
     * @param (callable(string): bool)|null $tableFilter
     */
    private function stepSql(ImportCursor $cursor, ?callable $tableFilter, float $deadline): void
    {
        $sqlFile = trailingslashit($cursor->workdir) . 'extracted/db.sql';
        $handle = fopen($sqlFile, 'rb');
        if ($handle === false) {
            throw new \RuntimeException('SQL-Datei nicht lesbar.');
        }

        try {
            if ($cursor->sqlByteOffset > 0 && fseek($handle, $cursor->sqlByteOffset) !== 0) {
                throw new \RuntimeException(
                    sprintf('SQL-Import konnte an Byte-Position %d nicht fortgesetzt werden.', $cursor->sqlByteOffset)
                );
            }

            $filePos = $cursor->sqlByteOffset;
            $buffer = '';
            $inString = false;
            $escaped = false;

            while (true) {
                $chunk = fread($handle, self::SQL_READ_CHUNK);
                if ($chunk === false || $chunk === '') {
                    break;
                }

                $filePos += strlen($chunk);
                $chunkLen = strlen($chunk);
                $offset = 0;

                while ($offset < $chunkLen) {
                    $end = $this->findStatementEnd($chunk, $offset, $inString, $escaped);

                    if ($end === null) {
                        // Statement reicht über dieses Lesestück hinaus, weitersammeln.
                        $buffer .= substr($chunk, $offset);
                        break;
                    }

                    $buffer .= substr($chunk, $offset, $end - $offset + 1);
                    $offset = $end + 1;

                    $this->runStatement($buffer, $cursor, $tableFilter);
                    $buffer = '';

                    // Nach einem vollständigen Statement ist der Scanner-Zustand neutral
                    // (nicht im String, kein offenes Escape). Genau hier ist der Resume-Punkt.
                    if (microtime(true) >= $deadline) {
                        $cursor->sqlByteOffset = $filePos - ($chunkLen - $offset);
                        return;
                    }
                }
            }

            // Letztes Statement ohne abschließendes Semikolon (Defensive).
            $this->runStatement($buffer, $cursor, $tableFilter);
        } finally {
            fclose($handle);
        }

        $cursor->phase = ImportCursor::PHASE_META_REWRITE;
    }

    /**
     * Sucht ab $from das erste Semikolon, das AUSSERHALB eines SQL-Strings steht, und
     * schreibt den Scanner-Zustand fort (offener String, offenes Escape am Stück-Ende).
     *
     * Das ist der Kern der Statement-Erkennung. Die frühere Variante prüfte, ob ein
     * gelesenes Stück auf ';' endet. Bei Zellen über der Lesegröße (serialisierte
     * Elementor-Daten, große Options, base64 im post_content) zerriss das Statements
     * mitten im String: das Fragment lief als ungültiges SQL auf, und die Zeile fehlte
     * anschließend stillschweigend in der wiederhergestellten Datenbank.
     *
     * Läuft über strcspn (C-Ebene) statt zeichenweise, damit große Dumps nicht ausbremsen.
     *
     * @param bool $inString Wird fortgeschrieben (by reference).
     * @param bool $escaped  Wird fortgeschrieben (by reference), offener Backslash am Stück-Ende.
     * @return int|null Position des Statement-Semikolons, oder null wenn das Stück endet.
     */
    private function findStatementEnd(string $chunk, int $from, bool &$inString, bool &$escaped): ?int
    {
        $len = strlen($chunk);
        $i = $from;

        // Ein Backslash am Ende des vorherigen Stücks escaped das erste Zeichen hier.
        if ($escaped) {
            $escaped = false;
            $i++;
        }

        while ($i < $len) {
            if ($inString) {
                $i += strcspn($chunk, "'\\", $i);
                if ($i >= $len) {
                    break;
                }

                if ($chunk[$i] === '\\') {
                    if ($i + 1 >= $len) {
                        // Escape-Zeichen ist das letzte im Stück, das Ziel folgt im nächsten.
                        $escaped = true;
                        break;
                    }
                    $i += 2;
                    continue;
                }

                $inString = false;
                $i++;
                continue;
            }

            $i += strcspn($chunk, "';", $i);
            if ($i >= $len) {
                break;
            }

            if ($chunk[$i] === ';') {
                return $i;
            }

            $inString = true;
            $i++;
        }

        return null;
    }

    /**
     * Bereitet ein gesammeltes Statement auf und führt es aus.
     *
     * Kommentar- und Leerzeilen werden erst hier vom Statement-Anfang entfernt (nicht mehr
     * beim Lesen), damit eine Zeile, die zufällig innerhalb eines Werts mit '--' beginnt,
     * nicht verworfen wird. Die Prefix-Umschreibung greift dadurch am echten
     * Statement-Anfang statt am Anfang eines beliebigen Lesestücks.
     *
     * @param (callable(string): bool)|null $tableFilter
     * @throws \RuntimeException wenn die Datenbank das Statement ablehnt.
     */
    private function runStatement(string $statement, ImportCursor $cursor, ?callable $tableFilter): void
    {
        global $wpdb;

        $sql = $this->stripLeadingComments($statement);
        if ($sql === '') {
            return;
        }

        $sql = $this->rewriteStatementPrefix($sql, $cursor->sourcePrefix, $cursor->targetPrefix);

        $this->assertStatementAllowed($sql, $cursor->targetPrefix);

        if (!$this->shouldExecuteStatement($sql, $tableFilter)) {
            return;
        }

        // Ein Fehlschlag darf NICHT still durchrutschen: an dieser Stelle sind Tabellen
        // bereits gedroppt, ein ignorierter Fehler hinterlässt eine halb ersetzte Datenbank,
        // die als erfolgreicher Import gemeldet wird.
        if ($wpdb->query($sql) === false) {
            throw new \RuntimeException(sprintf(
                'SQL-Import abgebrochen: %s (Statement: %s)',
                $wpdb->last_error !== '' ? $wpdb->last_error : 'unbekannter Datenbankfehler',
                $this->statementExcerpt($sql)
            ));
        }
    }

    /**
     * Entfernt führende Kommentar- und Leerzeilen vom Statement-Anfang.
     */
    private function stripLeadingComments(string $sql): string
    {
        while (true) {
            $trimmed = ltrim($sql);
            if (!str_starts_with($trimmed, '--')) {
                return $trimmed;
            }

            $newline = strpos($trimmed, "\n");
            if ($newline === false) {
                return '';
            }

            $sql = substr($trimmed, $newline + 1);
        }
    }

    /**
     * Schreibt den Tabellen-Prefix am Statement-Anfang um (Quelle -> Ziel).
     */
    private function rewriteStatementPrefix(string $sql, string $sourcePrefix, string $targetPrefix): string
    {
        if ($sourcePrefix === '' || $sourcePrefix === $targetPrefix) {
            return $sql;
        }

        $quoted = preg_quote($sourcePrefix, '/');
        $rewritten = preg_replace(
            [
                '/^(DROP TABLE IF EXISTS )`' . $quoted . '/',
                '/^(CREATE TABLE )`' . $quoted . '/',
                '/^(INSERT INTO )`' . $quoted . '/',
            ],
            '$1`' . $targetPrefix,
            $sql
        );

        return is_string($rewritten) ? $rewritten : $sql;
    }

    /**
     * Kurzer, loggbarer Ausschnitt eines Statements (Werte können megabytegroß sein).
     */
    private function statementExcerpt(string $sql): string
    {
        $excerpt = trim(substr($sql, 0, 120));

        return strlen($sql) > 120 ? $excerpt . ' [...]' : $excerpt;
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
        $pairs = $this->urlRewritePairs($cursor->manifest, $cursor);
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

        // Ab hier ist klar: das Backup enthält laut Manifest Medien und sie sind angefordert.
        // Jeder Abbruch bedeutet, dass ALLE Medien fehlen. Das darf nicht als erfolgreicher
        // Import durchgehen, sonst sieht ein Restore ohne Mediathek aus wie ein gelungener.
        if (!class_exists(\ZipArchive::class)) {
            throw new \RuntimeException('Backup enthält Medien, aber die ZIP-PHP-Extension ist nicht verfügbar.');
        }

        $uploadDir = wp_upload_dir();
        $uploadBase = (string) $uploadDir['basedir'];
        if ($uploadBase === '') {
            throw new \RuntimeException('Uploads-Verzeichnis konnte nicht ermittelt werden.');
        }
        wp_mkdir_p($uploadBase);
        $uploadBaseReal = realpath($uploadBase);
        if ($uploadBaseReal === false) {
            throw new \RuntimeException('Uploads-Verzeichnis nicht vorhanden oder nicht beschreibbar: ' . $uploadBase);
        }

        $zip = new \ZipArchive();
        $status = $zip->open($cursor->zipPath);
        if ($status !== true) {
            throw new \RuntimeException('Backup-ZIP konnte für die Medien nicht geöffnet werden: ' . (string) $status);
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

                // Ein Backup darf keine ausführbaren Dateien ins öffentlich erreichbare
                // Uploads-Verzeichnis legen. Beim Pull stammt das Archiv von einem Peer.
                if ($this->isExecutablePath($relPath)) {
                    $cursor->uploadsFailed++;
                    continue;
                }

                $targetPath = trailingslashit($uploadBaseReal) . $relPath;
                $targetDir = dirname($targetPath);
                wp_mkdir_p($targetDir);

                $targetDirReal = realpath($targetDir);
                if ($targetDirReal === false || !str_starts_with($targetDirReal, $uploadBaseReal)) {
                    continue;
                }

                // Ein vorhandener Symlink an dieser Stelle würde das Schreiben aus dem
                // Uploads-Baum herausführen. Der Verzeichnis-Check oben deckt das nicht ab.
                if (is_link($targetPath)) {
                    $cursor->uploadsFailed++;
                    continue;
                }

                // Einzelne Datei-Fehler brechen den Import nicht ab (der Rest der Mediathek
                // ist brauchbar), werden aber gezählt und am Phasen-Ende gemeldet.
                $stream = $zip->getStream($name);
                if ($stream === false) {
                    $cursor->uploadsFailed++;
                    continue;
                }

                $out = fopen($targetPath, 'wb');
                if ($out === false) {
                    fclose($stream);
                    $cursor->uploadsFailed++;
                    continue;
                }

                $copied = stream_copy_to_stream($stream, $out);
                fclose($stream);
                if (!fclose($out) || $copied === false) {
                    $cursor->uploadsFailed++;
                }

                if (microtime(true) >= $deadline) {
                    $cursor->uploadsFileIndex = $i + 1;
                    return;
                }
            }
        } finally {
            $zip->close();
        }

        if ($cursor->uploadsFailed > 0) {
            /**
             * Der Import ist durchgelaufen, aber einzelne Mediendateien fehlen.
             * Der Aufrufer entscheidet, wie er das dem Nutzer zeigt.
             */
            do_action('rh-db-engine/import_incomplete_uploads', $cursor->uploadsFailed, $cursor->zipPath);
        }

        $cursor->phase = ImportCursor::PHASE_DONE;
    }

    // ============================================================
    // Helfer (unverändert aus der monolithischen Variante übernommen)
    // ============================================================

    /**
     * Endungen, die im Uploads-Verzeichnis niemals landen dürfen.
     *
     * Geprüft wird JEDES Punkt-Segment des Pfads, nicht nur die letzte Endung: bei einer
     * unglücklichen Server-Konfiguration führt auch `bild.php.jpg` zur Ausführung.
     *
     * @var array<int, string>
     */
    private const EXECUTABLE_EXTENSIONS = [
        'php', 'php3', 'php4', 'php5', 'php7', 'php8', 'phps', 'pht', 'phtml', 'phar',
        'shtml', 'cgi', 'pl', 'py', 'rb', 'sh', 'asp', 'aspx', 'jsp', 'htaccess', 'user',
    ];

    private function isExecutablePath(string $relPath): bool
    {
        $name = strtolower(basename($relPath));

        // .user.ini setzt PHP-Einstellungen und ist damit ebenso gefährlich wie ein Skript.
        if ($name === '.htaccess' || $name === '.user.ini' || $name === 'web.config') {
            return true;
        }

        foreach (explode('.', $name) as $segment) {
            if (in_array($segment, self::EXECUTABLE_EXTENSIONS, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Grobprüfung des Archivs, bevor auch nur ein Byte entpackt wird.
     *
     * Ohne diese Grenze kann ein wenige Megabyte großes Archiv die Platte füllen. Bei
     * einem Backup-Plugin ist eine volle Platte besonders unangenehm: danach lässt sich
     * auch kein neues Backup mehr schreiben.
     *
     * @param string $targetPath Verzeichnis, in das entpackt wird. Der freie Platz wird
     *                           dort gemessen, nicht irgendwo sonst im Dateisystem.
     * @throws \RuntimeException
     */
    private function assertArchiveSane(\ZipArchive $zip, string $targetPath): void
    {
        $uncompressed = 0;
        $compressed = 0;

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            if ($stat === false) {
                continue;
            }
            $uncompressed += (int) ($stat['size'] ?? 0);
            $compressed += (int) ($stat['comp_size'] ?? 0);
        }

        if ($uncompressed <= 0) {
            return;
        }

        // Ein Kompressionsverhältnis jenseits von 200:1 erreicht kein reales Backup
        // (SQL-Text und Medien liegen deutlich darunter), eine Zip-Bombe schon.
        if ($compressed > 0 && ($uncompressed / $compressed) > 200) {
            throw new \RuntimeException(sprintf(
                'Backup abgelehnt: unplausibles Kompressionsverhältnis (%.0f:1).',
                $uncompressed / $compressed
            ));
        }

        // disk_free_space steckt auf manchen Hostern in disable_functions.
        if (!function_exists('disk_free_space') || !is_dir($targetPath)) {
            return;
        }

        $free = disk_free_space($targetPath);
        if ($free === false) {
            return;
        }

        // Reserve, damit die Platte nicht exakt bis zum Anschlag läuft.
        if ($uncompressed + 64 * 1024 * 1024 > $free) {
            throw new \RuntimeException(sprintf(
                'Zu wenig Plattenplatz: das Backup braucht entpackt %s, frei sind %s.',
                size_format($uncompressed),
                size_format((float) $free)
            ));
        }
    }

    private function extractZipSafely(string $zipPath, string $destination): void
    {
        if (!class_exists(\ZipArchive::class)) {
            throw new \RuntimeException('ZipArchive-Klasse nicht verfügbar.');
        }

        $zip = new \ZipArchive();
        $status = $zip->open($zipPath, \ZipArchive::CHECKCONS);
        if ($status !== true) {
            throw new \RuntimeException('ZIP konnte nicht geöffnet werden: ' . (string) $status);
        }

        try {
            $this->assertArchiveSane($zip, $destination);
        } catch (\RuntimeException $e) {
            $zip->close();
            throw $e;
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

            // Gegen den VOLLEN Pfad prüfen, nicht den basename: db.sql und manifest.json liegen
            // im ZIP-Root. Ein basename-Vergleich würde auch eine gleichnamige Datei aus den
            // uploads matchen (z.B. uploads/really-simple-ssl/…/manifest.json) und beim flachen
            // Extrahieren die echte Root-manifest.json überschreiben -> Import bricht mit
            // "kein db_prefix" ab, obwohl das Backup intakt ist.
            if (!in_array($normalized, self::ALLOWED_ENTRIES, true)) {
                continue;
            }

            $baseName = basename($normalized);
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
     * Prüft, ob ein Statement überhaupt ausgeführt werden darf.
     *
     * Bewusst als Allowlist: alles, was der eigene Exporter nicht erzeugt, wird abgelehnt.
     * Die frühere Variante war fail-open (unbekanntes Statement => ausführen). Damit lief
     * aus einem manipulierten Dump beliebiges SQL durch (UPDATE, GRANT, LOAD DATA,
     * SELECT INTO OUTFILE), und der Tabellen-Filter, mit dem rh-sync sein Sync-Profil
     * durchsetzt, war wirkungslos. Ein Backup-ZIP ist nicht vertrauenswürdig: beim Pull
     * stammt es von einem entfernten Peer.
     *
     * @throws \RuntimeException bei einem Statement ausserhalb der Allowlist.
     */
    private function assertStatementAllowed(string $sql, string $targetPrefix): void
    {
        $type = null;
        foreach (['SET ', 'DROP TABLE ', 'CREATE TABLE ', 'INSERT INTO '] as $allowed) {
            if (strncasecmp($sql, $allowed, strlen($allowed)) === 0) {
                $type = $allowed;
                break;
            }
        }

        if ($type === null) {
            throw new \RuntimeException(
                'Unerwartetes SQL im Backup abgelehnt: ' . $this->statementExcerpt($sql)
            );
        }

        if ($type === 'SET ') {
            return;
        }

        // Jede Tabelle im eigenen Dump trägt den Prefix (der Export wählt genau danach aus).
        // Nach der Prefix-Umschreibung muss sie den Ziel-Prefix tragen. Alles andere zielt
        // an der Site vorbei, etwa auf eine fremde Datenbank.
        $table = $this->extractTableFromStatement($sql);
        if ($table === null || ($targetPrefix !== '' && !str_starts_with($table, $targetPrefix))) {
            throw new \RuntimeException(sprintf(
                'SQL im Backup zielt auf eine unerwartete Tabelle (%s): %s',
                $table ?? 'nicht erkennbar',
                $this->statementExcerpt($sql)
            ));
        }
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
            // Nur noch SET-Statements kommen ohne Tabelle hier an (siehe assertStatementAllowed).
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
    private function urlRewritePairs(array $manifest, ImportCursor $cursor): array
    {
        $oldSiteUrl = isset($manifest['site_url']) ? (string) $manifest['site_url'] : '';
        $oldHomeUrl = isset($manifest['home_url']) ? (string) $manifest['home_url'] : '';

        // Aus dem Cursor, nicht frisch aus der Datenbank: siehe stepExtract().
        // Der Fallback greift nur für Cursor aus einer älteren Version.
        $newSiteUrl = $cursor->targetSiteUrl !== '' ? $cursor->targetSiteUrl : (string) get_site_url();
        $newHomeUrl = $cursor->targetHomeUrl !== '' ? $cursor->targetHomeUrl : (string) get_home_url();

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
