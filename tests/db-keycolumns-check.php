<?php

/**
 * Nachweis: findet der Exporter auch ohne Primärschlüssel eine stabile Ordnung?
 *
 * Der Dump blättert über den Schlüssel statt über eine Sprungmarke, weil Letzteres
 * Zeilen überspringt oder doppelt schreibt, sobald sich die Tabelle während des Laufs
 * ändert. Ein Primärschlüssel ist die erste Wahl, aber Tabellen fremder Plugins haben
 * oft keinen. Dann tut es jeder andere eindeutige Schlüssel, sofern er keine leeren
 * Werte zulässt.
 *
 * Läuft gegen die echte Datenbank der DDEV-Instanz, weil SHOW KEYS sich nicht sinnvoll
 * nachbauen lässt.
 *
 * Aufruf aus Code/: ddev wp eval-file rh-db-engine/tests/db-keycolumns-check.php
 */

global $wpdb;

$exporter = rh_db_engine()->exporter();
$r = new ReflectionClass($exporter);
$m = $r->getMethod('primaryKeyColumns');
$m->setAccessible(true);

$faelle = [
    'rhtest_pk' => [
        'CREATE TABLE %s (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, wert VARCHAR(20), PRIMARY KEY (id))',
        ['id'],
        'Primärschlüssel',
    ],
    'rhtest_composite' => [
        'CREATE TABLE %s (a BIGINT NOT NULL, b BIGINT NOT NULL, wert VARCHAR(20), PRIMARY KEY (a, b))',
        ['a', 'b'],
        'zusammengesetzter Primärschlüssel',
    ],
    'rhtest_unique' => [
        'CREATE TABLE %s (kennung VARCHAR(60) NOT NULL, wert VARCHAR(20), UNIQUE KEY kennung (kennung))',
        ['kennung'],
        'kein Primärschlüssel, aber ein eindeutiger',
    ],
    'rhtest_unique_null' => [
        'CREATE TABLE %s (kennung VARCHAR(60) NULL, wert VARCHAR(20), UNIQUE KEY kennung (kennung))',
        [],
        'eindeutiger Schlüssel, aber nullbar: taugt nicht',
    ],
    'rhtest_index_only' => [
        'CREATE TABLE %s (wert VARCHAR(20) NOT NULL, KEY wert (wert))',
        [],
        'nur ein gewöhnlicher Index: taugt nicht',
    ],
    'rhtest_nothing' => [
        'CREATE TABLE %s (wert VARCHAR(20))',
        [],
        'gar kein Schlüssel',
    ],
    'rhtest_two_unique' => [
        'CREATE TABLE %s (a VARCHAR(20) NOT NULL, b VARCHAR(20) NOT NULL, UNIQUE KEY erste (a), UNIQUE KEY zweite (b))',
        ['a'],
        'zwei eindeutige Schlüssel: der erste gewinnt',
    ],
];

$fehler = 0;

foreach ($faelle as $name => [$sql, $erwartet, $beschreibung]) {
    $tabelle = $wpdb->prefix . $name;
    $wpdb->query("DROP TABLE IF EXISTS `{$tabelle}`");
    $wpdb->query(sprintf($sql, "`{$tabelle}`"));

    $ergebnis = $m->invoke($exporter, $tabelle);
    $ok = $ergebnis === $erwartet;

    printf(
        "  %s  %-34s erwartet [%s], bekommen [%s]%s",
        $ok ? 'PASS' : 'FAIL',
        $beschreibung,
        implode(', ', $erwartet),
        implode(', ', $ergebnis),
        PHP_EOL
    );

    if (! $ok) {
        $fehler++;
    }

    $wpdb->query("DROP TABLE IF EXISTS `{$tabelle}`");
}

printf("%s%s%s", PHP_EOL, $fehler === 0 ? 'OK, alle Faelle richtig.' : "FEHLER: {$fehler} Fall/Faelle falsch.", PHP_EOL);
