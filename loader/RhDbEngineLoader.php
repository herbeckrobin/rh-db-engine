<?php

/**
 * Version-Negotiation-Loader für das geteilte rh-db-engine Package.
 *
 * Gleiches Pattern wie der Core-Loader: mehrere Plugins (rh-backup, rh-sync)
 * können die Engine bundeln, die höchste Version gewinnt zur Laufzeit. Die
 * Klasse muss byte-stabil bleiben (sie liegt in jedem Bundle, der erste
 * class_exists-Guard gewinnt). Neue Funktionalität gehört in src/, nicht hierher.
 *
 * `loadLatest()` entdeckt ALLE gebündelten Engines über das Dateisystem, statt
 * sich auf die Selbst-Anmeldung jedes Bundles zu verlassen. Grund (identisch zum
 * Core-Loader): Composers files-autoload vergibt dem Entry-Point in jedem Bundle
 * denselben Hash und führt ihn pro Request nur EINMAL aus (globaler Dedup). Also
 * meldet nur das zuerst geladene Plugin (alphabetisch, z.B. rh-backup) seine
 * Version an. Bei gemischten Versionen (rh-backup mit alter Engine + rh-sync mit
 * neuer) lud sonst die ALTE Engine und rh-sync fatalt auf einer Methode, die es
 * dort noch nicht gibt (z.B. Storage::jobWorkdir()).
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
        if (self::$winningVersion !== '') {
            return;
        }

        self::discoverBundles();

        if (self::$versions === []) {
            return;
        }

        $version = self::pickLatest(array_keys(self::$versions));
        self::$winningVersion = $version;
        self::$winningDir = self::$versions[$version];

        require_once self::$winningDir . '/bootstrap.php';
    }

    /**
     * Entdeckt alle im Request vorliegenden Engine-Bundles über das Dateisystem
     * (siehe Klassen-Doc: Composers files-autoload-Dedup führt den Entry-Point pro
     * Request nur einmal aus, die Selbst-Anmeldung sieht also nur das erste Bundle).
     */
    private static function discoverBundles(): void
    {
        if (! defined('WP_PLUGIN_DIR')) {
            return;
        }

        $matches = glob(WP_PLUGIN_DIR . '/*/vendor/rh/db-engine/version.php', GLOB_NOSORT);

        if ($matches === false) {
            return;
        }

        foreach ($matches as $versionFile) {
            $version = (string) (include $versionFile);
            if ($version !== '') {
                self::$versions[$version] = dirname($versionFile);
            }
        }
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
