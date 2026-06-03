<?php

/**
 * Globaler Helper der DB-Engine. Vom Bootstrap der Gewinner-Version geladen.
 */

declare(strict_types=1);

if (! function_exists('rh_db_engine')) {
    /**
     * Zentraler Zugriff auf die DB-Engine.
     * Über `rh_db_engine()->exporter()` / `importer()` / `storage()`.
     */
    function rh_db_engine(): \RhDbEngine\DbEngine
    {
        return \RhDbEngine\DbEngine::instance();
    }
}
