<?php

/**
 * Standalone-Test für Durchgang und Dateiliste.
 *   php tests/scanner-index-test.php
 *
 * Der Testbaum enthält die Fälle, die im Ernstfall Schaden anrichten: einen Symlink der
 * nach aussen zeigt, einen der im Kreis führt, eine Wurzel die unter einer anderen liegt,
 * und Verzeichnisse die ausgeschlossen sein müssen. Dazu die Frage, ob ein unterbrochener
 * Durchgang beim Fortsetzen dasselbe Ergebnis liefert wie ein Durchgang in einem Zug.
 */

declare(strict_types=1);

require __DIR__ . '/../src/Archive/ScanCursor.php';
require __DIR__ . '/../src/Archive/ArchiveIndex.php';
require __DIR__ . '/../src/Archive/SiteScanner.php';

use RhDbEngine\Archive\ArchiveIndex;
use RhDbEngine\Archive\ScanCursor;
use RhDbEngine\Archive\SiteScanner;

$failures = 0;
function check(string $label, bool $ok, string $detail = ''): void
{
    global $failures;
    echo ($ok ? '  PASS  ' : '  FAIL  ') . $label . ($ok || $detail === '' ? '' : "  ({$detail})") . "\n";
    if (! $ok) {
        $failures++;
    }
}

$tmp = sys_get_temp_dir() . '/rhdb-scan-' . bin2hex(random_bytes(4));

// --- Testbaum ---------------------------------------------------------------

$dateien = [
    'index.php' => "<?php\n",
    'wp-config.php' => "<?php // geheim\n",
    'wp-admin/admin.php' => str_repeat('a', 500),
    'wp-includes/functions.php' => str_repeat('b', 1200),
    'wp-content/themes/mein-theme/style.css' => str_repeat('c', 300),
    'wp-content/plugins/shop/shop.php' => str_repeat('d', 800),
    // Muss draussen bleiben: der eigene Datenordner, sonst sichert sich das Backup selbst.
    'wp-content/rh-blueprint-data/backups/alt.zip' => str_repeat('z', 5000),
    'wp-content/cache/seite.html' => str_repeat('x', 400),
    'wp-content/uploads/2026/bild.jpg' => str_repeat('e', 2000),
    'wp-content/uploads/cache/thumb.jpg' => str_repeat('y', 100),
    'wp-content/debug.log' => str_repeat('l', 999),
    'wp-content/plugins/shop/node_modules/paket/index.js' => str_repeat('n', 50),
    'wp-content/plugins/shop/.git/config' => "[core]\n",
];

foreach ($dateien as $rel => $inhalt) {
    $pfad = $tmp . '/' . $rel;
    @mkdir(dirname($pfad), 0777, true);
    file_put_contents($pfad, $inhalt);
}

// Etwas ausserhalb des Baums, das nicht hineingeraten darf.
mkdir($tmp . '-fremd', 0777, true);
file_put_contents($tmp . '-fremd/passwoerter.txt', str_repeat('p', 42));

$symlinks = 0;
if (@symlink($tmp . '-fremd', $tmp . '/wp-content/nach-aussen')) {
    $symlinks++;
}
if (@symlink($tmp, $tmp . '/wp-content/im-kreis')) {
    $symlinks++;
}

// --- Ausschlussregeln, wie rh-backup sie stellt ------------------------------

$ausgeschlosseneOrdner = [
    'root' => ['wp-content/rh-blueprint-data', 'wp-content/cache', 'wp-content/uploads/cache'],
];
$ueberall = ['node_modules', '.git', '.DS_Store'];
$endungen = ['log'];

$exclude = static function (string $relativ, string $rootName, bool $istVerzeichnis) use ($ausgeschlosseneOrdner, $ueberall, $endungen): bool {
    if (in_array(basename($relativ), $ueberall, true)) {
        return true;
    }
    if (! $istVerzeichnis) {
        $endung = strtolower((string) pathinfo($relativ, PATHINFO_EXTENSION));
        if ($endung !== '' && in_array($endung, $endungen, true)) {
            return true;
        }
    }
    foreach ($ausgeschlosseneOrdner[$rootName] ?? [] as $aus) {
        if ($relativ === $aus || str_starts_with($relativ, $aus . '/')) {
            return true;
        }
    }

    return false;
};

// --- Durchgang in einem Zug --------------------------------------------------

echo "Durchgang\n";

$index = new ArchiveIndex($tmp . '-index.tsv');
$index->reset();

$scanner = new SiteScanner($exclude);
$cursor = ScanCursor::start(['root' => $tmp]);
$cursor = $scanner->scanStep($cursor, $index, 30.0);
$index->flush();

check('Der Durchgang ist fertig', $cursor->done);

$namen = [];
foreach ($index->each() as $eintrag) {
    $namen[] = $eintrag['name'];
}
sort($namen);

$erwartet = [
    'root/index.php',
    'root/wp-admin/admin.php',
    'root/wp-config.php',
    'root/wp-content/plugins/shop/shop.php',
    'root/wp-content/themes/mein-theme/style.css',
    'root/wp-content/uploads/2026/bild.jpg',
    'root/wp-includes/functions.php',
];

check('Genau die erwarteten Dateien', $namen === $erwartet, implode(', ', array_diff($namen, $erwartet)) . ' / fehlt: ' . implode(', ', array_diff($erwartet, $namen)));
check('Anzahl stimmt', $index->count() === count($erwartet), (string) $index->count());

echo "\nAusschlüsse\n";

$alsText = implode("\n", $namen);
check('Der eigene Datenordner ist draussen', ! str_contains($alsText, 'rh-blueprint-data'));
check('Zwischenspeicher ist draussen', ! str_contains($alsText, '/cache/'));
check('Protokolle sind draussen', ! str_contains($alsText, '.log'));
check('node_modules ist draussen', ! str_contains($alsText, 'node_modules'));
check('.git ist draussen', ! str_contains($alsText, '.git'));

if ($symlinks === 2) {
    check('Dem Symlink nach aussen wird nicht gefolgt', ! str_contains($alsText, 'passwoerter'));
    check('Der Symlink im Kreis führt nicht zur Endlosschleife', $cursor->done);
    check('Übersprungenes wird gezählt', $cursor->skipped > 0, (string) $cursor->skipped);
} else {
    echo "  ----  Symlink-Prüfungen übersprungen (nicht anlegbar)\n";
}

echo "\nUnterbrechen und fortsetzen\n";

// Derselbe Baum, aber mit einem Budget, das nicht reicht. Das Ergebnis muss identisch
// sein: ein über viele Requests laufender Durchgang darf nichts verlieren und nichts
// doppelt aufnehmen.
$index2 = new ArchiveIndex($tmp . '-index2.tsv');
$index2->reset();

$cursor2 = ScanCursor::start(['root' => $tmp]);
$runden = 0;
while (! $cursor2->done && $runden < 200) {
    // Zwischendurch durch die Serialisierung, wie es im Betrieb passiert.
    $cursor2 = ScanCursor::fromArray($cursor2->toArray());
    $cursor2 = $scanner->scanStep($cursor2, $index2, 0.0001);
    $runden++;
}
$index2->flush();

$namen2 = [];
foreach ($index2->each() as $eintrag) {
    $namen2[] = $eintrag['name'];
}
sort($namen2);

check('Auch unterbrochen fertig geworden', $cursor2->done, "nach {$runden} Runden");
check('Gleiches Ergebnis wie in einem Zug', $namen2 === $namen, implode(', ', array_merge(array_diff($namen2, $namen), array_diff($namen, $namen2))));
check('Keine Datei doppelt', count($namen2) === count(array_unique($namen2)));

echo "\nSuche über die Byte-Position\n";

// Mit einem Kopfsatz je Eintrag, wie ihn ein Archivformat braucht.
$index3 = new ArchiveIndex($tmp . '-index3.tsv', static fn (string $name): int => 30 + strlen($name));
$index3->reset();
$c3 = $scanner->scanStep(ScanCursor::start(['root' => $tmp]), $index3, 30.0);
$index3->flush();

$eintraege = [];
foreach ($index3->each() as $e) {
    $eintraege[] = $e;
}

// Der gespeicherte Anfang ist der des ganzen Eintrags, also einschliesslich Kopfsatz.
check('Der erste Eintrag beginnt bei null', ($eintraege[0]['start'] ?? -1) === 0, (string) ($eintraege[0]['start'] ?? -1));

$kopf = static fn (string $name): int => 30 + strlen($name);

$lueckenlos = true;
for ($i = 1; $i < count($eintraege); $i++) {
    $vorher = $eintraege[$i - 1];
    $erwarteterStart = $vorher['start'] + $kopf($vorher['name']) + $vorher['size'];
    if ($eintraege[$i]['start'] !== $erwarteterStart) {
        $lueckenlos = false;
        break;
    }
}
check('Die Einträge liegen lückenlos hintereinander', $lueckenlos);

// Jede Byte-Position muss zum richtigen Eintrag führen, auch die im Kopfsatz.
$treffer = true;
foreach ($eintraege as $i => $e) {
    $daten = $e['start'] + $kopf($e['name']);
    foreach ([$e['start'], $daten, $daten + intdiv($e['size'], 2), $daten + $e['size'] - 1] as $pos) {
        $gefunden = $index3->locate($pos);
        if ($gefunden === null || $gefunden['name'] !== $e['name']) {
            $treffer = false;
            echo '        Position ' . $pos . ' fand ' . ($gefunden['name'] ?? 'nichts') . ' statt ' . $e['name'] . "\n";
            break 2;
        }
    }
}
check('Jede Position führt zum richtigen Eintrag', $treffer);
check('Vor dem Anfang wird nichts gefunden', $index3->locate(-1) === null);
check('Die Gesamtgrösse zählt nur die Inhalte', $index3->bytes() === array_sum(array_column($eintraege, 'size')));

echo "\nVerschachtelte Wurzeln\n";

$roots = SiteScanner::dropNested([
    'root' => $tmp,
    'content' => $tmp . '/wp-content',
    'plugins' => $tmp . '/wp-content/plugins',
    'fehlt' => $tmp . '/gibtsnicht',
]);

check('Nur die oberste Wurzel bleibt', array_keys($roots) === ['root'], implode(', ', array_keys($roots)));

$getrennt = SiteScanner::dropNested([
    'root' => $tmp,
    'content' => $tmp . '-fremd',
]);
check('Wurzeln nebeneinander bleiben beide', count($getrennt) === 2, implode(', ', array_keys($getrennt)));

// --- Abbau ------------------------------------------------------------------

$index->close();
$index2->close();
$index3->close();

$rm = static function (string $d) use (&$rm): void {
    foreach ((array) scandir($d) ?: [] as $n) {
        if ($n === '.' || $n === '..') {
            continue;
        }
        $p = $d . '/' . $n;
        if (is_link($p)) {
            @unlink($p);
        } elseif (is_dir($p)) {
            $rm($p);
        } else {
            @unlink($p);
        }
    }
    @rmdir($d);
};
$rm($tmp);
$rm($tmp . '-fremd');
foreach (['-index.tsv', '-index.tsv.seek', '-index2.tsv', '-index2.tsv.seek', '-index3.tsv', '-index3.tsv.seek'] as $suffix) {
    @unlink($tmp . $suffix);
}

echo "\n";
if ($failures === 0) {
    echo "OK, alle Checks bestanden.\n";
    exit(0);
}
echo "FEHLER: {$failures} Check(s) fehlgeschlagen.\n";
exit(1);
