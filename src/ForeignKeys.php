<?php

declare(strict_types=1);

namespace RhDbEngine;

/**
 * Fremdschlüssel aus einer CREATE-Anweisung herauslösen und später wieder setzen.
 *
 * InnoDB verlangt Constraint-Namen datenbankweit eindeutig, nicht nur pro Tabelle. Beim
 * Anlegen einer Schattentabelle wandert nur der Tabellenname auf den Zwischen-Prefix, der
 * Constraint-Name bleibt. Da die Originaltabelle ihn noch hält, scheitert das Anlegen mit
 * errno 121. Dazu zeigen die Verweise auf die Live-Tabellen statt auf die Schattentabellen.
 *
 * Deshalb tragen die Schattentabellen gar keine Fremdschlüssel: sie werden hier abgetrennt
 * und erst gesetzt, wenn die Tabellen live sind und die alten Stände entfernt wurden. Dann
 * sind die Namen frei, die Verweise zeigen von selbst auf die richtigen Tabellen, und das
 * Umschalten selbst kommt ohne InnoDB-Fremdschlüsselbuchhaltung aus.
 *
 * WordPress-Kern nutzt keine Fremdschlüssel, einzelne Plugins schon (Defender zum Beispiel).
 */
final class ForeignKeys
{
    /**
     * Trennt alle Fremdschlüssel-Klauseln von einer CREATE-Anweisung ab.
     *
     * @return array{sql: string, keys: array<int, array{name: ?string, columns: string, ref_table: string, ref_columns: string, actions: string}>}
     */
    public static function strip(string $createSql): array
    {
        $pattern = '/,?\s*(?:CONSTRAINT\s+`([^`]+)`\s+)?FOREIGN\s+KEY\s*\(([^)]*)\)\s*'
            . 'REFERENCES\s+`([^`]+)`\s*\(([^)]*)\)'
            . '((?:\s+ON\s+(?:DELETE|UPDATE)\s+(?:RESTRICT|CASCADE|SET\s+NULL|NO\s+ACTION|SET\s+DEFAULT))*)/i';

        $keys = [];

        $cleaned = preg_replace_callback(
            $pattern,
            static function (array $m) use (&$keys): string {
                $keys[] = [
                    'name' => ($m[1] ?? '') !== '' ? $m[1] : null,
                    'columns' => trim($m[2]),
                    'ref_table' => $m[3],
                    'ref_columns' => trim($m[4]),
                    'actions' => trim($m[5] ?? ''),
                ];

                // Die führende Kommastelle verschwindet mit: eine Fremdschlüssel-Klausel
                // steht immer hinter mindestens einer Spaltendefinition.
                return '';
            },
            $createSql
        );

        return [
            'sql' => is_string($cleaned) ? $cleaned : $createSql,
            'keys' => $keys,
        ];
    }

    /**
     * Baut die ALTER-Anweisung, die einen abgetrennten Fremdschlüssel wieder setzt.
     *
     * Der Verweis wird auf den Ziel-Prefix gezogen: im Dump zeigt er auf die Tabelle der
     * Quellseite, gemeint ist die gleichnamige Tabelle hier.
     *
     * @param array{name: ?string, columns: string, ref_table: string, ref_columns: string, actions: string} $key
     * @param string|null $overrideName Ersatzname, wenn der ursprüngliche schon vergeben ist.
     */
    public static function addStatement(
        string $table,
        array $key,
        string $sourcePrefix,
        string $targetPrefix,
        ?string $overrideName = null
    ): string {
        $refTable = $key['ref_table'];
        if ($sourcePrefix !== '' && $sourcePrefix !== $targetPrefix && str_starts_with($refTable, $sourcePrefix)) {
            $refTable = $targetPrefix . substr($refTable, strlen($sourcePrefix));
        }

        $name = $overrideName ?? self::normalizeName($key['name'], $sourcePrefix, $targetPrefix);

        $sql = sprintf('ALTER TABLE `%s` ADD ', self::quote($table));
        if ($name !== null) {
            $sql .= sprintf('CONSTRAINT `%s` ', self::quote($name));
        }
        $sql .= sprintf(
            'FOREIGN KEY (%s) REFERENCES `%s` (%s)',
            $key['columns'],
            self::quote($refTable),
            $key['ref_columns']
        );

        if ($key['actions'] !== '') {
            $sql .= ' ' . $key['actions'];
        }

        return $sql;
    }

    /**
     * Ein Ersatzname, wenn der ursprüngliche noch belegt ist.
     *
     * Aus Tabelle und Spalten abgeleitet, damit wiederholte Läufe denselben Namen erzeugen
     * und nicht bei jedem Sync ein neuer entsteht. Auf die MySQL-Grenze von 64 Zeichen gekürzt.
     */
    public static function fallbackName(string $table, array $key, int $index): string
    {
        $name = sprintf('%s_fk_%d', $table, $index + 1);

        return strlen($name) <= 64 ? $name : substr($name, -64);
    }

    /**
     * Zieht einen führenden Quell-Prefix im Constraint-Namen auf den Ziel-Prefix.
     *
     * Rein kosmetisch. Namen, die den Prefix einer dritten Site tragen (Altlast aus einem
     * früheren Import), bleiben wie sie sind: sie lassen sich nicht zuverlässig erkennen,
     * und ein Constraint-Name ist funktional beliebig. Gegen Kollisionen hilft nicht das
     * Umbenennen, sondern dass die alte Tabelle vor dem Setzen entfernt wurde.
     */
    private static function normalizeName(?string $name, string $sourcePrefix, string $targetPrefix): ?string
    {
        if ($name === null || $sourcePrefix === '' || $sourcePrefix === $targetPrefix) {
            return $name;
        }

        return str_starts_with($name, $sourcePrefix)
            ? $targetPrefix . substr($name, strlen($sourcePrefix))
            : $name;
    }

    private static function quote(string $identifier): string
    {
        return str_replace('`', '``', $identifier);
    }
}
