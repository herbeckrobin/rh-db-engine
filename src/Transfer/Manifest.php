<?php

declare(strict_types=1);

namespace RhDbEngine\Transfer;

/**
 * Generisches Datei-Manifest für einen Verzeichnisbaum.
 *
 * Feature-frei: kennt keine Sync-, Peer- oder WordPress-Konzepte. Bildet die Basis für
 * Delta-Übertragung (nur geänderte Dateien). Bedient sowohl `uploads/` (DB-Sync) als auch
 * später einen kompletten Filesystem-Sync, der Wurzelpfad ist nur ein Parameter.
 *
 * Pro Datei werden size + mtime erfasst (billig, nur stat). Der Hash ist lazy: er wird nur
 * berechnet wenn explizit angefordert (teuer, nur zur Konflikt-Auflösung gedacht).
 */
final class Manifest
{
    /**
     * Scannt einen Verzeichnisbaum und liefert ein Manifest:
     *   [ rel_path => { 'size' => int, 'mtime' => int, 'hash' => string? } ].
     *
     * @return array<string, array{size: int, mtime: int, hash?: string}>
     */
    public static function build(string $root, bool $withHash = false): array
    {
        $root = rtrim($root, '/\\');
        if ($root === '' || !is_dir($root)) {
            return [];
        }

        // Symlinks im Wurzelpfad auflösen (z.B. /tmp -> /private/tmp), sonst matcht der
        // Präfix-Vergleich gegen die per getRealPath() aufgelösten Datei-Pfade nicht.
        $root = realpath($root) ?: $root;

        $manifest = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if (!$file instanceof \SplFileInfo || !$file->isFile()) {
                continue;
            }

            $real = $file->getRealPath();
            if ($real === false) {
                continue;
            }

            $rel = self::relativePath($root, $real);
            if ($rel === '') {
                continue;
            }

            $entry = [
                'size' => (int) $file->getSize(),
                'mtime' => (int) $file->getMTime(),
            ];
            if ($withHash) {
                $entry['hash'] = self::hashFile($real);
            }

            $manifest[$rel] = $entry;
        }

        return $manifest;
    }

    /**
     * Stabiler, schneller Inhalts-Hash einer Datei (xxh128 wenn verfügbar, sonst crc32b).
     */
    public static function hashFile(string $path): string
    {
        if (!is_file($path)) {
            return '';
        }

        $algos = hash_algos();
        $algo = in_array('xxh128', $algos, true) ? 'xxh128' : 'crc32b';
        $hash = hash_file($algo, $path);

        return $hash === false ? '' : $hash;
    }

    private static function relativePath(string $root, string $absolute): string
    {
        $normalizedRoot = str_replace('\\', '/', $root);
        $normalizedAbs = str_replace('\\', '/', $absolute);

        if (!str_starts_with($normalizedAbs, $normalizedRoot . '/')) {
            return '';
        }

        return ltrim(substr($normalizedAbs, strlen($normalizedRoot)), '/');
    }
}
