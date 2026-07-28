<?php

declare(strict_types=1);

namespace RhDbEngine\Archive;

/**
 * Geht einen oder mehrere Verzeichnisbäume durch und schreibt jede Datei in den Index.
 *
 * Warum nicht einfach ein rekursiver Iterator: der Durchgang über eine ganze WordPress-
 * Installation umfasst zehntausende Dateien und passt in keinen einzelnen Request. Der
 * Scanner arbeitet deshalb ein Zeitbudget ab und hinterlässt einen Stand, aus dem der
 * nächste Aufruf weitermacht.
 *
 * Mehrere Wurzeln, weil ein einziger Pfad nicht reicht: bei manchen Installationen liegt
 * wp-content neben und nicht unter dem WordPress-Verzeichnis. Jede Wurzel bekommt im
 * Archiv einen sprechenden Namen (root/, content/, plugins/), damit beim Auspacken von
 * Hand erkennbar bleibt, wohin was gehört.
 *
 * Zwei Regeln, die leicht übersehen werden und beide echten Schaden anrichten:
 * Symlinks werden nicht verfolgt, und jeder Pfad wird gegen seine Wurzel verankert. Ohne
 * das erste archiviert ein verlinktes Verzeichnis im schlimmsten Fall den halben Server
 * oder läuft in eine Schleife, ohne das zweite lässt sich über einen Link ein Pfad
 * ausserhalb der erlaubten Wurzel ins Archiv holen.
 */
final class SiteScanner
{
    /** So viele Dateien am Stück, bevor wieder auf die Uhr gesehen wird. */
    private const CHECK_EVERY = 200;

    /**
     * @param callable(string, string, bool): bool $exclude Erhält (relativer Pfad, Wurzelname,
     *                                                      ist Verzeichnis) und gibt true, wenn
     *                                                      der Eintrag nicht ins Archiv soll.
     */
    public function __construct(private $exclude = null)
    {
    }

    /**
     * Arbeitet ein Zeitbudget ab und schreibt die gefundenen Dateien in den Index.
     */
    public function scanStep(ScanCursor $cursor, ArchiveIndex $index, float $budget): ScanCursor
    {
        $deadline = microtime(true) + max(0.1, $budget);
        $seitLetztemBlick = 0;

        while (true) {
            // Aktuelle Wurzel abgearbeitet? Dann die nächste beginnen.
            if ($cursor->pending === []) {
                if ($cursor->roots === []) {
                    $cursor->done = true;

                    return $cursor;
                }

                $naechste = array_shift($cursor->roots);
                $real = realpath($naechste['path']);

                if ($real === false || ! is_dir($real)) {
                    continue;
                }

                $cursor->rootName = $naechste['name'];
                $cursor->rootPath = $real;
                $cursor->pending = [$real];

                continue;
            }

            $dir = array_pop($cursor->pending);

            foreach ($this->entries($dir) as $pfad) {
                $seitLetztemBlick++;

                // Symlinks führen unter Umständen aus dem Baum heraus oder im Kreis.
                if (is_link($pfad)) {
                    $cursor->skipped++;
                    continue;
                }

                $istVerzeichnis = is_dir($pfad);
                $relativ = $this->relativeTo($cursor->rootPath, $pfad);

                // Ausserhalb der Wurzel hat nichts im Archiv verloren, egal wie es dort
                // hingekommen ist.
                if ($relativ === null) {
                    $cursor->skipped++;
                    continue;
                }

                if ($this->isExcluded($relativ, $cursor->rootName, $istVerzeichnis)) {
                    $cursor->skipped++;
                    continue;
                }

                if ($istVerzeichnis) {
                    $cursor->pending[] = $pfad;
                    continue;
                }

                if (! is_file($pfad) || ! is_readable($pfad)) {
                    $cursor->skipped++;
                    continue;
                }

                $size = filesize($pfad);
                if ($size === false) {
                    $cursor->skipped++;
                    continue;
                }

                $index->append(
                    $cursor->rootName . '/' . $relativ,
                    $pfad,
                    (int) $size,
                    (int) (filemtime($pfad) ?: 0)
                );

                $cursor->files++;
                $cursor->bytes += (int) $size;
            }

            if ($seitLetztemBlick >= self::CHECK_EVERY) {
                $seitLetztemBlick = 0;
                if (microtime(true) >= $deadline) {
                    return $cursor;
                }
            }
        }
    }

    /**
     * Einträge eines Verzeichnisses, ohne . und ..
     *
     * @return array<int, string>
     */
    private function entries(string $dir): array
    {
        $handle = @opendir($dir);
        if ($handle === false) {
            return [];
        }

        $pfade = [];
        while (($name = readdir($handle)) !== false) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            $pfade[] = rtrim($dir, '/\\') . '/' . $name;
        }
        closedir($handle);

        return $pfade;
    }

    /**
     * Pfad relativ zur Wurzel, oder null wenn er ausserhalb liegt.
     */
    private function relativeTo(string $root, string $pfad): ?string
    {
        $root = rtrim(str_replace('\\', '/', $root), '/');
        $pfad = str_replace('\\', '/', $pfad);

        if (! str_starts_with($pfad, $root . '/')) {
            return null;
        }

        $relativ = substr($pfad, strlen($root) + 1);

        return $relativ === '' ? null : $relativ;
    }

    private function isExcluded(string $relativ, string $rootName, bool $istVerzeichnis): bool
    {
        if (! is_callable($this->exclude)) {
            return false;
        }

        return (bool) ($this->exclude)($relativ, $rootName, $istVerzeichnis);
    }

    /**
     * Entfernt Wurzeln, die unterhalb einer anderen liegen.
     *
     * Beim Standard-Layout liegt wp-content unter dem WordPress-Verzeichnis. Ohne diesen
     * Schritt landet die halbe Installation zweimal im Archiv, einmal als root/wp-content/
     * und einmal als content/.
     *
     * @param array<string, string> $roots
     * @return array<string, string>
     */
    public static function dropNested(array $roots): array
    {
        $aufgeloest = [];
        foreach ($roots as $name => $path) {
            $real = realpath($path);
            if ($real !== false && is_dir($real)) {
                $aufgeloest[(string) $name] = rtrim(str_replace('\\', '/', $real), '/');
            }
        }

        // Vom kürzesten Pfad aus: was darunter liegt, ist schon abgedeckt.
        uasort($aufgeloest, static fn (string $a, string $b): int => strlen($a) <=> strlen($b));

        $behalten = [];
        foreach ($aufgeloest as $name => $path) {
            $enthalten = false;
            foreach ($behalten as $vorhanden) {
                if ($path === $vorhanden || str_starts_with($path . '/', $vorhanden . '/')) {
                    $enthalten = true;
                    break;
                }
            }

            if (! $enthalten) {
                $behalten[$name] = $path;
            }
        }

        return $behalten;
    }
}
