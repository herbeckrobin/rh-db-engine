<?php

/**
 * Nachweis: verliert der Dump Zeilen, wenn währenddessen gelöscht und eingefügt wird?
 *
 * Läuft gegen die echte Datenbank der DDEV-Instanz. Legt eine Testtabelle an, dumpt sie
 * über mehrere Ticks und verändert sie zwischen zwei Ticks genau so, wie es auf einer
 * lebenden Site passiert. Am Ende wird die Menge der gedumpten Schlüssel mit dem
 * tatsächlichen Tabelleninhalt verglichen.
 *
 * Dazu die Gegenprobe mit dem alten OFFSET-Weg, damit der Unterschied belegt ist und
 * nicht behauptet.
 *
 * Aufruf aus Code/: ddev wp eval-file rh-db-engine/tests/db-keyset-check.php
 */

global $wpdb;

$table = $wpdb->prefix . 'rhtest_keyset';

// Gross genug, damit die SQL-Phase das Zeitbudget eines Ticks überschreitet und mitten
// in der Tabelle unterbricht. Genau dieser Zustand ist der, in dem der Fehler auftritt.
$gesamt = 40000;

function schritt(string $text): void
{
    echo $text . "\n";
}

function pruefe(string $text, bool $ok): void
{
    echo ($ok ? '  PASS  ' : '  FEHL  ') . $text . "\n";
    if (! $ok) {
        $GLOBALS['rhtest_fehler'] = true;
    }
}

// --- Aufbau ---------------------------------------------------------------

$wpdb->query("DROP TABLE IF EXISTS {$table}");
$wpdb->query("CREATE TABLE {$table} (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    nutzlast VARCHAR(64) NOT NULL,
    PRIMARY KEY (id)
) DEFAULT CHARSET=utf8mb4");

$fueller = str_repeat('x', 180);
$werte = [];
for ($i = 1; $i <= $gesamt; $i++) {
    $werte[] = "('zeile-{$i}-{$fueller}')";
}
foreach (array_chunk($werte, 500) as $block) {
    $wpdb->query("INSERT INTO {$table} (nutzlast) VALUES " . implode(',', $block));
}
schritt("Testtabelle {$table} mit {$gesamt} Zeilen angelegt.");

// Alle anderen Tabellen ausschliessen, damit der Lauf kurz bleibt.
$alle = $wpdb->get_col($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($wpdb->prefix) . '%'));
$ausgeschlossen = array_values(array_filter($alle, static fn ($t) => $t !== $table));

// --- Lauf mit dem neuen Weg ----------------------------------------------

$engine = rh_db_engine();
$workdir = $engine->storage()->jobWorkdir('keyset-check-' . wp_generate_password(6, false, false));
$cursor = \RhDbEngine\ExportCursor::start($workdir, false, $ausgeschlossen);

$exporter = $engine->exporter();
$eingegriffen = false;
$runden = 0;

$unterbrochenBei = 0;
$letzterSchluessel = '';

while ($cursor->phase === \RhDbEngine\ExportCursor::PHASE_SQL && $runden < 200) {
    $cursor = $exporter->exportStep($cursor, 0.05);
    $runden++;

    // Beim ersten Halt mitten in der Tabelle eingreifen, genau wie ein Besucher es täte:
    // frühe Zeilen löschen, die schon gedumpt sind, und hinten eine neue anhängen. Damit
    // rutscht alles Folgende nach vorn, und der OFFSET-Weg überspringt Zeilen.
    if (! $eingegriffen && $cursor->rowOffset > 0 && $cursor->phase === \RhDbEngine\ExportCursor::PHASE_SQL) {
        $unterbrochenBei = $cursor->rowOffset;
        $letzterSchluessel = implode(',', (array) $cursor->rowKey);
        $wpdb->query("DELETE FROM {$table} WHERE id IN (3, 7, 11)");
        $wpdb->query("INSERT INTO {$table} (nutzlast) VALUES ('spaet-dazu')");
        $eingegriffen = true;
        schritt("Bei Zeile {$unterbrochenBei} unterbrochen, dort eingegriffen: 3 Zeilen gelöscht, 1 angehängt.");
    }
}

schritt("SQL-Phase nach {$runden} Ticks beendet.");

// Ohne Unterbrechung mitten in der Tabelle prüft der Test gar nichts. Das muss
// auffallen und nicht als grüner Lauf durchgehen.
pruefe('der Lauf wurde mitten in der Tabelle unterbrochen', $eingegriffen && $runden > 1);
if (! $eingegriffen) {
    schritt('  Der Dump lief in einem Zug durch, der geprüfte Fall trat nicht ein.');
}

$dump = (string) file_get_contents((string) $cursor->sqlPath);

preg_match_all("/INSERT INTO `" . preg_quote($table, '/') . "` \(`id`.*?VALUES \('(\d+)'/", $dump, $treffer);
$gedumpt = array_map('intval', $treffer[1]);

// Die Zeilen, die zum Zeitpunkt des Eingriffs schon dagewesen sind und es noch sind.
$vorhanden = array_map('intval', (array) $wpdb->get_col("SELECT id FROM {$table} ORDER BY id"));

// --- Auswertung -----------------------------------------------------------

schritt('');
schritt('Neuer Weg (Blättern über den Primärschlüssel):');
pruefe('keine Zeile doppelt', count($gedumpt) === count(array_unique($gedumpt)));

$fehlen = array_diff($vorhanden, $gedumpt);
$zuviel = array_diff($gedumpt, $vorhanden);

pruefe(
    sprintf(
        'keine überlebende Zeile verloren (fehlen: %d%s)',
        count($fehlen),
        $fehlen === [] ? '' : ': ' . implode(', ', array_slice(array_values($fehlen), 0, 10))
    ),
    $fehlen === []
);
schritt(sprintf('  Unterbrechungspunkt lag bei Zeile %d, letzter Schlüssel davor: %s', $unterbrochenBei, $letzterSchluessel));

// Zeilen, die vor ihrer Löschung schon gedumpt waren, stehen im Dump. Das ist keine
// Unsauberkeit des Blätterns, sondern die Folge davon, dass ein über Minuten laufender
// Dump keinen einheitlichen Zeitpunkt hat. Es dürfen aber nur genau diese drei sein.
pruefe(
    sprintf('nur die vor dem Eingriff gedumpten Zeilen zusätzlich (%s)', $zuviel === [] ? 'keine' : implode(', ', $zuviel)),
    array_diff($zuviel, [3, 7, 11]) === []
);
schritt(sprintf('  gedumpt: %d, in der Tabelle: %d', count($gedumpt), count($vorhanden)));

// --- Gegenprobe: was hätte der alte OFFSET-Weg getan? ---------------------
//
// Nachgestellt ohne den Exporter: erster Abschnitt mit OFFSET 0, dann die drei Zeilen
// löschen, dann OFFSET 500. Genau die Reihenfolge des Laufs oben.

$altTable = $table . '_alt';
$wpdb->query("DROP TABLE IF EXISTS {$altTable}");
$wpdb->query("CREATE TABLE {$altTable} LIKE {$table}");
$wpdb->query("INSERT INTO {$altTable} SELECT * FROM {$table}");

// In der Kopie sind 3, 7 und 11 schon weg. Für die Gegenprobe braucht es drei Zeilen,
// die es noch gibt und die vor dem Unterbrechungspunkt liegen.
$opfer = array_map('intval', (array) $wpdb->get_col("SELECT id FROM {$altTable} ORDER BY id LIMIT 3 OFFSET 5"));

// Gleicher Ablauf wie oben, nur mit dem OFFSET-Weg: bis zum Unterbrechungspunkt lesen,
// dann dieselben drei Zeilen löschen, dann ab diesem Punkt weiterlesen.
$grenze = max(500, $unterbrochenBei);
$ersterAbschnitt = array_map('intval', (array) $wpdb->get_col("SELECT id FROM {$altTable} LIMIT {$grenze} OFFSET 0"));
$wpdb->query("DELETE FROM {$altTable} WHERE id IN (" . implode(',', $opfer) . ')');
$zweiterAbschnitt = array_map('intval', (array) $wpdb->get_col("SELECT id FROM {$altTable} LIMIT 5000 OFFSET {$grenze}"));

$altGedumpt = array_merge($ersterAbschnitt, $zweiterAbschnitt);
$altVorhanden = array_map('intval', (array) $wpdb->get_col("SELECT id FROM {$altTable} ORDER BY id LIMIT " . ($grenze + 5000)));
$altFehlen = array_diff($altVorhanden, $altGedumpt);

schritt('');
schritt('Gegenprobe alter Weg (Blättern über OFFSET, gleiche Eingriffe):');
schritt(sprintf('  übersprungene Zeilen: %d %s', count($altFehlen), count($altFehlen) > 0 ? '(' . implode(', ', array_slice($altFehlen, 0, 5)) . ' ...)' : ''));
pruefe('der alte Weg verliert nachweislich Zeilen', count($altFehlen) > 0);

// --- Sonderfälle: zusammengesetzter Schlüssel und gar keiner --------------
//
// Beide nehmen einen anderen Weg durch den Code. In WordPress trifft der erste Fall
// wp_term_relationships, der zweite kommt bei Plugin-Tabellen vor.

$paar = $wpdb->prefix . 'rhtest_paar';
$ohne = $wpdb->prefix . 'rhtest_ohne';

$wpdb->query("DROP TABLE IF EXISTS {$paar}");
$wpdb->query("CREATE TABLE {$paar} (
    links BIGINT UNSIGNED NOT NULL,
    rechts BIGINT UNSIGNED NOT NULL,
    nutzlast VARCHAR(32) NOT NULL,
    PRIMARY KEY (links, rechts)
) DEFAULT CHARSET=utf8mb4");

$paare = [];
for ($l = 1; $l <= 40; $l++) {
    for ($r = 1; $r <= 30; $r++) {
        $paare[] = "({$l}, {$r}, 'p{$l}-{$r}')";
    }
}
$wpdb->query("INSERT INTO {$paar} (links, rechts, nutzlast) VALUES " . implode(',', $paare));

$wpdb->query("DROP TABLE IF EXISTS {$ohne}");
$wpdb->query("CREATE TABLE {$ohne} (wert VARCHAR(32) NOT NULL) DEFAULT CHARSET=utf8mb4");
$lose = [];
for ($i = 1; $i <= 700; $i++) {
    $lose[] = "('w{$i}')";
}
$wpdb->query("INSERT INTO {$ohne} (wert) VALUES " . implode(',', $lose));

$sonderAusgeschlossen = array_values(array_filter(
    (array) $wpdb->get_col($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($wpdb->prefix) . '%')),
    static fn ($t) => $t !== $paar && $t !== $ohne
));

$workdir2 = $engine->storage()->jobWorkdir('keyset-check2-' . wp_generate_password(6, false, false));
$cursor2 = \RhDbEngine\ExportCursor::start($workdir2, false, $sonderAusgeschlossen);
$runden2 = 0;
while ($cursor2->phase === \RhDbEngine\ExportCursor::PHASE_SQL && $runden2 < 200) {
    $cursor2 = $exporter->exportStep($cursor2, 0.05);
    $runden2++;
}

$dump2 = (string) file_get_contents((string) $cursor2->sqlPath);

schritt('');
schritt('Sonderfälle:');

preg_match_all('/INSERT INTO `' . preg_quote($paar, '/') . '`/', $dump2, $paarTreffer);
pruefe(
    sprintf('zusammengesetzter Schlüssel: alle 1200 Zeilen gedumpt (%d)', count($paarTreffer[0])),
    count($paarTreffer[0]) === 1200
);

preg_match_all('/INSERT INTO `' . preg_quote($ohne, '/') . '`/', $dump2, $ohneTreffer);
pruefe(
    sprintf('ohne Schlüssel: alle 700 Zeilen gedumpt (%d)', count($ohneTreffer[0])),
    count($ohneTreffer[0]) === 700
);
pruefe(
    'ohne Schlüssel: Hinweis steht im Dump',
    str_contains($dump2, 'Tabelle ohne Primärschlüssel')
);

// --- Abbau ----------------------------------------------------------------

$wpdb->query("DROP TABLE IF EXISTS {$table}");
$wpdb->query("DROP TABLE IF EXISTS {$altTable}");
$wpdb->query("DROP TABLE IF EXISTS {$paar}");
$wpdb->query("DROP TABLE IF EXISTS {$ohne}");

$rm = static function (string $dir) use (&$rm): void {
    foreach ((array) glob($dir . '/*') as $f) {
        is_dir($f) ? $rm($f) : @unlink($f);
    }
    @rmdir($dir);
};
$rm($workdir);
$rm($workdir2);

// exportStep arbeitet innerhalb eines Aufrufs mehrere Phasen ab, solange das Budget
// reicht. Der Lauf kommt also bis zum fertigen ZIP, auch wenn die Schleife nur die
// SQL-Phase abwarten wollte. Ohne diesen Schritt sammeln sich Testarchive in backups/.
foreach ([$cursor->zipPath, $cursor2->zipPath] as $zip) {
    if (is_string($zip) && $zip !== '' && is_file($zip)) {
        @unlink($zip);
        schritt('  Testarchiv entfernt: ' . basename($zip));
    }
}

schritt('');
schritt(empty($GLOBALS['rhtest_fehler']) ? 'ALLES GRÜN' : 'FEHLER AUFGETRETEN');
