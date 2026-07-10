<?php

/**
 * Standalone-Test für extractZipSafely(): eine gleichnamige Datei aus den uploads darf die
 * echte Root-manifest.json / db.sql NICHT überschreiben.
 *   php tests/extract-collision-test.php
 *
 * Reproduziert den realen Bug: ein Backup mit uploads/really-simple-ssl/…/manifest.json
 * überschrieb beim flachen Extrahieren die echte manifest.json -> Import brach mit
 * "kein db_prefix" ab, obwohl das Backup intakt war.
 */

declare(strict_types=1);

namespace {
    function trailingslashit(string $s): string
    {
        return rtrim($s, '/\\') . '/';
    }

    require_once dirname(__DIR__) . '/src/Importer.php';

    $failures = 0;
    function check(string $label, bool $ok): void
    {
        global $failures;
        echo ($ok ? '  PASS  ' : '  FAIL  ') . $label . "\n";
        if (! $ok) {
            $failures++;
        }
    }

    $tmp = sys_get_temp_dir() . '/rhdb-extract-' . bin2hex(random_bytes(4));
    mkdir($tmp, 0700, true);

    // Kollisions-ZIP bauen: echte Root-Dateien + eine gleichnamige upload-Datei danach.
    $zipPath = $tmp . '/backup.zip';
    $realManifest = '{"db_prefix":"vlsoa_","plugin_version":"1.1.3"}';
    $realSql = "-- echte db.sql\nCREATE TABLE `vlsoa_posts` (id INT);\n";
    $zip = new ZipArchive();
    $zip->open($zipPath, ZipArchive::CREATE);
    $zip->addFromString('db.sql', $realSql);
    $zip->addFromString('manifest.json', $realManifest);
    // Kollisionen aus den uploads (kommen NACH den Root-Einträgen -> würden flach überschreiben).
    $zip->addFromString('uploads/really-simple-ssl/abc/manifest.json', str_repeat('X', 5000));
    $zip->addFromString('uploads/backups/db.sql', str_repeat('Y', 5000));
    $zip->close();

    $dest = $tmp . '/extracted';
    mkdir($dest, 0700, true);

    // extractZipSafely ist private und nutzt kein $this -> Reflection ohne Konstruktor.
    $ref = new \ReflectionClass(\RhDbEngine\Importer::class);
    $importer = $ref->newInstanceWithoutConstructor();
    $m = $ref->getMethod('extractZipSafely');
    $m->invoke($importer, $zipPath, $dest);

    $extractedManifest = @file_get_contents($dest . '/manifest.json');
    $extractedSql = @file_get_contents($dest . '/db.sql');

    check('manifest.json extrahiert', $extractedManifest !== false);
    check('manifest.json ist die ECHTE (nicht die upload-Kollision)', $extractedManifest === $realManifest);
    check('db_prefix aus extrahiertem manifest lesbar', is_array(json_decode((string) $extractedManifest, true)) && (json_decode((string) $extractedManifest, true)['db_prefix'] ?? '') === 'vlsoa_');
    check('db.sql ist die ECHTE (nicht die upload-Kollision)', $extractedSql === $realSql);
    // Die uploads-Kollisionen dürfen NICHT als Root-Dateien gelandet sein (kein Verzeichnis angelegt).
    check('keine Fremd-Dateien flach im extract-Dir', count(array_diff(scandir($dest) ?: [], ['.', '..', 'db.sql', 'manifest.json'])) === 0);

    // Aufräumen.
    array_map('unlink', glob($dest . '/*') ?: []);
    @rmdir($dest);
    @unlink($zipPath);
    @rmdir($tmp);

    echo "\n" . ($failures === 0 ? "ALLE TESTS GRÜN" : "$failures FEHLER") . "\n";
    exit($failures === 0 ? 0 : 1);
}
