<?php

/**
 * Standalone-Test für Unterordner im Backup-Verzeichnis.
 *   php tests/storage-subdir-test.php
 *
 * Zwei Fragen: findet die Auflistung Archive in Unterordnern, und lässt sich über den
 * jetzt erlaubten Schrägstrich aus dem Backup-Ordner ausbrechen? Der zweite Teil ist der
 * wichtigere: resolveInside() ist die einzige Stelle, die einen vom Nutzer kommenden
 * Dateinamen in einen echten Pfad übersetzt, und sie liegt vor dem Herunterladen,
 * Wiederherstellen und Löschen.
 */

declare(strict_types=1);

$tmp = sys_get_temp_dir() . '/rhdb-subdir-' . bin2hex(random_bytes(4));
mkdir($tmp . '/wp-content', 0777, true);

define('ABSPATH', $tmp . '/');
define('WP_CONTENT_DIR', $tmp . '/wp-content');

function trailingslashit(string $s): string
{
    return rtrim($s, '/\\') . '/';
}
function wp_mkdir_p(string $dir): bool
{
    return is_dir($dir) || mkdir($dir, 0777, true);
}
function wp_generate_password(int $len = 12, bool $special = true, bool $extra = false): string
{
    return substr(bin2hex(random_bytes($len)), 0, $len);
}

require __DIR__ . '/../src/Storage.php';

use RhDbEngine\Storage;

$failures = 0;
function check(string $label, bool $ok, string $detail = ''): void
{
    global $failures;
    echo ($ok ? '  PASS  ' : '  FAIL  ') . $label . ($ok || $detail === '' ? '' : "  ({$detail})") . "\n";
    if (! $ok) {
        $failures++;
    }
}

$storage = new Storage();
$storage->ensureReady();
$backups = $storage->backupsPath();

// --- Aufbau: je ein Archiv flach und in zwei Unterordnern -------------------

file_put_contents($backups . '/alt-flach.zip', 'x');
touch($backups . '/alt-flach.zip', time() - 300);

$auto = $storage->backupsSubPath('automatic');
file_put_contents($auto . '/backup-neu.zip', 'x');
touch($auto . '/backup-neu.zip', time() - 100);

$presync = $storage->backupsSubPath('presync');
file_put_contents($presync . '/vor-dem-sync.zip', 'x');
touch($presync . '/vor-dem-sync.zip', time() - 200);

echo "Auflistung\n";

$alle = $storage->listBackups();
check('Alle drei Archive gefunden', count($alle) === 3, implode(', ', $alle));
check('Flaches Archiv mit blossem Namen', in_array('alt-flach.zip', $alle, true));
check('Unterordner als relativer Pfad', in_array('automatic/backup-neu.zip', $alle, true));
check('Neueste zuerst', ($alle[0] ?? '') === 'automatic/backup-neu.zip', (string) ($alle[0] ?? ''));

$nurFlach = $storage->listBackupsIn($backups);
check('Ohne Unterordner nur das flache', $nurFlach === ['alt-flach.zip'], implode(', ', $nurFlach));

check('Unterordner ist abgeschirmt', is_file($auto . '/.htaccess') && is_file($auto . '/index.php'));

echo "\nAuflösung\n";

check('Blosser Name geht', $storage->resolveInside($backups, 'alt-flach.zip') !== null);
check('Name mit Ordner geht', $storage->resolveInside($backups, 'automatic/backup-neu.zip') !== null);
check(
    'Aufgelöster Pfad zeigt wirklich dorthin',
    $storage->resolveInside($backups, 'automatic/backup-neu.zip') === realpath($auto . '/backup-neu.zip')
);

echo "\nAusbruchsversuche\n";

// Eine Datei ausserhalb des Backup-Ordners, die es zu schützen gilt.
file_put_contents($tmp . '/wp-content/geheim.txt', 'geht niemanden etwas an');
file_put_contents(dirname($backups) . '/nebenan.zip', 'x');

$versuche = [
    '../nebenan.zip',
    '../../geheim.txt',
    'automatic/../../nebenan.zip',
    './../nebenan.zip',
    'automatic/./../../nebenan.zip',
    '/etc/passwd',
    'automatic/subsub/tiefer.zip',
    'a/b/c/d.zip',
    '',
    '.',
    '..',
];

foreach ($versuche as $v) {
    check(
        'Abgewiesen: ' . ($v === '' ? '(leer)' : $v),
        $storage->resolveInside($backups, $v) === null,
        (string) $storage->resolveInside($backups, $v)
    );
}

// Ein Symlink, der aus dem Backup-Ordner herauszeigt. realpath löst ihn auf, danach
// greift die Verankerung. Ohne diese Prüfung wäre der Schrägstrich ein Einfallstor.
if (@symlink($tmp . '/wp-content/geheim.txt', $auto . '/getarnt.zip')) {
    check(
        'Symlink nach aussen wird abgewiesen',
        $storage->resolveInside($backups, 'automatic/getarnt.zip') === null,
        (string) $storage->resolveInside($backups, 'automatic/getarnt.zip')
    );
} else {
    echo "  ----  Symlink-Test übersprungen (nicht anlegbar)\n";
}

// --- Abbau ----------------------------------------------------------------

$rm = static function (string $d) use (&$rm): void {
    foreach ((array) glob($d . '/{,.}*', GLOB_BRACE) as $f) {
        if (basename($f) === '.' || basename($f) === '..') {
            continue;
        }
        is_dir($f) && ! is_link($f) ? $rm($f) : @unlink($f);
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
