<?php

/**
 * Standalone-Test für das Archiv, das beim Lesen erst entsteht.
 *   php tests/streaming-zip-test.php
 *
 * Die drei Fragen, an denen alles hängt:
 *
 * 1. Ergibt das Lesen in beliebiger Reihenfolge genau dasselbe wie ein Durchlauf am
 *    Stück? Wäre es das nicht, käme bei Google ein beschädigtes Archiv an, und zwar
 *    unbemerkt, weil erst beim Zurückspielen jemand nachsieht.
 * 2. Ist das Ergebnis ein gültiges ZIP, das sich mit den üblichen Werkzeugen öffnen
 *    lässt und die richtigen Inhalte enthält?
 * 3. Bleibt es gültig, wenn sich eine Datei während des Laufs ändert? Bei zwanzig
 *    Minuten Laufzeit auf einer belebten Seite ist das der Normalfall, nicht die
 *    Ausnahme.
 */

declare(strict_types=1);

require __DIR__ . '/../src/Archive/ArchiveStream.php';
require __DIR__ . '/../src/Archive/ScanCursor.php';
require __DIR__ . '/../src/Archive/ArchiveIndex.php';
require __DIR__ . '/../src/Archive/SiteScanner.php';
require __DIR__ . '/../src/Archive/StreamingZipWriter.php';

use RhDbEngine\Archive\ArchiveIndex;
use RhDbEngine\Archive\ScanCursor;
use RhDbEngine\Archive\SiteScanner;
use RhDbEngine\Archive\StreamingZipWriter;

$failures = 0;
function check(string $label, bool $ok, string $detail = ''): void
{
    global $failures;
    echo ($ok ? '  PASS  ' : '  FAIL  ') . $label . ($ok || $detail === '' ? '' : "  ({$detail})") . "\n";
    if (! $ok) {
        $failures++;
    }
}

$tmp = sys_get_temp_dir() . '/rhdb-zip-' . bin2hex(random_bytes(4));
mkdir($tmp . '/quelle', 0777, true);

// --- Testbaum ---------------------------------------------------------------
// Verschiedene Grössen, damit Abschnittsgrenzen mal mitten in eine Datei und mal genau
// dazwischen fallen. Dazu ein langer Pfad und ein Umlaut im Namen, beides kommt in
// Mediatheken vor.

$dateien = [
    'index.php' => "<?php\n// Start\n",
    'klein.txt' => 'a',
    'leer.txt' => '',
    'mittel.bin' => random_bytes(70000),
    'gross.bin' => random_bytes(300000),
    'unterordner/tief/noch-tiefer/datei.css' => str_repeat("body{margin:0}\n", 400),
    'wp-content/plugins/woocommerce/packages/action-scheduler/classes/abstracts/ActionScheduler_Abstract_QueueRunner.php' => str_repeat('x', 2048),
    'uploads/gruen-strasse-koeln.jpg' => random_bytes(45000),
];

foreach ($dateien as $rel => $inhalt) {
    $pfad = $tmp . '/quelle/' . $rel;
    @mkdir(dirname($pfad), 0777, true);
    file_put_contents($pfad, $inhalt);
}

// --- Durchgang, Prüfsummen, Aufbau -------------------------------------------

$indexPfad = $tmp . '/index.tsv';
$index = new ArchiveIndex($indexPfad, StreamingZipWriter::overhead());
$index->reset();

$scanner = new SiteScanner(null);
$cursor = $scanner->scanStep(ScanCursor::start(['root' => $tmp . '/quelle']), $index, 30.0);
$index->flush();

check('Alle Dateien erfasst', $index->count() === count($dateien), (string) $index->count());

// Die Prüfsummen kommen in einer eigenen Runde, unterbrochen wie im Betrieb.
$runden = 0;
while ($index->crcCount() < $index->count() && $runden < 100) {
    $naechster = $index->at($index->crcCount());
    if ($naechster === null) {
        break;
    }
    $hash = hash_file('crc32b', $naechster['source']);
    $index->appendCrc($hash === false ? 0 : (int) hexdec($hash));
    $runden++;
}
$index->flush();

check('Für jede Datei liegt eine Prüfsumme vor', $index->crcCount() === $index->count(), $index->crcCount() . ' von ' . $index->count());

$writer = new StreamingZipWriter($index, $tmp . '/trailer.bin');
$groesse = $writer->size();
check('Die Grösse steht vorab fest', $groesse > 0, size_format_local($groesse));

// --- 1. Am Stück gegen beliebige Reihenfolge ---------------------------------

echo "\nLesen in beliebiger Reihenfolge\n";

$amStueck = $tmp . '/am-stueck.zip';
$geschrieben = $writer->writeTo($amStueck, 64 * 1024);
check('Am Stück geschrieben ergibt die angekündigte Grösse', $geschrieben === $groesse, $geschrieben . ' gegen ' . $groesse);
check('Die Datei ist auch so gross', (int) filesize($amStueck) === $groesse);

// Jetzt dasselbe Archiv in willkürlicher Reihenfolge zusammensetzen, mit wechselnden
// Abschnittsgrössen. Genau das tut ein Upload, der zwischendurch zurückspringt.
$stuecke = [];
$offset = 0;
$groessen = [8192, 1000, 65536, 333, 16384];
$i = 0;
while ($offset < $groesse) {
    $len = $groessen[$i % count($groessen)];
    $stuecke[] = [$offset, min($len, $groesse - $offset)];
    $offset += $len;
    $i++;
}

// Reihenfolge umdrehen und mischen, damit nichts sequenziell bleibt.
$reihenfolge = array_keys($stuecke);
shuffle($reihenfolge);

$zusammen = str_repeat("\0", $groesse);
foreach ($reihenfolge as $k) {
    [$pos, $len] = $stuecke[$k];
    $daten = $writer->readAt($pos, $len);
    $zusammen = substr_replace($zusammen, $daten, $pos, strlen($daten));
}

check(
    'Beliebige Reihenfolge ergibt Byte für Byte dasselbe',
    $zusammen === file_get_contents($amStueck),
    'Länge ' . strlen($zusammen) . ' gegen ' . filesize($amStueck)
);

// Abschnitte, die über eine Grenze zwischen zwei Dateien reichen. Das ist die Stelle,
// an der ein Rechenfehler im Aufbau zuerst auffällt.
$eintraege = [];
foreach ($index->each() as $e) {
    $eintraege[] = $e;
}

$referenz = (string) file_get_contents($amStueck);
$grenzenOk = true;
$fehlerBei = '';

foreach ($eintraege as $e) {
    foreach ([[$e['start'], 64], [max(0, $e['start'] - 32), 64], [max(0, $e['start'] - 1), 2]] as [$pos, $len]) {
        if ($writer->readAt($pos, $len) !== substr($referenz, $pos, $len)) {
            $grenzenOk = false;
            $fehlerBei = $e['name'] . ' bei ' . $pos;
            break 2;
        }
    }
}

check('Abschnitte über jede Datei-Grenze stimmen', $grenzenOk, $fehlerBei);

// Auch der Übergang von den Dateien zum Verzeichnis am Ende.
$uebergang = $index->archiveOffset();
check(
    'Der Übergang zum Verzeichnis stimmt',
    $writer->readAt(max(0, $uebergang - 50), 100) === substr($referenz, max(0, $uebergang - 50), 100)
);

// --- 2. Ist es ein gültiges Archiv? ------------------------------------------

echo "\nGültigkeit\n";

$zip = new ZipArchive();
$offen = $zip->open($amStueck, ZipArchive::CHECKCONS);
check('Öffnet mit Konsistenzprüfung', $offen === true, 'Fehler ' . (string) $offen);

if ($offen === true) {
    check('Enthält alle Dateien', $zip->numFiles === count($dateien), (string) $zip->numFiles);

    $alleGleich = true;
    $abweichung = '';
    foreach ($dateien as $rel => $inhalt) {
        $ausArchiv = $zip->getFromName('root/' . $rel);
        if ($ausArchiv === false && $inhalt === '') {
            continue;
        }
        if ($ausArchiv !== $inhalt) {
            $alleGleich = false;
            $abweichung = $rel;
            break;
        }
    }
    check('Jeder Inhalt stimmt mit dem Original überein', $alleGleich, $abweichung);
    $zip->close();
}

exec('unzip -t ' . escapeshellarg($amStueck) . ' 2>&1', $ausgabe, $rc);
check('unzip prüft die Prüfsummen erfolgreich', $rc === 0, implode(' ', array_slice($ausgabe, -3)));
unset($ausgabe);

// Auspacken und vergleichen, so wie es jemand von Hand täte.
$ziel = $tmp . '/entpackt';
mkdir($ziel, 0777, true);
exec('unzip -qq ' . escapeshellarg($amStueck) . ' -d ' . escapeshellarg($ziel) . ' 2>&1', $ausgabe, $rc2);

$entpacktGleich = true;
foreach ($dateien as $rel => $inhalt) {
    $pfad = $ziel . '/root/' . $rel;
    if (! is_file($pfad) || file_get_contents($pfad) !== $inhalt) {
        $entpacktGleich = false;
        break;
    }
}
check('Von Hand ausgepackt stimmt alles', $entpacktGleich);

// --- 3. Eine Datei ändert sich mitten im Lauf --------------------------------

echo "\nÄnderung während des Laufs\n";

// Kürzer: der reservierte Platz muss trotzdem gefüllt werden.
file_put_contents($tmp . '/quelle/gross.bin', 'nur noch wenig');
// Länger: es darf nur so viel gelesen werden, wie aufgezeichnet wurde.
file_put_contents($tmp . '/quelle/mittel.bin', random_bytes(200000));
// Weg: der Platz bleibt trotzdem reserviert.
unlink($tmp . '/quelle/klein.txt');

$nachher = $tmp . '/nachher.zip';
$writer2 = new StreamingZipWriter($index, $tmp . '/trailer2.bin');
$geschrieben2 = $writer2->writeTo($nachher, 64 * 1024);

check('Die Grösse bleibt unverändert', $geschrieben2 === $groesse, $geschrieben2 . ' gegen ' . $groesse);

$zip2 = new ZipArchive();
$offen2 = $zip2->open($nachher);
check('Das Archiv lässt sich trotzdem öffnen', $offen2 === true, 'Fehler ' . (string) $offen2);
if ($offen2 === true) {
    check('Die unveränderten Dateien sind unversehrt', $zip2->getFromName('root/index.php') === $dateien['index.php']);
    $zip2->close();
}

$writer->close();
$writer2->close();
$index->close();

// --- Abbau ------------------------------------------------------------------

$rm = static function (string $d) use (&$rm): void {
    foreach ((array) scandir($d) ?: [] as $n) {
        if ($n === '.' || $n === '..') {
            continue;
        }
        $p = $d . '/' . $n;
        is_dir($p) && ! is_link($p) ? $rm($p) : @unlink($p);
    }
    @rmdir($d);
};
$rm($tmp);

echo "\n";
if ($failures === 0) {
    echo "OK, alle Checks bestanden.\n";
    exit(0);
}
echo "FEHLER: {$failures} Check(s) fehlgeschlagen.\n";
exit(1);

function size_format_local(int $bytes): string
{
    return number_format($bytes / 1024, 1, ',', '.') . ' KB';
}
