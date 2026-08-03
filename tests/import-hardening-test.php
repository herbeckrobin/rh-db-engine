<?php

/**
 * Standalone-Test für die Härtung des Imports.
 *   php tests/import-hardening-test.php
 *
 * Ein Backup-ZIP ist kein vertrauenswürdiger Input: beim Pull lädt rh-sync es von einem
 * entfernten Peer herunter, ohne Signatur und ohne Herkunftsprüfung des Inhalts. Geprüft wird:
 *
 *   1. Statement-Allowlist: alles, was der eigene Exporter nicht erzeugt, wird abgelehnt
 *      (früher fail-open, damit lief beliebiges SQL aus einem manipulierten Dump durch).
 *   2. Tabellen-Bindung: Statements müssen auf den Ziel-Prefix zielen.
 *   3. Uploads: keine ausführbaren Dateien im öffentlich erreichbaren Uploads-Verzeichnis.
 *   4. Zip-Bombe: unplausibles Kompressionsverhältnis wird abgelehnt.
 */

declare(strict_types=1);

namespace {
    // Storage::basePath() hängt daran (Plattenplatz-Prüfung in assertArchiveSane).
    define('WP_CONTENT_DIR', sys_get_temp_dir());

    function trailingslashit(string $s): string
    {
        return rtrim($s, '/\\') . '/';
    }

    function size_format(float $bytes, int $decimals = 0): string
    {
        return number_format($bytes / 1048576, $decimals) . ' MB';
    }

    final class FakeWpdb
    {
        /** @var array<int, string> */
        public array $executed = [];

        public string $last_error = '';

        public string $prefix = 'wp_';

        public function query(string $sql): int
        {
            $this->executed[] = $sql;

            return 1;
        }
    }

    require_once dirname(__DIR__) . '/src/ImportCursor.php';
    require_once dirname(__DIR__) . '/src/Storage.php';
    require_once dirname(__DIR__) . '/src/SearchReplace.php';
    require_once dirname(__DIR__) . '/src/ForeignKeys.php';
    require_once dirname(__DIR__) . '/src/SwapUnavailable.php';
    require_once dirname(__DIR__) . '/src/TableSwap.php';
    require_once dirname(__DIR__) . '/src/Importer.php';

    $failures = 0;
    function check(string $label, bool $ok, string $detail = ''): void
    {
        global $failures;
        echo ($ok ? '  PASS  ' : '  FAIL  ') . $label . ($ok || $detail === '' ? '' : "  ({$detail})") . "\n";
        if (! $ok) {
            $failures++;
        }
    }

    $importer = new \RhDbEngine\Importer(new \RhDbEngine\Storage(), new \RhDbEngine\SearchReplace());
    $workdir = sys_get_temp_dir() . '/rh-hardening-' . bin2hex(random_bytes(4));
    mkdir($workdir . '/extracted', 0777, true);

    $stepSql = new ReflectionMethod(\RhDbEngine\Importer::class, 'stepSql');
    $isExecutable = new ReflectionMethod(\RhDbEngine\Importer::class, 'isExecutablePath');
    $assertSane = new ReflectionMethod(\RhDbEngine\Importer::class, 'assertArchiveSane');

    /** @return array{0: array<int, string>, 1: ?string} */
    function runSql(string $dump, string $targetPrefix = 'wp_'): array
    {
        global $workdir, $importer, $stepSql;

        file_put_contents($workdir . '/extracted/db.sql', $dump);
        $GLOBALS['wpdb'] = new FakeWpdb();

        $cursor = \RhDbEngine\ImportCursor::start($workdir . '/x.zip', $workdir);
        $cursor->phase = \RhDbEngine\ImportCursor::PHASE_SQL;
        $cursor->sourcePrefix = $targetPrefix;
        $cursor->targetPrefix = $targetPrefix;

        $error = null;
        try {
            $stepSql->invoke($importer, $cursor, null, microtime(true) + 30);
        } catch (\RuntimeException $e) {
            $error = $e->getMessage();
        }

        return [$GLOBALS['wpdb']->executed, $error];
    }

    // --- 1. Statements, die der eigene Exporter erzeugt, laufen durch ----------

    $legit = "SET NAMES utf8mb4;\n"
        . "SET FOREIGN_KEY_CHECKS=0;\n"
        . "DROP TABLE IF EXISTS `wp_posts`;\n"
        . "CREATE TABLE `wp_posts` (`ID` bigint(20) NOT NULL);\n"
        . "INSERT INTO `wp_posts` (`ID`) VALUES ('1');\n";
    [$stmts, $err] = runSql($legit);
    check('Regulärer Dump läuft vollständig durch', $err === null && count($stmts) === 5, (string) $err);

    // --- 2. Fremde Statement-Typen werden abgelehnt ----------------------------

    $attacks = [
        'UPDATE auf wp_users' => "UPDATE `wp_users` SET `user_pass` = 'x' WHERE `ID` = 1;\n",
        'GRANT' => "GRANT ALL PRIVILEGES ON *.* TO 'angreifer'@'%';\n",
        'SELECT INTO OUTFILE' => "SELECT '<?php system(\$_GET[0]); ?>' INTO OUTFILE '/var/www/html/wp-content/uploads/s.php';\n",
        'DELETE' => "DELETE FROM `wp_posts`;\n",
        'ALTER TABLE' => "ALTER TABLE `wp_users` ADD `backdoor` int;\n",
        'CREATE TRIGGER' => "CREATE TRIGGER `t` AFTER INSERT ON `wp_posts` FOR EACH ROW BEGIN END;\n",
        'LOAD DATA INFILE' => "LOAD DATA INFILE '/etc/passwd' INTO TABLE `wp_posts`;\n",
        'REPLACE INTO' => "REPLACE INTO `wp_options` VALUES ('siteurl','http://evil.tld');\n",
    ];
    foreach ($attacks as $label => $sql) {
        [$stmts, $err] = runSql($sql);
        check(
            "Abgelehnt: {$label}",
            $err !== null && $stmts === [],
            $err === null ? 'wurde AUSGEFÜHRT' : 'ausgeführt: ' . count($stmts)
        );
    }

    // --- 3. Statements ausserhalb des Ziel-Prefixes werden abgelehnt ----------

    [$stmts, $err] = runSql("INSERT INTO `fremde_tabelle` (`ID`) VALUES ('1');\n");
    check('Abgelehnt: Tabelle ohne Ziel-Prefix', $err !== null && $stmts === [], (string) $err);

    [$stmts, $err] = runSql("DROP TABLE IF EXISTS `mysql`;\n");
    check('Abgelehnt: DROP auf systemfremde Tabelle', $err !== null && $stmts === [], (string) $err);

    // --- 4. Ausführbare Dateien im Uploads-Teil --------------------------------

    $blocked = [
        'shell.php', 'bild.php.jpg', 'x.phtml', 'y.phar', '2026/07/eval.php5',
        '.htaccess', '.user.ini', 'web.config', 'script.cgi', 'a/b/tool.py',
    ];
    foreach ($blocked as $path) {
        check("Uploads blockiert: {$path}", $isExecutable->invoke($importer, $path) === true);
    }

    $allowed = ['2026/07/foto.jpg', 'dokument.pdf', 'video.mp4', 'archiv.zip', 'font.woff2', 'daten.json'];
    foreach ($allowed as $path) {
        check("Uploads erlaubt: {$path}", $isExecutable->invoke($importer, $path) === false);
    }

    // --- 5. Zip-Bombe ----------------------------------------------------------

    $bombPath = $workdir . '/bomb.zip';
    $bomb = new ZipArchive();
    $bomb->open($bombPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $bomb->addFromString('uploads/gross.bin', str_repeat("\0", 60 * 1024 * 1024));
    $bomb->close();

    $zip = new ZipArchive();
    $zip->open($bombPath);
    $bombError = null;
    try {
        $assertSane->invoke($importer, $zip, $workdir);
    } catch (\RuntimeException $e) {
        $bombError = $e->getMessage();
    }
    $zip->close();
    check('Zip-Bombe wird abgelehnt', $bombError !== null, 'durchgelassen');
    check(
        'Ablehnung nennt das Kompressionsverhältnis',
        $bombError !== null && str_contains($bombError, 'Kompressionsverhältnis'),
        (string) $bombError
    );

    // Ein reguläres Archiv mit normalen Daten darf NICHT abgelehnt werden.
    $okPath = $workdir . '/normal.zip';
    $normal = new ZipArchive();
    $normal->open($okPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $normal->addFromString('db.sql', random_bytes(2 * 1024 * 1024));
    $normal->close();

    $zip = new ZipArchive();
    $zip->open($okPath);
    $normalError = null;
    try {
        $assertSane->invoke($importer, $zip, $workdir);
    } catch (\RuntimeException $e) {
        $normalError = $e->getMessage();
    }
    $zip->close();
    check('Reguläres Archiv wird durchgelassen', $normalError === null, (string) $normalError);

    // --- Aufräumen --------------------------------------------------------------

    array_map('unlink', glob($workdir . '/*.zip') ?: []);
    @unlink($workdir . '/extracted/db.sql');
    @rmdir($workdir . '/extracted');
    @rmdir($workdir);

    echo "\n";
    if ($failures === 0) {
        echo "OK, alle Checks bestanden.\n";
        exit(0);
    }

    echo "FEHLER: {$failures} Check(s) fehlgeschlagen.\n";
    exit(1);
}
