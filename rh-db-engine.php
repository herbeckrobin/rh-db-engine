<?php

/**
 * RH DB-Engine, Entry-Point.
 *
 * Wird von jedem Plugin, das die Engine bundelt (rh-backup, rh-sync), über
 * Composers files-autoload geladen, möglicherweise mehrfach. Tut nur zwei Dinge,
 * beide idempotent: Loader laden und die eigene Version anmelden. Welche Version
 * läuft, entscheidet der Loader auf plugins_loaded (höchste gewinnt).
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    return;
}

if (! class_exists('RhDbEngineLoader', false)) {
    require_once __DIR__ . '/loader/RhDbEngineLoader.php';
}

RhDbEngineLoader::declareVersion(
    (string) (require __DIR__ . '/version.php'),
    __DIR__
);
