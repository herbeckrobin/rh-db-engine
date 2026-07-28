<?php

/**
 * Standalone-Test für den Lauf-Lock.
 *   php tests/job-lock-test.php
 *
 * Ohne Lock starten zwei Klicks zwei parallele Wiederherstellungen auf derselben
 * Datenbank, und ein Export während eines Imports schreibt ein Backup aus einem halb
 * ersetzten Zustand, das gültig aussieht.
 */

declare(strict_types=1);

namespace {
    $GLOBALS['__options'] = [];

    function get_option(string $name, mixed $default = false): mixed
    {
        // Stellt einen Wettlauf nach: beim Nachlesen steht die Kennung eines anderen
        // Prozesses in der Option, so als wäre der schneller gewesen.
        if (($GLOBALS['__fremder_owner'] ?? '') === $name) {
            return ['owner' => 'jemand-anderes', 'expires' => time() + 60];
        }

        return $GLOBALS['__options'][$name] ?? $default;
    }

    function add_option(string $name, mixed $value, string $deprecated = '', string $autoload = 'yes'): bool
    {
        if (array_key_exists($name, $GLOBALS['__options'])) {
            return false;
        }
        $GLOBALS['__options'][$name] = $value;

        return true;
    }

    function update_option(string $name, mixed $value, mixed $autoload = null): bool
    {
        $GLOBALS['__options'][$name] = $value;

        return true;
    }

    function delete_option(string $name): bool
    {
        unset($GLOBALS['__options'][$name]);

        return true;
    }

    function get_current_user_id(): int
    {
        return 1;
    }

    require_once dirname(__DIR__) . '/src/JobLock.php';

    $failures = 0;
    function check(string $label, bool $ok, string $detail = ''): void
    {
        global $failures;
        echo ($ok ? '  PASS  ' : '  FAIL  ') . $label . ($ok || $detail === '' ? '' : "  ({$detail})") . "\n";
        if (! $ok) {
            $failures++;
        }
    }

    use RhDbEngine\JobLock;

    // --- Grundverhalten --------------------------------------------------------

    check('Erster Lauf bekommt den Lock', JobLock::acquire('db') === true);
    check('Zweiter Lauf wird abgewiesen', JobLock::acquire('db') === false);
    check('Lock ist als gehalten sichtbar', JobLock::heldUntil('db') !== null);

    JobLock::release('db');
    check('Nach der Freigabe ist kein Lock mehr gehalten', JobLock::heldUntil('db') === null);
    check('Nach der Freigabe wieder übernehmbar', JobLock::acquire('db') === true);
    JobLock::release('db');

    // --- Getrennte Läufe blockieren sich nicht gegenseitig ---------------------

    check('Lock A übernommen', JobLock::acquire('db') === true);
    check('Anderer Lock-Name bleibt frei', JobLock::acquire('dateien') === true);
    JobLock::release('db');
    JobLock::release('dateien');

    // --- Abgelaufener Lock blockiert nicht dauerhaft ---------------------------

    check('Lock mit kurzer Laufzeit übernommen', JobLock::acquire('kurz', 1) === true);
    $GLOBALS['__options']['rhdbe_lock_kurz']['expires'] = time() - 1; // Ablauf vorziehen
    check('Abgelaufener Lock gilt nicht mehr als gehalten', JobLock::heldUntil('kurz') === null);
    check('Abgelaufener Lock wird übernommen', JobLock::acquire('kurz') === true);
    JobLock::release('kurz');

    // --- Verlängern ------------------------------------------------------------

    JobLock::acquire('lang', 60);
    $before = JobLock::heldUntil('lang');
    JobLock::refresh('lang', 3600);
    $after = JobLock::heldUntil('lang');
    check('Verlängern schiebt die Ablaufzeit nach hinten', $before !== null && $after !== null && $after > $before);
    JobLock::release('lang');

    // --- Wettlauf: ein anderer Prozess war schneller --------------------------

    // add_option prüft vorab über den Options-Cache, ob es die Option schon gibt, und
    // das ist nicht atomar. Zwei Prozesse im selben Augenblick bekommen sonst beide ein
    // true. Hier wird genau das nachgestellt: das Anlegen gelingt scheinbar, aber beim
    // Nachlesen steht die Kennung eines anderen in der Option.
    $GLOBALS['__fremder_owner'] = 'rhdbe_lock_wettlauf';
    check(
        'Wer den Wettlauf verliert, bekommt den Lock nicht',
        JobLock::acquire('wettlauf') === false
    );
    unset($GLOBALS['__fremder_owner']);
    delete_option('rhdbe_lock_wettlauf');

    check('Ohne Wettlauf wird derselbe Lock übernommen', JobLock::acquire('wettlauf') === true);
    JobLock::release('wettlauf');

    // --- Ungültige Zeichen im Namen -------------------------------------------

    JobLock::acquire('db/../evil');
    check(
        'Lock-Name wird auf harmlose Zeichen reduziert',
        ! isset($GLOBALS['__options']['rhdbe_lock_db/../evil']),
        implode(', ', array_keys($GLOBALS['__options']))
    );
    JobLock::release('db/../evil');

    echo "\n";
    if ($failures === 0) {
        echo "OK, alle Checks bestanden.\n";
        exit(0);
    }

    echo "FEHLER: {$failures} Check(s) fehlgeschlagen.\n";
    exit(1);
}
