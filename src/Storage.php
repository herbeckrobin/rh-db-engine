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
     * Löst einen Dateinamen innerhalb eines erlaubten Roots auf.
     * Schützt gegen Path-Traversal, die aufgelöste Datei MUSS unterhalb von $allowedRoot liegen.
     */
    public function resolveInside(string $allowedRoot, string $fileName): ?string
    {
        $fileName = basename($fileName);
        if ($fileName === '' || $fileName === '.' || $fileName === '..') {
            return null;
        }

        $fullPath = trailingslashit($allowedRoot) . $fileName;
        $real = realpath($fullPath);
        $rootReal = realpath($allowedRoot);

        if ($real === false || $rootReal === false) {
            return null;
        }

        if (! str_starts_with($real, trailingslashit($rootReal))) {
            return null;
        }

        return $real;
    }

    /**
     * @return array<int, string> Datei-Basenames im Backups-Ordner, neueste zuerst.
     */
    public function listBackups(): array
    {
        $dir = $this->backupsPath();
        if (! is_dir($dir)) {
            return [];
        }

        $files = glob(trailingslashit($dir) . '*.zip') ?: [];
        usort($files, static fn (string $a, string $b): int => filemtime($b) <=> filemtime($a));

        return array_map('basename', $files);
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
