<?php

declare(strict_types=1);

namespace RhDbEngine;

/**
 * Atomares Umschalten der importierten Tabellen.
 *
 * Der Import schrieb bisher direkt in die Live-Tabellen: erst DROP, dann CREATE, dann die
 * Zeilen. Zwischen diesen Schritten ist die Site kaputt, und jeder Abbruch in diesem Fenster
 * lässt sie kaputt zurück. Genau das ist am 2026-08-02 passiert: der Import starb nach
 * 17 Sekunden, die Options-Tabelle war zur Hälfte ersetzt, und die Zielseite war anderthalb
 * Stunden nur noch von Hand über FTP zu retten.
 *
 * Hier läuft es anders: der Import legt seine Tabellen unter einem eigenen Prefix an
 * (`rhstg_`), arbeitet dort fertig (Zeilen, Meta-Keys, URL-Umschreibung) und schaltet erst
 * ganz am Ende um. Das Umschalten ist ein einziges `RENAME TABLE`, und das ist in MySQL
 * atomar: entweder alle Tabellen wechseln, oder keine. Die Live-Site sieht nie einen halben
 * Zustand. Bricht der Import vorher ab, bleiben nur ein paar `rhstg_`-Tabellen liegen, die
 * der nächste Lauf wegräumt.
 *
 * Kann der Host das nicht (fehlende Rechte, zu lange Tabellennamen), fällt der Import auf
 * den direkten Weg zurück. Dann greift wieder das Sicherheits-Backup als Netz.
 */
final class TableSwap
{
    /** Prefix, unter dem der Import arbeitet, bis er fertig ist. */
    public const STAGE_PREFIX = 'rhstg_';

    /** Prefix, unter den die alten Live-Tabellen beim Umschalten wandern. */
    public const RETIRED_PREFIX = 'rhold_';

    /** MySQL-Grenze für Tabellennamen. */
    private const MAX_TABLE_NAME = 64;

    /**
     * Prüft einmalig, ob dieser Datenbank-Nutzer überhaupt umschalten darf.
     *
     * Nicht geraten, sondern gemessen: eine Wegwerf-Tabelle anlegen, umbenennen, entfernen.
     * Fällt einer der drei Schritte durch, fehlt ein Recht (CREATE, ALTER, DROP) und der
     * Import nimmt den direkten Weg.
     */
    public function isSupported(): bool
    {
        global $wpdb;

        $a = self::STAGE_PREFIX . 'probe_a';
        $b = self::STAGE_PREFIX . 'probe_b';

        $this->suppressed(static function () use ($wpdb, $a, $b): void {
            $wpdb->query("DROP TABLE IF EXISTS `{$a}`");
            $wpdb->query("DROP TABLE IF EXISTS `{$b}`");
        });

        $ok = $this->suppressed(static function () use ($wpdb, $a, $b): bool {
            if ($wpdb->query("CREATE TABLE `{$a}` (id INT)") === false) {
                return false;
            }
            if ($wpdb->query("RENAME TABLE `{$a}` TO `{$b}`") === false) {
                $wpdb->query("DROP TABLE IF EXISTS `{$a}`");
                return false;
            }
            return true;
        });

        $this->suppressed(static function () use ($wpdb, $a, $b): void {
            $wpdb->query("DROP TABLE IF EXISTS `{$a}`");
            $wpdb->query("DROP TABLE IF EXISTS `{$b}`");
        });

        return (bool) $ok;
    }

    /**
     * Prüft, ob die Namen unter allen drei Prefixen in die MySQL-Grenze passen.
     *
     * Der Stage-Prefix ersetzt den Site-Prefix. Ist der Site-Prefix kürzer als `rhstg_`,
     * wachsen die Namen. Bei der Tabelle eines Plugins mit sehr langem Namen kann das
     * die 64 Zeichen sprengen, dann lässt sich nicht umschalten.
     *
     * @param array<int, string> $baseNames Tabellennamen OHNE Prefix.
     */
    public function namesFit(array $baseNames): bool
    {
        foreach ($baseNames as $base) {
            if (strlen(self::STAGE_PREFIX . $base) > self::MAX_TABLE_NAME) {
                return false;
            }
            if (strlen(self::RETIRED_PREFIX . $base) > self::MAX_TABLE_NAME) {
                return false;
            }
        }

        return true;
    }

    /**
     * Der Stage-Prefix darf nicht zufällig der Prefix der Site selbst sein, sonst würde
     * der Import in die Live-Tabellen schreiben und genau das Fenster wieder aufreissen,
     * das hier geschlossen werden soll.
     */
    public function prefixIsUsable(string $livePrefix): bool
    {
        return $livePrefix !== self::STAGE_PREFIX && $livePrefix !== self::RETIRED_PREFIX;
    }

    /**
     * Räumt Reste eines abgebrochenen Laufs weg, bevor ein neuer beginnt.
     *
     * @return int Anzahl entfernter Tabellen.
     */
    public function dropLeftovers(): int
    {
        global $wpdb;

        $dropped = 0;

        // Ohne diesen Schalter scheitert das Entfernen einer Tabelle, auf die eine andere
        // per Fremdschlüssel verweist, und die Reste blieben liegen.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Sitzungs-Schalter, kein Datenzugriff.
        $wpdb->query('SET FOREIGN_KEY_CHECKS=0');

        foreach ([self::STAGE_PREFIX, self::RETIRED_PREFIX] as $prefix) {
            foreach ($this->tablesWithPrefix($prefix) as $table) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Tabellenname stammt aus SHOW TABLES mit festem Prefix, kein Nutzer-Input.
                $wpdb->query("DROP TABLE IF EXISTS `{$table}`");
                $dropped++;
            }
        }

        return $dropped;
    }

    /**
     * Schaltet die fertig importierten Schattentabellen in einem Zug live.
     *
     * Ein einziges `RENAME TABLE` mit allen Paaren. MySQL führt das atomar aus: schlägt
     * ein Paar fehl, macht die Datenbank die bereits ausgeführten Umbenennungen desselben
     * Statements wieder rückgängig. Die Site sieht also entweder den alten oder den neuen
     * Stand, nie eine Mischung.
     *
     * Tabellen, die es live noch nicht gibt (neu in der Quelle), bekommen kein
     * Live-nach-Alt-Paar, sondern werden nur eingehängt.
     *
     * @param array<int, string> $baseNames Tabellennamen OHNE Prefix.
     * @throws \RuntimeException wenn das Umschalten scheitert. Die Live-Tabellen sind dann unverändert.
     */
    public function swap(array $baseNames, string $livePrefix): void
    {
        global $wpdb;

        if ($baseNames === []) {
            return;
        }

        $existingLive = $this->existingTableMap($livePrefix, $baseNames);
        $existingStage = $this->existingTableMap(self::STAGE_PREFIX, $baseNames);

        $pairs = [];
        $retired = [];

        foreach ($baseNames as $base) {
            if (!isset($existingStage[$base])) {
                // Kein Schattenstand für diese Tabelle, also nichts umzuschalten.
                continue;
            }

            if (isset($existingLive[$base])) {
                $pairs[] = sprintf('`%s` TO `%s`', $livePrefix . $base, self::RETIRED_PREFIX . $base);
                $retired[] = self::RETIRED_PREFIX . $base;
            }

            $pairs[] = sprintf('`%s` TO `%s`', self::STAGE_PREFIX . $base, $livePrefix . $base);
        }

        if ($pairs === []) {
            return;
        }

        // Die Live-Tabellen können untereinander per Fremdschlüssel verbunden sein. Sie
        // wandern in derselben Anweisung, InnoDB soll dabei nicht zwischendurch prüfen.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Sitzungs-Schalter, kein Datenzugriff.
        $wpdb->query('SET FOREIGN_KEY_CHECKS=0');

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Tabellennamen aus festen Prefixen plus SHOW-TABLES-Ergebnis, kein Nutzer-Input.
        $result = $wpdb->query('RENAME TABLE ' . implode(', ', $pairs));

        if ($result === false) {
            throw new \RuntimeException(sprintf(
                'Umschalten auf die importierten Tabellen fehlgeschlagen: %s. Die Site läuft unverändert weiter.',
                $wpdb->last_error !== '' ? $wpdb->last_error : 'unbekannter Datenbankfehler'
            ));
        }

        // Ab hier ist die Site auf dem neuen Stand. Die alten Tabellen sind nur noch Ballast:
        // ein Fehlschlag beim Aufräumen ist kein Grund, den gelungenen Import als gescheitert
        // zu melden. Sie müssen aber weg, bevor die Fremdschlüssel gesetzt werden, sonst
        // sind deren Namen noch belegt.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Sitzungs-Schalter, kein Datenzugriff.
        $wpdb->query('SET FOREIGN_KEY_CHECKS=0');

        foreach ($retired as $table) {
            $this->suppressed(static function () use ($wpdb, $table): void {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- fester Prefix plus geprüfter Basisname.
                $wpdb->query("DROP TABLE IF EXISTS `{$table}`");
            });
        }
    }

    /**
     * @param array<int, string> $baseNames
     * @return array<string, true> Basisnamen, die unter diesem Prefix existieren.
     */
    private function existingTableMap(string $prefix, array $baseNames): array
    {
        $wanted = array_flip($baseNames);
        $found = [];

        foreach ($this->tablesWithPrefix($prefix) as $table) {
            $base = substr($table, strlen($prefix));
            if (isset($wanted[$base])) {
                $found[$base] = true;
            }
        }

        return $found;
    }

    /**
     * @return array<int, string>
     */
    private function tablesWithPrefix(string $prefix): array
    {
        global $wpdb;

        $like = $wpdb->esc_like($prefix) . '%';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Tabellen-Inventar, muss den frischen Stand sehen.
        $tables = (array) $wpdb->get_col($wpdb->prepare('SHOW TABLES LIKE %s', $like));

        return array_values(array_map('strval', $tables));
    }

    /**
     * Führt einen Aufruf ohne wpdb-Fehlerausgabe aus.
     *
     * Die Rechte-Probe erzeugt absichtlich Fehler. Ohne das Stummschalten landet ein
     * "command denied" als Notice im Frontend oder im Log und sieht nach einem kaputten
     * Plugin aus, obwohl der Import sauber auf den Fallback wechselt.
     *
     * @template T
     * @param callable(): T $fn
     * @return T
     */
    private function suppressed(callable $fn)
    {
        global $wpdb;

        $before = $wpdb->suppress_errors(true);
        $hide = $wpdb->hide_errors();

        try {
            return $fn();
        } finally {
            $wpdb->suppress_errors($before);
            if ($hide === false) {
                $wpdb->show_errors();
            }
        }
    }
}
