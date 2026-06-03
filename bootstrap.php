<?php

/**
 * DB-Engine-Bootstrap. Wird NUR für die Gewinner-Version geladen (vom Loader).
 * Registriert den PSR-4-Autoloader für `RhDbEngine\`, definiert die Version
 * und bootet den Singleton.
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    return;
}

(static function (): void {
    $srcDir = __DIR__ . '/src/';

    spl_autoload_register(static function (string $class) use ($srcDir): void {
        $prefix = 'RhDbEngine\\';
        if (! str_starts_with($class, $prefix)) {
            return;
        }

        $relative = substr($class, strlen($prefix));
        $file = $srcDir . str_replace('\\', '/', $relative) . '.php';

        if (is_file($file)) {
            require_once $file;
        }
    });

    if (! defined('RHDBENGINE_VERSION')) {
        define('RHDBENGINE_VERSION', RhDbEngineLoader::winningVersion());
    }

    require_once __DIR__ . '/functions.php';

    \RhDbEngine\DbEngine::boot(
        RhDbEngineLoader::winningVersion(),
        __DIR__
    );
})();
