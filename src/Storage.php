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
        $htaccess = trailingslashit($path) . '.htaccess';
        if (! file_exists($htaccess)) {
            file_put_contents($htaccess, "Order deny,allow\nDeny from all\n");
        }

        $indexPhp = trailingslashit($path) . 'index.php';
        if (! file_exists($indexPhp)) {
            file_put_contents($indexPhp, "<?php\n// Silence is golden.\n");
        }
    }
}
