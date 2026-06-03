<?php

/**
 * Version-Negotiation-Loader für das geteilte rh-db-engine Package.
 *
 * Gleiches Pattern wie der Core-Loader: mehrere Plugins (rh-backup, rh-sync)
 * können die Engine bundeln, die höchste Version gewinnt zur Laufzeit. Die
 * Klasse muss byte-stabil bleiben (sie liegt in jedem Bundle, der erste
 * class_exists-Guard gewinnt). Neue Funktionalität gehört in src/, nicht hierher.
 */

declare(strict_types=1);

final class RhDbEngineLoader
{
    /** @var array<string, string> Map Version => Verzeichnis-Pfad */
    private static array $versions = [];

    private static bool $hooked = false;

    private static string $winningVersion = '';

    private static string $winningDir = '';

    public static function declareVersion(string $version, string $dir): void
    {
        if ($version === '' || $dir === '') {
            return;
        }

        self::$versions[$version] = $dir;

        if (! self::$hooked) {
            self::$hooked = true;
            // Prio -9: nach dem Core (-10), vor den Plugins (Default 10).
            add_action('plugins_loaded', [self::class, 'loadLatest'], -9);
        }
    }

    public static function loadLatest(): void
    {
        if (self::$winningVersion !== '' || self::$versions === []) {
            return;
        }

        $version = self::pickLatest(array_keys(self::$versions));
        self::$winningVersion = $version;
        self::$winningDir = self::$versions[$version];

        require_once self::$winningDir . '/bootstrap.php';
    }

    /**
     * @param array<int, string> $versions
     */
    public static function pickLatest(array $versions): string
    {
        usort($versions, 'version_compare');

        return $versions === [] ? '' : (string) end($versions);
    }

    public static function winningVersion(): string
    {
        return self::$winningVersion;
    }

    public static function winningDir(): string
    {
        return self::$winningDir;
    }
}
