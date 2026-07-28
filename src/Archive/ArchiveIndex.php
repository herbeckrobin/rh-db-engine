<?php

declare(strict_types=1);

namespace RhDbEngine\Archive;

/**
 * Die Liste aller Dateien, die ins Archiv kommen.
 *
 * Bewusst auf der Platte und nicht im Speicher: bei einer WordPress-Installation mit
 * Plugins sind das schnell zehntausende Einträge, und ein Array davon kostet mehr als
 * das Speicherlimit auf knappem Hosting hergibt. Der Index ist damit auch das, was einen
 * über viele Requests laufenden Durchgang überhaupt möglich macht.
 *
 * Zwei Dateien: die lesbare Liste als Tabulator-Text, und daneben eine Suchhilfe mit
 * festen Satzlängen. Die Suchhilfe ist der Grund, warum sich aus einer Byte-Position im
 * Archiv in Sekundenbruchteilen der zugehörige Eintrag finden lässt, ohne die ganze Liste
 * zu lesen. Ohne sie müsste ein Upload mit hundert Abschnitten hundertmal durch
 * zehntausende Zeilen laufen.
 */
final class ArchiveIndex
{
    /** Bytes je Satz in der Suchhilfe: Start im Archiv, Position in der Liste. */
    private const RECORD = 16;

    /** @var resource|null */
    private $listHandle = null;

    /** @var resource|null */
    private $seekHandle = null;

    /** @var resource|null */
    private $crcHandle = null;

    private int $count = 0;
    private int $bytes = 0;
    private int $nameBytes = 0;
    private int $archiveOffset = 0;

    /**
     * @param string        $listPath  Die lesbare Liste.
     * @param callable|null $overhead  Liefert je Eintrag den Platz, den das Archivformat
     *                                 zusätzlich zum Inhalt braucht (Kopfsatz). Ohne
     *                                 Angabe wird nur der Inhalt gerechnet.
     */
    public function __construct(
        private readonly string $listPath,
        private $overhead = null,
    ) {
    }

    public function seekPath(): string
    {
        return $this->listPath . '.seek';
    }

    public function crcPath(): string
    {
        return $this->listPath . '.crc';
    }

    public function listPath(): string
    {
        return $this->listPath;
    }

    /**
     * Beginnt eine neue Liste. Vorhandene Reste eines abgebrochenen Laufs verschwinden.
     */
    public function reset(): void
    {
        $this->close();

        foreach ([$this->listPath, $this->seekPath(), $this->crcPath()] as $pfad) {
            if (is_file($pfad)) {
                // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Reste eines früheren Laufs.
                @unlink($pfad);
            }
        }

        $this->count = 0;
        $this->bytes = 0;
        $this->nameBytes = 0;
        $this->archiveOffset = 0;
    }

    /**
     * Nimmt einen bereits begonnenen Index wieder auf.
     */
    public function resume(int $count, int $bytes, int $archiveOffset, int $nameBytes = 0): void
    {
        $this->count = $count;
        $this->bytes = $bytes;
        $this->archiveOffset = $archiveOffset;
        $this->nameBytes = $nameBytes;
    }

    /**
     * Hängt eine Datei an.
     *
     * Der Name im Archiv darf keine Tabulatoren und Zeilenumbrüche enthalten, sonst wäre
     * die Liste nicht mehr eindeutig lesbar. Solche Namen kommen selten vor, sind aber
     * möglich, und dann lieber übergehen als eine unlesbare Liste erzeugen.
     */
    public function append(string $name, string $source, int $size, int $mtime): void
    {
        if ($this->hasControlChars($name) || $this->hasControlChars($source)) {
            return;
        }

        $listOffset = $this->listSize();

        // Gespeichert wird der Anfang des ganzen Eintrags, also einschliesslich seines
        // Kopfsatzes. Nur so trifft die Suche auch dann den richtigen Eintrag, wenn die
        // gesuchte Stelle noch im Kopfsatz liegt und nicht erst in den Daten.
        $start = $this->archiveOffset;

        fwrite($this->list(), implode("\t", [$name, $source, (string) $size, (string) $mtime]) . "\n");
        fwrite($this->seek(), pack('PP', $start, $listOffset));

        $this->archiveOffset = $start + $this->overheadFor($name) + $size;
        $this->bytes += $size;
        $this->nameBytes += strlen($name);
        $this->count++;
    }

    /**
     * Summe der Namenslängen. Wird gebraucht, um die Grösse des Verzeichnisses am Ende
     * des Archivs auszurechnen, ohne die ganze Liste zu lesen.
     */
    public function nameBytes(): int
    {
        return $this->nameBytes;
    }

    public function count(): int
    {
        return $this->count;
    }

    /**
     * Summe der Dateiinhalte, ohne den Platz, den das Archivformat zusätzlich braucht.
     */
    public function bytes(): int
    {
        return $this->bytes;
    }

    /**
     * Position hinter dem letzten Eintrag. Das ist der Anfang dessen, was am Ende des
     * Archivs noch folgt.
     */
    public function archiveOffset(): int
    {
        return $this->archiveOffset;
    }

    /**
     * Hängt die Prüfsumme des nächsten Eintrags an.
     *
     * Die Prüfsummen kommen in einer eigenen Runde nach dem Durchgang, weil sie im
     * Kopfsatz jeder Datei stehen und damit feststehen müssen, bevor auch nur ein Byte
     * des Archivs gelesen wird. Sie werden der Reihe nach angehängt, dadurch ist der
     * Zähler zugleich der Stand: eine Runde, die abbricht, macht später dort weiter.
     */
    public function appendCrc(int $crc): void
    {
        fwrite($this->crc(), pack('V', $crc));
    }

    /**
     * Für wie viele Einträge die Prüfsumme schon feststeht.
     */
    public function crcCount(): int
    {
        if (is_resource($this->crcHandle)) {
            fflush($this->crcHandle);
        }

        if (! is_file($this->crcPath())) {
            return 0;
        }

        clearstatcache(true, $this->crcPath());
        $size = filesize($this->crcPath());

        return $size === false ? 0 : intdiv((int) $size, 4);
    }

    public function crcAt(int $index): int
    {
        if ($index < 0) {
            return 0;
        }

        $handle = @fopen($this->crcPath(), 'rb');
        if ($handle === false) {
            return 0;
        }

        try {
            if (fseek($handle, $index * 4) !== 0) {
                return 0;
            }

            $roh = fread($handle, 4);
            if ($roh === false || strlen($roh) !== 4) {
                return 0;
            }

            /** @var array{1: int} $werte */
            $werte = unpack('V', $roh);

            return (int) $werte[1];
        } finally {
            fclose($handle);
        }
    }

    public function flush(): void
    {
        foreach ([$this->listHandle, $this->seekHandle, $this->crcHandle] as $handle) {
            if (is_resource($handle)) {
                fflush($handle);
            }
        }
    }

    public function close(): void
    {
        foreach ([$this->listHandle, $this->seekHandle, $this->crcHandle] as $handle) {
            if (is_resource($handle)) {
                fclose($handle);
            }
        }

        $this->listHandle = null;
        $this->seekHandle = null;
        $this->crcHandle = null;
    }

    /**
     * Alle Einträge der Reihe nach.
     *
     * @return \Generator<int, array{name: string, source: string, size: int, mtime: int, start: int}>
     */
    public function each(): \Generator
    {
        $this->flush();

        $liste = @fopen($this->listPath, 'rb');
        if ($liste === false) {
            return;
        }

        $i = 0;
        try {
            while (($zeile = fgets($liste)) !== false) {
                $eintrag = $this->parse($zeile);
                if ($eintrag === null) {
                    continue;
                }

                $eintrag['start'] = $this->startOf($i);
                yield $i => $eintrag;
                $i++;
            }
        } finally {
            fclose($liste);
        }
    }

    /**
     * Welcher Eintrag liegt an dieser Stelle im Archiv?
     *
     * Gesucht wird über die Suchhilfe, nicht über die Liste: bei zehntausend Einträgen
     * sind das vierzehn Sprünge statt zehntausend gelesener Zeilen.
     *
     * @return array{name: string, source: string, size: int, mtime: int, start: int, index: int}|null
     */
    public function locate(int $archiveOffset): ?array
    {
        $this->flush();

        $anzahl = $this->recordCount();
        if ($anzahl === 0 || $archiveOffset < 0) {
            return null;
        }

        $lo = 0;
        $hi = $anzahl - 1;
        $treffer = null;

        while ($lo <= $hi) {
            $mitte = intdiv($lo + $hi, 2);
            $satz = $this->record($mitte);
            if ($satz === null) {
                return null;
            }

            if ($satz['start'] <= $archiveOffset) {
                $treffer = $mitte;
                $lo = $mitte + 1;
            } else {
                $hi = $mitte - 1;
            }
        }

        if ($treffer === null) {
            return null;
        }

        $satz = $this->record($treffer);
        if ($satz === null) {
            return null;
        }

        $eintrag = $this->entryAtListOffset($satz['list']);
        if ($eintrag === null) {
            return null;
        }

        $eintrag['start'] = $satz['start'];
        $eintrag['index'] = $treffer;

        return $eintrag;
    }

    /**
     * Der Eintrag mit dieser laufenden Nummer.
     *
     * @return array{name: string, source: string, size: int, mtime: int, start: int}|null
     */
    public function at(int $index): ?array
    {
        $satz = $this->record($index);
        if ($satz === null) {
            return null;
        }

        $eintrag = $this->entryAtListOffset($satz['list']);
        if ($eintrag === null) {
            return null;
        }

        $eintrag['start'] = $satz['start'];

        return $eintrag;
    }

    // ============================================================
    // Innereien
    // ============================================================

    private function overheadFor(string $name): int
    {
        if (! is_callable($this->overhead)) {
            return 0;
        }

        return (int) ($this->overhead)($name);
    }

    private function hasControlChars(string $wert): bool
    {
        return strpbrk($wert, "\t\r\n") !== false;
    }

    /**
     * @return array{name: string, source: string, size: int, mtime: int}|null
     */
    private function parse(string $zeile): ?array
    {
        $teile = explode("\t", rtrim($zeile, "\r\n"));
        if (count($teile) !== 4) {
            return null;
        }

        return [
            'name' => $teile[0],
            'source' => $teile[1],
            'size' => (int) $teile[2],
            'mtime' => (int) $teile[3],
        ];
    }

    /**
     * @return array{name: string, source: string, size: int, mtime: int}|null
     */
    private function entryAtListOffset(int $offset): ?array
    {
        $liste = @fopen($this->listPath, 'rb');
        if ($liste === false) {
            return null;
        }

        try {
            if (fseek($liste, $offset) !== 0) {
                return null;
            }

            $zeile = fgets($liste);

            return $zeile === false ? null : $this->parse($zeile);
        } finally {
            fclose($liste);
        }
    }

    /**
     * @return array{start: int, list: int}|null
     */
    private function record(int $index): ?array
    {
        if ($index < 0) {
            return null;
        }

        $handle = @fopen($this->seekPath(), 'rb');
        if ($handle === false) {
            return null;
        }

        try {
            if (fseek($handle, $index * self::RECORD) !== 0) {
                return null;
            }

            $roh = fread($handle, self::RECORD);
            if ($roh === false || strlen($roh) !== self::RECORD) {
                return null;
            }

            /** @var array{1: int, 2: int} $werte */
            $werte = unpack('Pstart/Plist', $roh);

            return ['start' => (int) $werte['start'], 'list' => (int) $werte['list']];
        } finally {
            fclose($handle);
        }
    }

    private function startOf(int $index): int
    {
        $satz = $this->record($index);

        return $satz === null ? 0 : $satz['start'];
    }

    private function recordCount(): int
    {
        if (! is_file($this->seekPath())) {
            return 0;
        }

        $size = filesize($this->seekPath());

        return $size === false ? 0 : intdiv((int) $size, self::RECORD);
    }

    private function listSize(): int
    {
        if (is_resource($this->listHandle)) {
            fflush($this->listHandle);
        }

        if (! is_file($this->listPath)) {
            return 0;
        }

        clearstatcache(true, $this->listPath);
        $size = filesize($this->listPath);

        return $size === false ? 0 : (int) $size;
    }

    /**
     * @return resource
     */
    private function list()
    {
        if (! is_resource($this->listHandle)) {
            $handle = fopen($this->listPath, 'ab');
            if ($handle === false) {
                throw new \RuntimeException('Die Dateiliste des Archivs konnte nicht geschrieben werden.');
            }
            $this->listHandle = $handle;
        }

        return $this->listHandle;
    }

    /**
     * @return resource
     */
    private function seek()
    {
        if (! is_resource($this->seekHandle)) {
            $handle = fopen($this->seekPath(), 'ab');
            if ($handle === false) {
                throw new \RuntimeException('Die Suchhilfe des Archivs konnte nicht geschrieben werden.');
            }
            $this->seekHandle = $handle;
        }

        return $this->seekHandle;
    }

    /**
     * @return resource
     */
    private function crc()
    {
        if (! is_resource($this->crcHandle)) {
            $handle = fopen($this->crcPath(), 'ab');
            if ($handle === false) {
                throw new \RuntimeException('Die Prüfsummen des Archivs konnten nicht geschrieben werden.');
            }
            $this->crcHandle = $handle;
        }

        return $this->crcHandle;
    }
}
