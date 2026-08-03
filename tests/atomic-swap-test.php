<?php

/**
 * Standalone-Test für das atomare Umschalten beim Import.
 *   php tests/atomic-swap-test.php
 *
 * Die Behauptung, um die es geht: ein Abbruch mitten im Import lässt die Zielseite
 * bedienbar. Genau das war am 2026-08-02 nicht so, und genau das wird hier geprüft,
 * ohne echte Datenbank: ein aufzeichnender wpdb protokolliert jedes Statement, und der
 * Test weist nach, dass bis zum Umschalten kein einziger schreibender Zugriff eine
 * Live-Tabelle trifft.
 *
 * Geprüft wird:
 *   1. Während Übertragung, Schlüssel- und URL-Umschreibung bleiben die Live-Tabellen
 *      unberührt, es wird ausschliesslich unter dem Zwischen-Prefix gearbeitet.
 *   2. Ein harter Abbruch mitten in der Übertragung hinterlässt ebenfalls keinen
 *      Zugriff auf eine Live-Tabelle. Das ist der eigentliche Beweis.
 *   3. Das Umschalten ist EIN Statement und enthält beide Richtungen je Tabelle.
 *   4. Die site-eigenen Options werden VOR dem Umschalten in die Schattentabelle
 *      geschrieben, nicht nachträglich in die Live-Tabelle.
 *   5. Der Tabellen-Filter eines Sync-Profils greift trotz Zwischen-Prefix.
 *   6. Fällt das Umschalten aus, arbeitet der Import direkt weiter (Rückfallweg).
 */

declare(strict_types=1);

// ============================================================
// WordPress-Ersatz, so viel wie die db-engine anfasst
// ============================================================

define('ABSPATH', __DIR__ . '/');
define('WP_CONTENT_DIR', sys_get_temp_dir() . '/rh-swap-test-' . getmypid());
define('HOUR_IN_SECONDS', 3600);
define('ARRAY_A', 'ARRAY_A');
define('ARRAY_N', 'ARRAY_N');
define('OBJECT', 'OBJECT');

$GLOBALS['rh_actions'] = [];
$GLOBALS['rh_filters'] = [];

function trailingslashit(string $p): string
{
    return rtrim($p, '/\\') . '/';
}
function untrailingslashit(string $p): string
{
    return rtrim($p, '/\\');
}
function wp_mkdir_p(string $dir): bool
{
    return is_dir($dir) || mkdir($dir, 0755, true);
}
function apply_filters(string $hook, $value, ...$args)
{
    foreach ($GLOBALS['rh_filters'][$hook] ?? [] as $cb) {
        $value = $cb($value, ...$args);
    }
    return $value;
}
function add_filter(string $hook, callable $cb): void
{
    $GLOBALS['rh_filters'][$hook][] = $cb;
}
function do_action(string $hook, ...$args): void
{
    foreach ($GLOBALS['rh_actions'][$hook] ?? [] as $cb) {
        $cb(...$args);
    }
}
function add_action(string $hook, callable $cb): void
{
    $GLOBALS['rh_actions'][$hook][] = $cb;
}
function size_format($bytes): string
{
    return (string) $bytes;
}
function wp_json_encode($data, int $flags = 0)
{
    return json_encode($data, $flags);
}
function wp_generate_password(int $len = 12, bool $s = true, bool $x = false): string
{
    return substr(bin2hex(random_bytes(16)), 0, $len);
}
function wp_upload_dir(): array
{
    return ['basedir' => WP_CONTENT_DIR . '/uploads'];
}
function get_site_url(): string
{
    return 'https://ziel.example';
}
function get_home_url(): string
{
    return 'https://ziel.example';
}
function wp_cache_flush(): bool
{
    return true;
}
function flush_rewrite_rules(bool $hard = true): void
{
}
function wp_raise_memory_limit(string $ctx = ''): bool
{
    return true;
}

/**
 * Aufzeichnender wpdb-Ersatz.
 *
 * Führt nichts aus, sondern hält fest, was ausgeführt worden wäre, und spielt ein
 * Tabellen-Inventar vor. Das reicht, um die entscheidende Frage zu beantworten: welche
 * Tabellen fasst der Import zu welchem Zeitpunkt an.
 */
final class FakeWpdb
{
    public string $prefix = 'live_';
    public string $options = 'live_options';
    public string $posts = 'live_posts';
    public string $last_error = '';

    /** @var array<int, string> */
    public array $queries = [];

    /** @var array<string, true> Vorhandene Tabellen. */
    public array $tables = [
        'live_options' => true,
        'live_posts' => true,
        'live_users' => true,
        'live_usermeta' => true,
    ];

    /** Wirft beim n-ten Statement, um einen harten Abbruch nachzustellen. 0 heisst nie. */
    public int $dieAtQuery = 0;

    private int $counter = 0;

    public function query(string $sql)
    {
        $this->counter++;
        $this->queries[] = $sql;

        if ($this->dieAtQuery > 0 && $this->counter >= $this->dieAtQuery) {
            throw new \RuntimeException('Nachgestellter harter Abbruch beim Statement ' . $this->counter);
        }

        if (preg_match('/^CREATE TABLE `([^`]+)`/i', $sql, $m)) {
            $this->tables[$m[1]] = true;
        }
        if (preg_match('/^DROP TABLE IF EXISTS `([^`]+)`/i', $sql, $m)) {
            unset($this->tables[$m[1]]);
        }
        if (stripos($sql, 'RENAME TABLE ') === 0) {
            if (preg_match_all('/`([^`]+)` TO `([^`]+)`/', $sql, $mm, PREG_SET_ORDER)) {
                foreach ($mm as $pair) {
                    unset($this->tables[$pair[1]]);
                    $this->tables[$pair[2]] = true;
                }
            }
        }

        return 1;
    }

    public function get_col($sql)
    {
        $this->queries[] = (string) $sql;
        if (preg_match("/SHOW TABLES LIKE '([^']+)'/i", (string) $sql, $m)) {
            // Das Muster hat zwei Maskier-Runden hinter sich (esc_like und prepare).
            // Für den Vergleich zählt nur der nackte Prefix.
            $like = str_replace(['\\', '%'], '', $m[1]);
            return array_values(array_filter(
                array_keys($this->tables),
                static fn (string $t): bool => str_starts_with($t, $like)
            ));
        }
        return [];
    }

    public function get_results($sql, $mode = null)
    {
        $this->queries[] = (string) $sql;
        if (stripos((string) $sql, 'SHOW COLUMNS') === 0) {
            return [
                ['Field' => 'id', 'Type' => 'bigint(20)', 'Key' => 'PRI'],
                ['Field' => 'wert', 'Type' => 'longtext', 'Key' => ''],
            ];
        }
        return [];
    }

    public function get_row($sql, $mode = null, $y = 0)
    {
        $this->queries[] = (string) $sql;
        return null;
    }

    public function prepare(string $sql, ...$args): string
    {
        foreach ($args as $arg) {
            $replacement = is_int($arg) ? (string) $arg : "'" . addslashes((string) $arg) . "'";
            $sql = preg_replace('/%[sd]/', $replacement, $sql, 1) ?? $sql;
        }
        return $sql;
    }

    public function esc_like(string $text): string
    {
        return addcslashes($text, '_%\\');
    }

    public function insert(string $table, array $data, $format = null)
    {
        $this->queries[] = 'INSERT INTO `' . $table . '` (' . implode(', ', array_keys($data)) . ')';
        return 1;
    }

    public function update(string $table, array $data, array $where, $f = null, $w = null)
    {
        $this->queries[] = 'UPDATE `' . $table . '` SET ' . implode(', ', array_keys($data));
        return 1;
    }

    public function suppress_errors(bool $s = true): bool
    {
        return false;
    }
    public function hide_errors(): bool
    {
        return true;
    }
    public function show_errors(): bool
    {
        return true;
    }
}

// ============================================================
// Testgerüst
// ============================================================

require_once dirname(__DIR__) . '/src/SearchReplace.php';
require_once dirname(__DIR__) . '/src/Storage.php';
require_once dirname(__DIR__) . '/src/ImportCursor.php';
require_once dirname(__DIR__) . '/src/SwapUnavailable.php';
require_once dirname(__DIR__) . '/src/TableSwap.php';
require_once dirname(__DIR__) . '/src/Importer.php';

$failures = 0;

function check(string $label, bool $ok, string $detail = ''): void
{
    global $failures;
    echo ($ok ? "  PASS  " : "  FAIL  ") . $label . "\n";
    if (!$ok) {
        $failures++;
        if ($detail !== '') {
            echo "        " . str_replace("\n", "\n        ", $detail) . "\n";
        }
    }
}

/** Legt ein Backup-ZIP an, wie der Exporter es schreibt. */
function makeBackupZip(string $path, string $sourcePrefix): void
{
    $sql = "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n";
    foreach (['options', 'posts', 'users', 'usermeta'] as $table) {
        $full = $sourcePrefix . $table;
        $sql .= "\n-- Table: {$full}\n";
        $sql .= "DROP TABLE IF EXISTS `{$full}`;\n";
        $sql .= "CREATE TABLE `{$full}` (id BIGINT(20) NOT NULL, wert LONGTEXT, PRIMARY KEY (id));\n\n";
        $sql .= "INSERT INTO `{$full}` (id, wert) VALUES (1, 'https://quelle.example/eins');\n";
        $sql .= "INSERT INTO `{$full}` (id, wert) VALUES (2, 'zwei');\n";
    }
    $sql .= "\nSET FOREIGN_KEY_CHECKS=1;\n";

    $manifest = json_encode([
        'db_prefix' => $sourcePrefix,
        'site_url' => 'https://quelle.example',
        'home_url' => 'https://quelle.example',
        'includes_uploads' => false,
        'created_at' => gmdate('c'),
    ]);

    @unlink($path);
    $zip = new ZipArchive();
    $zip->open($path, ZipArchive::CREATE);
    $zip->addFromString('db.sql', $sql);
    $zip->addFromString('manifest.json', (string) $manifest);
    $zip->close();
}

/**
 * Die Rechte-Probe legt selbst eine Wegwerf-Tabelle an, benennt sie um und entfernt sie.
 * Für die Frage, was der Import mit den echten Daten tut, zählt sie nicht mit.
 */
function isProbe(string $query): bool
{
    return str_contains($query, 'probe_a') || str_contains($query, 'probe_b');
}

/** Alle Statements, die eine Tabelle mit diesem Prefix verändern. */
function destructiveOn(array $queries, string $prefix): array
{
    $hits = [];
    foreach ($queries as $q) {
        if (isProbe($q)) {
            continue;
        }
        if (!preg_match('/^(DROP TABLE|CREATE TABLE|INSERT INTO|UPDATE|TRUNCATE|DELETE FROM)/i', $q)) {
            continue;
        }
        if (preg_match('/`(' . preg_quote($prefix, '/') . '[a-z0-9_]+)`/i', $q)) {
            $hits[] = $q;
        }
    }
    return $hits;
}

function renameStatements(array $queries): array
{
    return array_values(array_filter(
        $queries,
        static fn (string $q): bool => stripos($q, 'RENAME TABLE') === 0 && !isProbe($q)
    ));
}

/** Position des echten Umschalt-Statements, oder null. */
function swapPosition(array $queries): ?int
{
    foreach ($queries as $i => $q) {
        if (stripos($q, 'RENAME TABLE') === 0 && !isProbe($q)) {
            return $i;
        }
    }
    return null;
}

wp_mkdir_p(WP_CONTENT_DIR);
$zipPath = WP_CONTENT_DIR . '/backup.zip';
makeBackupZip($zipPath, 'src_');

$storage = new RhDbEngine\Storage();
$importer = new RhDbEngine\Importer($storage, new RhDbEngine\SearchReplace());

/** Fährt einen Import bis zum Ende oder bis zum Abbruch. */
$runImport = static function (RhDbEngine\ImportCursor $cursor, $filter = null) use ($importer): RhDbEngine\ImportCursor {
    $rounds = 0;
    while (!$cursor->isDone() && $rounds++ < 500) {
        $cursor = $importer->importStep($cursor, 5.0, $filter, false);
    }
    return $cursor;
};

// ------------------------------------------------------------
echo "\n1. Import im Umschalt-Modus: Live-Tabellen bleiben unberührt\n";
// ------------------------------------------------------------

$GLOBALS['wpdb'] = new FakeWpdb();
$GLOBALS['rh_actions'] = [];
add_action('rh-db-engine/before_table_swap', static function (string $stage): void {
    // Stellt rh-sync nach: die site-eigenen Options landen VOR dem Umschalten in der
    // Schattentabelle.
    $GLOBALS['wpdb']->query("DELETE FROM `{$stage}options` WHERE option_name = 'siteurl'");
    $GLOBALS['wpdb']->insert($stage . 'options', ['option_name' => 'siteurl']);
});

$cursor = $runImport(RhDbEngine\ImportCursor::start($zipPath, WP_CONTENT_DIR . '/job1'));

$queries = $GLOBALS['wpdb']->queries;
$renameIndex = swapPosition($queries);

if (getenv('RH_TRACE') === '1') {
    foreach ($GLOBALS['wpdb']->queries as $i => $q) {
        echo $i . ': ' . substr((string) preg_replace('/\s+/', ' ', $q), 0, 90) . "\n";
    }
}
check('Umschalt-Modus wurde gewählt', $cursor->swapMode === true);
check('Import ist durchgelaufen', $cursor->isDone());
check('Es gibt genau ein Umschalt-Statement', count(renameStatements($queries)) === 1);

$beforeSwap = $renameIndex === null ? $queries : array_slice($queries, 0, $renameIndex);
$liveHits = destructiveOn($beforeSwap, 'live_');
check(
    'Vor dem Umschalten kein schreibender Zugriff auf eine Live-Tabelle',
    $liveHits === [],
    implode("\n", array_slice($liveHits, 0, 5))
);
check('Es wurde unter dem Zwischen-Prefix gearbeitet', count(destructiveOn($beforeSwap, 'rhstg_')) >= 8);

// ------------------------------------------------------------
echo "\n2. Harter Abbruch mitten in der Übertragung\n";
// ------------------------------------------------------------

$GLOBALS['wpdb'] = new FakeWpdb();
$GLOBALS['wpdb']->dieAtQuery = 7; // mitten in den CREATE- und INSERT-Statements
$cursor = RhDbEngine\ImportCursor::start($zipPath, WP_CONTENT_DIR . '/job2');

$crashed = false;
try {
    $cursor = $runImport($cursor);
} catch (\RuntimeException $e) {
    $crashed = true;
}

check('Der Abbruch ist eingetreten', $crashed);
check('Kein Umschalten stattgefunden', renameStatements($GLOBALS['wpdb']->queries) === []);

$liveHits = destructiveOn($GLOBALS['wpdb']->queries, 'live_');
check(
    'Nach dem Abbruch ist keine Live-Tabelle angefasst worden',
    $liveHits === [],
    implode("\n", array_slice($liveHits, 0, 5))
);
check('Alle Live-Tabellen sind noch da', array_diff(
    ['live_options', 'live_posts', 'live_users', 'live_usermeta'],
    array_keys($GLOBALS['wpdb']->tables)
) === []);

// ------------------------------------------------------------
echo "\n3. Das Umschalt-Statement selbst\n";
// ------------------------------------------------------------

$GLOBALS['wpdb'] = new FakeWpdb();
$cursor = $runImport(RhDbEngine\ImportCursor::start($zipPath, WP_CONTENT_DIR . '/job3'));

$rename = renameStatements($GLOBALS['wpdb']->queries)[0] ?? '';

foreach (['options', 'posts', 'users', 'usermeta'] as $base) {
    check(
        "Tabelle {$base}: alt weicht und neu rückt nach",
        str_contains($rename, "`live_{$base}` TO `rhold_{$base}`")
            && str_contains($rename, "`rhstg_{$base}` TO `live_{$base}`")
    );
}
check('Die alten Tabellen werden danach entfernt', count(array_filter(
    $GLOBALS['wpdb']->queries,
    static fn (string $q): bool => str_contains($q, 'DROP TABLE IF EXISTS `rhold_')
)) === 4);

// ------------------------------------------------------------
echo "\n4. Die site-eigenen Options stehen vor dem Umschalten bereit\n";
// ------------------------------------------------------------

$GLOBALS['wpdb'] = new FakeWpdb();
$cursor = $runImport(RhDbEngine\ImportCursor::start($zipPath, WP_CONTENT_DIR . '/job4'));

$queries = $GLOBALS['wpdb']->queries;
$guardIndex = null;
$renameIndex = swapPosition($queries);
foreach ($queries as $i => $q) {
    if ($guardIndex === null && str_contains($q, 'DELETE FROM `rhstg_options`')) {
        $guardIndex = $i;
    }
}

check('Die Options gingen in die Schattentabelle', $guardIndex !== null);
check('Und zwar vor dem Umschalten', $guardIndex !== null && $renameIndex !== null && $guardIndex < $renameIndex);
check('Nach dem Umschalten wird an den Live-Tabellen nichts mehr repariert', destructiveOn(
    array_slice($queries, ($renameIndex ?? 0) + 1),
    'live_'
) === []);

// ------------------------------------------------------------
echo "\n5. Der Tabellen-Filter greift trotz Zwischen-Prefix\n";
// ------------------------------------------------------------

$GLOBALS['wpdb'] = new FakeWpdb();
$nurPosts = static fn (string $table): bool => $table === 'live_posts';
$cursor = $runImport(RhDbEngine\ImportCursor::start($zipPath, WP_CONTENT_DIR . '/job5'), $nurPosts);

check(
    'Nur die erlaubte Tabelle wurde angelegt',
    $cursor->createdTables === ['posts'],
    'angelegt: ' . implode(', ', $cursor->createdTables)
);
check('Die gesperrten Tabellen kamen nicht durch', !in_array('users', $cursor->createdTables, true));
check('Und wurden auch nicht als Schattentabelle geschrieben', destructiveOn(
    $GLOBALS['wpdb']->queries,
    'rhstg_users'
) === []);

// ------------------------------------------------------------
echo "\n6. Rückfallweg, wenn nicht umgeschaltet werden kann\n";
// ------------------------------------------------------------

$GLOBALS['wpdb'] = new FakeWpdb();
$GLOBALS['rh_filters'] = [];
$GLOBALS['rh_actions'] = [];
add_filter('rh-db-engine/atomic_swap', static fn (): bool => false);

$warned = null;
add_action('rh-db-engine/swap_unavailable', static function (string $reason) use (&$warned): void {
    $warned = $reason;
});

$cursor = $runImport(RhDbEngine\ImportCursor::start($zipPath, WP_CONTENT_DIR . '/job6'));

check('Der Import lief im direkten Modus', $cursor->swapMode === false);
check('Der Aufrufer wurde gewarnt', is_string($warned) && $warned !== '');
check('Und hat direkt in die Live-Tabellen geschrieben', destructiveOn($GLOBALS['wpdb']->queries, 'live_') !== []);
check('Ohne Umschalt-Statement', renameStatements($GLOBALS['wpdb']->queries) === []);

// ------------------------------------------------------------

$rm = static function (string $dir) use (&$rm): void {
    foreach (glob(rtrim($dir, '/') . '/*') ?: [] as $item) {
        is_dir($item) ? $rm($item) : @unlink($item);
    }
    @rmdir($dir);
};
$rm(WP_CONTENT_DIR);

echo "\n" . ($failures === 0 ? "Alle Prüfungen bestanden.\n" : "{$failures} Prüfung(en) fehlgeschlagen.\n");
exit($failures === 0 ? 0 : 1);
