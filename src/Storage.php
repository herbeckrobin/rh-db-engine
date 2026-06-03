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
