<?php

declare(strict_types=1);

namespace RhDbEngine;

/**
 * Geteilte Filesystem-Storage der rh-blueprint Kollektion.
 *
 * Verwaltet `wp-content/rh-blueprint-data/{backups,jobs,auto-backups}` mit
 * Guard-Files (.htaccess + index.php) und Path-Traversal-Schutz. rh-backup legt
 * hier seine Backups ab, rh-sync seine Job-Temp-Dateien und liest die Backups.
 * Beide nutzen dieselbe Instanz über `rh_blueprint()->storage()`.
 */
final class Storage
{
    public const DATA_DIR = 'rh-blueprint-data';
    public const BACKUPS = 'backups';
    public const JOBS = 'jobs';
    public const AUTO_BACKUPS = 'auto-backups';

    /** @var array<int, string> */
    private const SUBDIRS = [self::BACKUPS, self::JOBS, self::AUTO_BACKUPS];

    public function ensureReady(): void
    {
        $base = $this->basePath();

        if (! is_dir($base)) {
            wp_mkdir_p($base);
        }

        $this->writeGuardFiles($base);

        foreach (self::SUBDIRS as $sub) {
            $path = trailingslashit($base) . $sub;
            if (! is_dir($path)) {
                wp_mkdir_p($path);
            }
            $this->writeGuardFiles($path);
        }
    }

    public function basePath(): string
    {
        return trailingslashit(WP_CONTENT_DIR) . self::DATA_DIR;
    }

    public function backupsPath(): string
    {
        return trailingslashit($this->basePath()) . self::BACKUPS;
    }

    public function jobsPath(): string
    {
        return trailingslashit($this->basePath()) . self::JOBS;
    }

    public function autoBackupsPath(): string
    {
        return trailingslashit($this->basePath()) . self::AUTO_BACKUPS;
    }

    /**
     * Pfad zu einem Unterordner der Backups, angelegt und abgeschirmt.
     *
     * Welche Unterordner es gibt und was sie bedeuten, entscheidet der Aufrufer: die
     * Engine kennt nur "eine Ebene unter backups/". Ein leerer Name führt zurück auf
     * den Backup-Ordner selbst, damit alte Aufrufe unverändert funktionieren.
     */
    public function backupsSubPath(string $sub): string
    {
        $safe = preg_replace('/[^a-z0-9\-]/', '', strtolower($sub)) ?? '';
        if ($safe === '') {
            return $this->backupsPath();
        }

        $path = trailingslashit($this->backupsPath()) . $safe;
        if (! is_dir($path)) {
            wp_mkdir_p($path);
            $this->writeGuardFiles($path);
        }

        return $path;
    }

    /**
     * Löst einen Dateinamen innerhalb eines erlaubten Roots auf.
     *
     * Erlaubt ist der blosse Name oder ein Name mit genau einer Ordner-Ebene davor
     * ("automatic/backup-xy.zip"), damit sich Sicherungen nach Anlass trennen lassen.
     * Tiefer geht es bewusst nicht: mehr Ebenen gibt es nicht, und was es nicht gibt,
     * muss auch nicht erlaubt sein.
     *
     * Schützt gegen Path-Traversal, die aufgelöste Datei MUSS unterhalb von $allowedRoot liegen.
     */
    public function resolveInside(string $allowedRoot, string $fileName): ?string
    {
        $relativ = str_replace('\\', '/', trim($fileName));
        $teile = array_values(array_filter(explode('/', $relativ), static fn (string $t): bool => $t !== ''));

        if ($teile === [] || count($teile) > 2) {
            return null;
        }

        foreach ($teile as $teil) {
            if ($teil === '.' || $teil === '..') {
                return null;
            }
        }

        $fullPath = trailingslashit($allowedRoot) . implode('/', $teile);
        $real = realpath($fullPath);
        $rootReal = realpath($allowedRoot);

        if ($real === false || $rootReal === false) {
            return null;
        }

        // Die eigentliche Absicherung: egal was im Namen stand, das Ergebnis muss
        // unterhalb der erlaubten Wurzel liegen. Fängt auch Symlinks nach aussen.
        if (! str_starts_with($real, trailingslashit($rootReal))) {
            return null;
        }

        return $real;
    }

    /**
     * Alle Sicherungen, auch die in Unterordnern.
     *
     * Rückgabe sind Pfade relativ zum Backup-Ordner ("automatic/backup-xy.zip"), damit
     * der Aufrufer sie direkt an resolveInside() weiterreichen kann. Flach liegende
     * Archive aus der Zeit vor den Unterordnern erscheinen weiterhin mit blossem Namen.
     *
     * @return array<int, string> Neueste zuerst.
     */
    public function listBackups(): array
    {
        return $this->listBackupsIn($this->backupsPath(), true);
    }

    /**
     * @return array<int, string> Datei-Basenames im angegebenen Ordner, neueste zuerst.
     *                            Mit $withSubdirs zusätzlich eine Ebene tiefer, dann als
     *                            relativer Pfad.
     */
    public function listBackupsIn(string $dir, bool $withSubdirs = false): array
    {
        if ($dir === '' || ! is_dir($dir)) {
            return [];
        }

        $muster = $withSubdirs
            ? [trailingslashit($dir) . '*.zip', trailingslashit($dir) . '*/*.zip']
            : [trailingslashit($dir) . '*.zip'];

        $files = [];
        foreach ($muster as $m) {
            foreach (glob($m) ?: [] as $f) {
                $files[] = $f;
            }
        }

        usort($files, static fn (string $a, string $b): int => filemtime($b) <=> filemtime($a));

        $basis = trailingslashit($dir);

        return array_map(
            static fn (string $f): string => str_starts_with($f, $basis) ? substr($f, strlen($basis)) : basename($f),
            $files
        );
    }

    public function reserveTempFile(string $prefix): string
    {
        $this->ensureReady();
        $name = sprintf('%s-%s.tmp', $prefix, wp_generate_password(8, false, false));

        return trailingslashit($this->jobsPath()) . $name;
    }

    /**
     * Persistentes Arbeitsverzeichnis für einen Job unter `jobs/{jobId}/`.
     *
     * Anders als reserveTempFile() ist dieses Verzeichnis dafür gedacht, über mehrere
     * Hintergrund-Ticks (Requests) hinweg zu überleben (extrahierte db.sql, Chunks etc.).
     * Der Aufrufer ist für das Aufräumen verantwortlich (oder die GC, siehe gcStaleJobs()).
     * Der Job-Identifier wird auf alphanumerisch + Bindestrich begrenzt (Path-Traversal-Schutz).
     */
    public function jobWorkdir(string $jobId): string
    {
        $this->ensureReady();

        $safe = preg_replace('/[^A-Za-z0-9\-]/', '', $jobId) ?? '';
        if ($safe === '') {
            $safe = 'job-' . wp_generate_password(8, false, false);
        }

        $dir = trailingslashit($this->jobsPath()) . $safe;
        if (! is_dir($dir)) {
            wp_mkdir_p($dir);
        }

        return $dir;
    }

    /**
     * Markiert ein Job-Verzeichnis als "lebt noch", damit die GC es in Ruhe lässt.
     *
     * Nötig, weil die mtime eines Verzeichnisses sich nur ändert, wenn direkte Kinder
     * angelegt oder gelöscht werden. Ein laufender Import liest stundenlang aus einer
     * bereits entpackten db.sql und altert dabei aus Sicht der GC ungebremst weiter.
     * Wird pro Tick von Importer und Exporter aufgerufen.
     */
    public function touchJobWorkdir(string $workdir): void
    {
        if ($workdir === '' || ! is_dir($workdir)) {
            return;
        }

        // Nur innerhalb von jobs/, damit ein manipulierter Cursor nicht beliebige
        // Zeitstempel im Dateisystem verändern kann.
        $jobsReal = realpath($this->jobsPath());
        $dirReal = realpath($workdir);
        if ($jobsReal === false || $dirReal === false || ! str_starts_with($dirReal, trailingslashit($jobsReal))) {
            return;
        }

        // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Heartbeat, ein Fehlschlag ist unkritisch.
        @touch($dirReal);
    }

    /**
     * Setzt restriktive Rechte auf eine erzeugte Datei.
     *
     * Backups und Dumps enthalten die komplette Kundendatenbank inklusive Passwort-Hashes
     * und Zugangsdaten aus wp_options. Mit der üblichen umask entstehen sie als 0644 und
     * sind damit auf Shared Hosting ohne Nutzer-Isolation für andere Konten lesbar.
     */
    public function protectFile(string $path): void
    {
        if (! is_file($path)) {
            return;
        }

        // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- auf manchen Dateisystemen nicht unterstützt, dann bleibt es beim Default.
        @chmod($path, 0600);
    }

    /**
     * Räumt verwaiste Einträge im jobs/-Ordner auf, die älter als $maxAgeSeconds sind.
     *
     * Greift abgebrochene Sync-Sessions und Tick-Workdirs ab, deren Job nie sauber
     * abgeschlossen hat. Bei großen Transfers (10 GB+) verhindert das eine volllaufende Platte.
     * Guard-Files (.htaccess, index.php) werden nie angefasst.
     *
     * @return int Anzahl gelöschter Top-Level-Einträge.
     */
    public function gcStaleJobs(int $maxAgeSeconds): int
    {
        $jobsDir = $this->jobsPath();
        if (! is_dir($jobsDir)) {
            return 0;
        }

        $threshold = time() - max(0, $maxAgeSeconds);
        $removed = 0;

        $entries = glob(trailingslashit($jobsDir) . '*') ?: [];
        foreach ($entries as $entry) {
            $base = basename($entry);
            if ($base === '.htaccess' || $base === 'index.php') {
                continue;
            }

            $mtime = @filemtime($entry);
            if ($mtime === false || $mtime > $threshold) {
                continue;
            }

            if (is_dir($entry)) {
                $this->deleteDirRecursive($entry);
                $removed++;
            } elseif (is_file($entry)) {
                // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- GC einer verwaisten Temp-Datei, ein Fehlschlag ist unkritisch.
                if (@unlink($entry)) {
                    $removed++;
                }
            }
        }

        return $removed;
    }

    private function deleteDirRecursive(string $dir): void
    {
        $items = glob(trailingslashit($dir) . '*') ?: [];
        foreach ($items as $item) {
            if (is_dir($item)) {
                $this->deleteDirRecursive($item);
            } else {
                // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- GC einer verwaisten Temp-Datei, ein Fehlschlag ist unkritisch.
                @unlink($item);
            }
        }
        // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- GC eines verwaisten Temp-Verzeichnisses, ein Fehlschlag ist unkritisch.
        @rmdir($dir);
    }

    private function writeGuardFiles(string $path): void
    {
        // Apache 2.4 (mod_authz_core) und 2.2 (mod_access_compat) gleichzeitig abdecken.
        // ACHTUNG: Nginx wertet .htaccess NICHT aus, dort muss der Backup-Pfad serverseitig
        // gesperrt werden (location-Block auf rh-blueprint-data/). Der nicht erratbare
        // Random-Dateiname (Exporter) ist die eigentliche Absicherung, die hier ist Defense-in-Depth.
        //
        // Migration: eine vorhandene .htaccess der alten Generation (nur 2.2-Syntax) wird
        // überschrieben, sonst greift der 2.4-Schutz auf Bestands-Installs nie.
        $htaccess = trailingslashit($path) . '.htaccess';
        $desired = "<IfModule mod_authz_core.c>\n  Require all denied\n</IfModule>\n"
            . "<IfModule !mod_authz_core.c>\n  Order deny,allow\n  Deny from all\n</IfModule>\n";
        $current = is_readable($htaccess) ? (string) file_get_contents($htaccess) : '';
        if (! str_contains($current, 'Require all denied')) {
            file_put_contents($htaccess, $desired);
        }

        $indexPhp = trailingslashit($path) . 'index.php';
        if (! file_exists($indexPhp)) {
            file_put_contents($indexPhp, "<?php\n// Silence is golden.\n");
        }
    }
}
