<?php

/**
 * Standalone-Test für den SQL-Statement-Parser des Importers.
 *   php tests/sql-parser-test.php
 *
 * Deckt den realen Datenverlust-Bug ab: die frühere Variante erkannte das Statement-Ende
 * daran, dass ein gelesenes 64-KB-Stück auf ';' endet. Bei Zellen über der Lesegröße
 * (serialisierte Elementor-Daten, große Options, base64 im post_content) zerriss das
 * Statements mitten im String. Das Fragment lief als ungültiges SQL auf, wpdb->query()
 * gab false zurück, der Rückgabewert wurde nicht geprüft, und die Zeile fehlte danach
 * stillschweigend in der wiederhergestellten Datenbank.
 *
 * Gemessen vor dem Fix: bei ~1,1 MB großen Zellen kamen 114 von 120 Statements kaputt an.
 */

declare(strict_types=1);

namespace {
    function trailingslashit(string $s): string
    {
        return rtrim($s, '/\\') . '/';
    }

    /**
     * Minimaler wpdb-Ersatz: sammelt die ausgeführten Statements.
     */
    final class FakeWpdb
    {
        /** @var array<int, string> */
        public array $executed = [];

        public string $last_error = '';

        public string $prefix = 'wp_';

        /** Statement-Nummer, die fehlschlagen soll (0 = keine). */
        public int $failAt = 0;

        private int $counter = 0;

        public function query(string $sql): int|false
        {
            $this->counter++;
            if ($this->failAt > 0 && $this->counter === $this->failAt) {
                $this->last_error = 'simulierter Datenbankfehler';
                return false;
            }
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

    // --- Harness ---------------------------------------------------------------

    $workdir = sys_get_temp_dir() . '/rh-sqlparser-' . bin2hex(random_bytes(4));
    mkdir($workdir . '/extracted', 0777, true);

    $importer = new \RhDbEngine\Importer(new \RhDbEngine\Storage(), new \RhDbEngine\SearchReplace());
    $stepSql = new ReflectionMethod(\RhDbEngine\Importer::class, 'stepSql');
    $readChunk = (new ReflectionClass(\RhDbEngine\Importer::class))->getConstant('SQL_READ_CHUNK');

    /**
     * Fährt den Parser über einen Dump und liefert die ausgeführten Statements.
     *
     * @return array{0: array<int, string>, 1: ?string} [Statements, Fehlermeldung]
     */
    function runParser(string $dump, float $budget = PHP_INT_MAX, string $sourcePrefix = 'wp_', string $targetPrefix = 'wp_', int $failAt = 0): array
    {
        global $workdir, $importer, $stepSql;

        file_put_contents($workdir . '/extracted/db.sql', $dump);
        $GLOBALS['wpdb'] = new FakeWpdb();
        $GLOBALS['wpdb']->failAt = $failAt;

        $cursor = \RhDbEngine\ImportCursor::start($workdir . '/dummy.zip', $workdir);
        $cursor->phase = \RhDbEngine\ImportCursor::PHASE_SQL;
        $cursor->sourcePrefix = $sourcePrefix;
        $cursor->targetPrefix = $targetPrefix;

        $error = null;
        $guard = 0;
        try {
            // Mit kleinem Budget mehrfach aufrufen, bis die Phase weiterspringt (Resume-Pfad).
            while ($cursor->phase === \RhDbEngine\ImportCursor::PHASE_SQL && $guard++ < 10000) {
                $stepSql->invoke($importer, $cursor, null, microtime(true) + $budget);
            }
        } catch (\RuntimeException $e) {
            $error = $e->getMessage();
        }

        return [$GLOBALS['wpdb']->executed, $error];
    }

    /** Baut einen realistischen serialisierten Elementor-Wert der gewünschten Größe. */
    function bigValue(int $widgets): string
    {
        $blocks = [];
        for ($i = 0; $i < $widgets; $i++) {
            $blocks[] = [
                'id' => bin2hex(random_bytes(4)),
                'elType' => 'widget',
                'settings' => [
                    'title' => "Sektion {$i}",
                    'image' => ['url' => "https://kunde.de/wp-content/uploads/2026/07/foto-{$i}.jpg", 'id' => 1000 + $i],
                    'css' => '.elementor-element-' . bin2hex(random_bytes(3)) . '{padding:10px;margin:0}',
                ],
            ];
        }

        // Wie mysqli_real_escape_string: echte Newlines werden zu \n-Literalen.
        return str_replace(
            ["\\", "'", "\n", "\r", "\0"],
            ["\\\\", "\\'", "\\n", "\\r", "\\0"],
            serialize($blocks)
        );
    }

    // --- 1. Große Zelle bleibt ein einziges Statement ---------------------------

    $value = bigValue(4000);
    check(
        'Testwert ist größer als die Lesegröße',
        strlen($value) > $readChunk * 4,
        number_format(strlen($value)) . ' Bytes bei Lesegröße ' . number_format($readChunk)
    );

    $dump = "-- RH Blueprint DB Export\nSET NAMES utf8mb4;\n"
        . "INSERT INTO `wp_postmeta` (`meta_id`, `meta_key`, `meta_value`) VALUES ('1', '_elementor_data', '{$value}');\n"
        . "INSERT INTO `wp_postmeta` (`meta_id`, `meta_key`, `meta_value`) VALUES ('2', '_klein', 'kurz');\n";

    [$stmts, $err] = runParser($dump);
    check('Große Zelle: genau 3 Statements', count($stmts) === 3, 'erhalten: ' . count($stmts));
    check('Große Zelle: kein Fehler', $err === null, (string) $err);
    check(
        'Große Zelle: Wert kommt unverändert an',
        isset($stmts[1]) && str_contains($stmts[1], $value)
    );
    check(
        'Große Zelle: Statement ist vollständig terminiert',
        isset($stmts[1]) && str_starts_with($stmts[1], 'INSERT INTO `wp_postmeta`') && str_ends_with(rtrim($stmts[1]), ';')
    );

    // --- 2. Viele große Zellen hintereinander ----------------------------------

    $dump = "SET NAMES utf8mb4;\n";
    $values = [];
    for ($i = 0; $i < 8; $i++) {
        $values[$i] = bigValue(random_int(1500, 4000));
        $dump .= "INSERT INTO `wp_postmeta` (`meta_id`, `meta_value`) VALUES ('{$i}', '{$values[$i]}');\n";
    }
    [$stmts, $err] = runParser($dump);
    check('Acht große Zellen: 9 Statements', count($stmts) === 9, 'erhalten: ' . count($stmts));
    $allIntact = $err === null && count($stmts) === 9;
    foreach ($values as $i => $v) {
        if (! isset($stmts[$i + 1]) || ! str_contains($stmts[$i + 1], $v)) {
            $allIntact = false;
        }
    }
    check('Acht große Zellen: alle Werte unversehrt', $allIntact);

    // --- 3. Semikolon und Kommentar-Marker innerhalb eines Werts ---------------

    $tricky = "Preis: 10; Rabatt: 20; -- kein Kommentar\\nzweite Zeile; Ende";
    $dump = "INSERT INTO `wp_options` (`option_value`) VALUES ('{$tricky}');\n";
    [$stmts, $err] = runParser($dump);
    check('Semikolon im String beendet das Statement nicht', count($stmts) === 1, 'erhalten: ' . count($stmts));
    check('Semikolon im String: Wert unversehrt', isset($stmts[0]) && str_contains($stmts[0], $tricky));

    // --- 4. Escaped Quote im Wert ----------------------------------------------

    $quoted = "Robins \\'Backup\\' Test; mit Semikolon";
    $dump = "INSERT INTO `wp_options` (`option_value`) VALUES ('{$quoted}');\n"
        . "INSERT INTO `wp_options` (`option_value`) VALUES ('zweites');\n";
    [$stmts, $err] = runParser($dump);
    check('Escaptes Quote: 2 Statements', count($stmts) === 2, 'erhalten: ' . count($stmts));

    // --- 5. Escape-Zeichen genau auf der Lesestück-Grenze -----------------------
    // Kniffligster Fall: der Backslash ist das letzte Byte eines Lesestücks, das
    // escapte Zeichen das erste des nächsten.

    $prefix = "INSERT INTO `wp_options` (`option_value`) VALUES ('";
    $padding = str_repeat('x', $readChunk - strlen($prefix) - 1);
    $dump = $prefix . $padding . "\\'; nicht das Ende" . str_repeat('y', 100) . "');\n"
        . "INSERT INTO `wp_options` (`option_value`) VALUES ('danach');\n";
    [$stmts, $err] = runParser($dump);
    check('Escape auf Stück-Grenze: 2 Statements', count($stmts) === 2, 'erhalten: ' . count($stmts));
    check(
        'Escape auf Stück-Grenze: Semikolon im String ignoriert',
        isset($stmts[0]) && str_contains($stmts[0], '; nicht das Ende')
    );

    // --- 6. Quote genau auf der Lesestück-Grenze -------------------------------

    $padding = str_repeat('x', $readChunk - strlen($prefix));
    $dump = $prefix . $padding . "');\n"
        . "INSERT INTO `wp_options` (`option_value`) VALUES ('danach');\n";
    [$stmts, $err] = runParser($dump);
    check('Quote auf Stück-Grenze: 2 Statements', count($stmts) === 2, 'erhalten: ' . count($stmts));

    // --- 7. Resume: winziges Budget liefert dasselbe Ergebnis ------------------

    $dump = "SET NAMES utf8mb4;\n";
    $values = [];
    for ($i = 0; $i < 5; $i++) {
        $values[$i] = bigValue(random_int(800, 2000));
        $dump .= "INSERT INTO `wp_postmeta` (`meta_id`, `meta_value`) VALUES ('{$i}', '{$values[$i]}');\n";
    }
    [$full, $errFull] = runParser($dump);
    [$resumed, $errResume] = runParser($dump, 0.0001);
    check('Resume: gleiche Anzahl Statements wie am Stück', count($resumed) === count($full), count($resumed) . ' statt ' . count($full));
    check('Resume: identische Statements', $resumed === $full);
    check('Resume: kein Fehler', $errResume === null, (string) $errResume);

    // --- 8. Kommentar- und Leerzeilen ------------------------------------------

    $dump = "-- RH Blueprint DB Export\n-- Date: 2026-07-27\n\n"
        . "SET NAMES utf8mb4;\n\n"
        . "-- Table: wp_posts\nDROP TABLE IF EXISTS `wp_posts`;\n"
        . "CREATE TABLE `wp_posts` (`ID` bigint(20) NOT NULL);\n";
    [$stmts, $err] = runParser($dump);
    check('Kommentarzeilen werden verworfen', count($stmts) === 3, 'erhalten: ' . count($stmts));
    check(
        'DROP steht am Statement-Anfang (Tabellen-Filter kann greifen)',
        isset($stmts[1]) && str_starts_with($stmts[1], 'DROP TABLE IF EXISTS `wp_posts`')
    );

    // --- 9. Prefix-Umschreibung -------------------------------------------------

    $dump = "-- Table: alt_posts\nDROP TABLE IF EXISTS `alt_posts`;\n"
        . "CREATE TABLE `alt_posts` (`ID` bigint(20) NOT NULL);\n"
        . "INSERT INTO `alt_posts` (`ID`) VALUES ('1');\n";
    [$stmts, $err] = runParser($dump, PHP_INT_MAX, 'alt_', 'neu_');
    check('Prefix umgeschrieben: 3 Statements', count($stmts) === 3, 'erhalten: ' . count($stmts));
    $rewritten = count(array_filter($stmts, static fn (string $s): bool => str_contains($s, '`neu_posts`')));
    check('Prefix umgeschrieben: alle 3 auf neu_posts', $rewritten === 3, "umgeschrieben: {$rewritten}");

    // --- 10. Datenbankfehler bricht ab, statt still weiterzulaufen --------------

    $dump = "SET NAMES utf8mb4;\n"
        . "DROP TABLE IF EXISTS `wp_posts`;\n"
        . "CREATE TABLE `wp_posts` (`ID` bigint(20) NOT NULL) COLLATE=utf8mb4_0900_ai_ci;\n"
        . "INSERT INTO `wp_posts` (`ID`) VALUES ('1');\n";
    [$stmts, $err] = runParser($dump, PHP_INT_MAX, 'wp_', 'wp_', 3);
    check('Fehlgeschlagenes Statement wirft', $err !== null, 'keine Exception');
    check(
        'Fehlermeldung nennt den Datenbankfehler',
        $err !== null && str_contains($err, 'simulierter Datenbankfehler'),
        (string) $err
    );
    check('Nach dem Fehler wird nichts mehr ausgeführt', count($stmts) === 2, 'ausgeführt: ' . count($stmts));

    // --- Aufräumen --------------------------------------------------------------

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
