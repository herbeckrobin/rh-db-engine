<?php

/**
 * Standalone-Test für den Lesezugriff auf ein Archiv.
 *   php tests/archive-stream-test.php
 *
 * Geprüft wird genau das, was der Upload tut: an einer beliebigen Stelle lesen, in
 * Abschnitten vorrücken, am Ende weniger bekommen als angefragt. Dazu die Fälle, die
 * einen laufenden Upload abbrechen lassen: die Datei verschwindet mitten im Lauf, oder
 * es wird hinter dem Ende gelesen.
 *
 * Der Zusammenbau aller Abschnitte muss Byte für Byte die Ausgangsdatei ergeben. Wäre
 * das nicht so, käme bei Google ein beschädigtes Archiv an, und niemand würde es merken,
 * bis jemand es zurückspielen will.
 */

declare(strict_types=1);

require __DIR__ . '/../src/Archive/ArchiveStream.php';
require __DIR__ . '/../src/Archive/FileArchiveStream.php';

use RhDbEngine\Archive\FileArchiveStream;

$failures = 0;
function check(string $label, bool $ok, string $detail = ''): void
{
    global $failures;
    echo ($ok ? '  PASS  ' : '  FAIL  ') . $label . ($ok || $detail === '' ? '' : "  ({$detail})") . "\n";
    if (! $ok) {
        $failures++;
    }
}

$tmp = sys_get_temp_dir() . '/rhdb-stream-' . bin2hex(random_bytes(4));
mkdir($tmp, 0777, true);

// Nicht rund durch die Abschnittsgrösse teilbar, damit der letzte Abschnitt kürzer ist.
$inhalt = random_bytes(1000 * 1024 + 137);
$datei = $tmp . '/archiv.zip';
file_put_contents($datei, $inhalt);

echo "Lesen\n";

$stream = new FileArchiveStream($datei);
check('Grösse stimmt', $stream->size() === strlen($inhalt), (string) $stream->size());
check('Grösse bleibt gleich', $stream->size() === strlen($inhalt));

check('Anfang stimmt', $stream->readAt(0, 16) === substr($inhalt, 0, 16));
check('Mitte stimmt', $stream->readAt(500000, 64) === substr($inhalt, 500000, 64));
check('Rückwärts springen geht', $stream->readAt(10, 8) === substr($inhalt, 10, 8));

$rest = $stream->readAt(strlen($inhalt) - 5, 4096);
check('Am Ende kommt nur der Rest', $rest === substr($inhalt, -5), (string) strlen($rest));
check('Hinter dem Ende kommt nichts', $stream->readAt(strlen($inhalt) + 100, 64) === '');
check('Nulllänge liefert nichts', $stream->readAt(0, 0) === '');

echo "\nAbschnittsweise, wie beim Upload\n";

// Genau der Ablauf des Uploads: Abschnitt lesen, Position vorrücken, bis nichts mehr da
// ist. Die Abschnittsgrösse wechselt unterwegs, weil sie sich bei Fehlern halbiert.
$zusammen = '';
$offset = 0;
$groessen = [256 * 1024, 256 * 1024, 128 * 1024, 64 * 1024];
$i = 0;

while ($offset < $stream->size()) {
    $len = $groessen[$i % count($groessen)];
    $chunk = $stream->readAt($offset, $len);
    if ($chunk === '') {
        break;
    }
    $zusammen .= $chunk;
    $offset += strlen($chunk);
    $i++;
}

check('Alle Abschnitte ergeben die Ausgangsdatei', $zusammen === $inhalt, sprintf(
    '%d gegen %d Byte',
    strlen($zusammen),
    strlen($inhalt)
));

$stream->close();
check('Danach lässt sich erneut lesen', (new FileArchiveStream($datei))->readAt(0, 4) === substr($inhalt, 0, 4));

echo "\nFehlerfälle\n";

$weg = new FileArchiveStream($tmp . '/gibtsnicht.zip');
$gemeldet = false;
try {
    $weg->size();
} catch (\RuntimeException $e) {
    $gemeldet = true;
}
check('Fehlende Datei wird gemeldet', $gemeldet);

// Der Fall, der einen laufenden Upload trifft: das Archiv verschwindet, während schon
// gelesen wird. Etwa weil jemand aufgeräumt hat.
$verschwindet = new FileArchiveStream($datei);
$verschwindet->size();
$verschwindet->close();
unlink($datei);

$gemeldet2 = false;
try {
    $verschwindet->readAt(0, 16);
} catch (\RuntimeException $e) {
    $gemeldet2 = true;
}
check('Verschwundene Datei bricht sauber ab', $gemeldet2);

// --- Abbau ----------------------------------------------------------------

foreach ((array) glob($tmp . '/*') as $f) {
    @unlink($f);
}
@rmdir($tmp);

echo "\n";
if ($failures === 0) {
    echo "OK, alle Checks bestanden.\n";
    exit(0);
}
echo "FEHLER: {$failures} Check(s) fehlgeschlagen.\n";
exit(1);
