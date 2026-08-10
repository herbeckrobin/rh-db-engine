<?php

/**
 * Standalone-Test für den Options-Ausschluss beim Export.
 *   php tests/option-exclusion-test.php
 *
 * Anlass ist der 2026-08-10: ein Pull hat die Kopplung der Zielseite mit der Kopplung der
 * Quelle überschrieben. Danach zeigte der Peer auf die eigene Adresse, der nächste Lauf
 * scheiterte an einem Zertifikat, und der Grund lag drei Ebenen tiefer.
 *
 * Die erste Verteidigungslinie ist deshalb, dass solche Werte gar nicht erst im Archiv
 * landen. Geprüft wird die Erkennung selbst und, dass das Blättern über die Tabelle davon
 * unberührt bleibt: würde der Export gefilterte Zeilen als Tabellen-Ende lesen, bräche der
 * Dump mittendrin ab und niemand merkte es.
 */

declare(strict_types=1);

namespace {
    require_once dirname(__DIR__) . '/src/ExportCursor.php';

    $failures = 0;

    function check(string $label, bool $ok, string $detail = ''): void
    {
        global $failures;
        echo ($ok ? '  PASS  ' : '  FAIL  ') . $label . "\n";
        if (!$ok) {
            $failures++;
            if ($detail !== '') {
                echo '        ' . $detail . "\n";
            }
        }
    }

    $muster = ['rhbp_peers', 'rhbp_sync_*', '_transient_rhbp_sync_*'];
    $raus = static fn (string $name): bool => RhDbEngine\ExportCursor::optionExcluded($name, $muster);

    // ====================================================================
    echo "\nA. Genauer Name gegen Anfang\n";
    // ====================================================================

    check('Der genaue Name greift', $raus('rhbp_peers'));
    check('Der Anfang greift', $raus('rhbp_sync_log'));
    check('Auch für einen langen Namen', $raus('rhbp_sync_job_1fd73d717dbaf4bf60e63668aed524b7'));
    check('Auch für ein Transient', $raus('_transient_rhbp_sync_status'));

    check('Ein anderer Name bleibt', !$raus('rhbp_settings_seo'));
    check('Ein ähnlicher Name bleibt', !$raus('rhbp_peers_backup'));
    check('WordPress-Options bleiben', !$raus('siteurl'));

    // Der Unterstrich ist in SQL-LIKE ein Platzhalter und steckt in jedem WordPress-Namen.
    // Genau deshalb wird hier nicht mit LIKE verglichen.
    check('Der Unterstrich ist kein Platzhalter', !$raus('rhbpXpeers'));

    check('Ohne Muster bleibt alles', !RhDbEngine\ExportCursor::optionExcluded('rhbp_peers', []));
    check('Ein leeres Muster fängt nichts', !RhDbEngine\ExportCursor::optionExcluded('irgendwas', ['']));

    // ====================================================================
    echo "\nB. Der Cursor trägt die Liste über die Ticks\n";
    // ====================================================================

    $cursor = RhDbEngine\ExportCursor::start('/tmp/x', false, ['wp_sessions'], '/tmp/y', $muster);
    check('start() nimmt die Liste an', $cursor->excludedOptions === $muster);

    $wieder = RhDbEngine\ExportCursor::fromArray($cursor->toArray());
    check('Und sie übersteht das Serialisieren', $wieder->excludedOptions === $muster);
    check('Die Tabellen-Ausnahme bleibt daneben bestehen', $wieder->excludedTables === ['wp_sessions']);

    $alt = RhDbEngine\ExportCursor::fromArray(['workdir' => '/tmp/x']);
    check('Ein alter Cursor ohne die Angabe läuft weiter', $alt->excludedOptions === []);

    // ====================================================================
    echo "\nC. Das Blättern zählt geholte Zeilen, nicht geschriebene\n";
    // ====================================================================

    // Nachbildung der Schleife aus dumpTableRowsChunk(): geholt werden $chunk Zeilen,
    // geschrieben nur die erlaubten, und weitergeblättert wird nach der Anzahl der
    // geholten. Andernfalls hielte ein Chunk voller ausgeschlossener Zeilen die Tabelle
    // für zu Ende.
    $zeilen = [];
    for ($i = 1; $i <= 25; $i++) {
        $zeilen[] = $i <= 10 ? 'rhbp_sync_job_' . $i : 'option_' . $i;
    }

    $chunk = 10;
    $geschrieben = [];
    $geholt = 0;
    $offset = 0;

    do {
        $teil = array_slice($zeilen, $offset, $chunk);
        foreach ($teil as $name) {
            if (!$raus($name)) {
                $geschrieben[] = $name;
            }
        }
        $geholt = count($teil);
        $offset += $geholt;
    } while ($geholt === $chunk);

    check('Alle Zeilen wurden angesehen', $offset === 25, (string) $offset);
    check('Und nur die erlaubten geschrieben', count($geschrieben) === 15, (string) count($geschrieben));
    check('Der erste Chunk war komplett ausgeschlossen und hat trotzdem nicht abgebrochen', in_array('option_25', $geschrieben, true));

    // ====================================================================
    echo "\n";
    if ($failures > 0) {
        echo "  {$failures} Prüfung(en) fehlgeschlagen.\n\n";
        exit(1);
    }
    echo "  Alle Prüfungen bestanden.\n\n";
    exit(0);
}
