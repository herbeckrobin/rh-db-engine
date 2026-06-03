<?php

/**
 * Standalone-Test für den db-engine Negotiation-Loader + Singleton.
 *   php tests/negotiation-test.php
 */

declare(strict_types=1);

define('ABSPATH', __DIR__ . '/');

$GLOBALS['__hooks'] = [];

function add_action(string $hook, callable $cb, int $prio = 10, int $args = 1): void
{
    $GLOBALS['__hooks'][$hook][] = $cb;
}

function do_action(string $hook, mixed ...$args): void
{
    foreach ($GLOBALS['__hooks'][$hook] ?? [] as $cb) {
        $cb(...$args);
    }
}

$failures = 0;
function check(string $label, bool $ok): void
{
    global $failures;
    echo ($ok ? '  PASS  ' : '  FAIL  ') . $label . "\n";
    if (! $ok) {
        $failures++;
    }
}

$dir = dirname(__DIR__);
require_once $dir . '/loader/RhDbEngineLoader.php';

check('pickLatest: 1.4.0 schlägt 1.0.0', RhDbEngineLoader::pickLatest(['1.0.0', '1.4.0']) === '1.4.0');
check('pickLatest: 1.10.0 schlägt 1.9.0', RhDbEngineLoader::pickLatest(['1.9.0', '1.10.0']) === '1.10.0');

RhDbEngineLoader::declareVersion('1.0.0', $dir);
RhDbEngineLoader::declareVersion('1.2.0', $dir);
do_action('plugins_loaded');

check('Negotiation: 1.2.0 gewinnt', RhDbEngineLoader::winningVersion() === '1.2.0');
check('DbEngine gebootet', \RhDbEngine\DbEngine::isBooted());
check('rh_db_engine() liefert Singleton', rh_db_engine() instanceof \RhDbEngine\DbEngine);
check('Version 1.2.0', rh_db_engine()->version() === '1.2.0');
check('storage() liefert Storage', rh_db_engine()->storage() instanceof \RhDbEngine\Storage);
check('exporter() liefert Exporter', rh_db_engine()->exporter() instanceof \RhDbEngine\Exporter);
check('importer() liefert Importer', rh_db_engine()->importer() instanceof \RhDbEngine\Importer);
check('RHDBENGINE_VERSION definiert', defined('RHDBENGINE_VERSION') && RHDBENGINE_VERSION === '1.2.0');

echo "\n";
if ($failures === 0) {
    echo "OK, alle Checks bestanden.\n";
    exit(0);
}
echo "FEHLER: {$failures} Check(s) fehlgeschlagen.\n";
exit(1);
