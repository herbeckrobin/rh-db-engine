<?php

declare(strict_types=1);

namespace RhDbEngine\Archive;

/**
 * Ein Archiv, das beim Lesen erst entsteht.
 *
 * Der Kern der Sache: ohne Kompression ist ein Archiv keine Funktion seiner Geschichte,
 * sondern seines Inhaltsverzeichnisses. Jedes Byte jeder Datei erscheint unverändert an
 * einer vorher ausrechenbaren Stelle. Damit lässt sich aus einer beliebigen Position
 * zurückrechnen, in welcher Datei an welchem Offset man steht, und direkt dorthin
 * springen. Das Archiv wird zu einer Datei, die nie eine ist: sie hat eine feste Grösse,
 * ist an jeder Stelle lesbar und reproduzierbar, existiert aber nur im Moment des Lesens.
 *
 * Wozu der Aufwand: auf einem Paket mit einem Gigabyte Kontingent und einer Website von
 * 900 MB kann der herkömmliche Weg grundsätzlich nicht funktionieren, weil er nochmal
 * 900 MB freien Platz im selben Kontingent bräuchte. Dieser Weg braucht dauerhaft unter
 * einem Megabyte.
 *
 * Aufbau, durchgehend nach ZIP64, damit die Grösse von Anfang an feststeht und nicht
 * davon abhängt, wie gross es am Ende wird:
 *
 *   [Kopf][Datei] [Kopf][Datei] ... [Verzeichnis] [ZIP64-Ende] [Verweis] [Ende]
 *
 * Zwei Dinge sind nicht verhandelbar. Erstens muss die aufgezeichnete Länge erzwungen
 * werden: ändert sich eine Datei während des Laufs, verschieben sich sonst alle folgenden
 * Positionen und das Archiv wird unbrauchbar. Zweitens müssen die Prüfsummen vorher
 * feststehen, denn sie stehen im Kopf jeder Datei, also vor deren Inhalt.
 */
final class StreamingZipWriter implements ArchiveStream
{
    /** Fester Teil eines Datei-Kopfsatzes, ohne Namen und Zusatzfeld. */
    private const LOCAL_FIXED = 30;

    /** Zusatzfeld im Kopfsatz: Kennung, Länge, zwei 64-Bit-Grössen. */
    private const LOCAL_EXTRA = 20;

    /** Fester Teil eines Verzeichnis-Eintrags. */
    private const CENTRAL_FIXED = 46;

    /** Zusatzfeld im Verzeichnis: zusätzlich noch die Position im Archiv. */
    private const CENTRAL_EXTRA = 28;

    private const EOCD64 = 56;
    private const EOCD64_LOCATOR = 20;
    private const EOCD = 22;

    private ?int $size = null;

    /** @var resource|null */
    private $sourceHandle = null;

    private string $sourcePath = '';

    public function __construct(
        private readonly ArchiveIndex $index,
        private readonly string $trailerPath,
    ) {
    }

    /**
     * Platz, den ein Eintrag zusätzlich zu seinem Inhalt braucht.
     *
     * Der Index rechnet damit die Position jedes Eintrags aus, noch bevor der Writer
     * existiert. Deshalb hier als statische Funktion und nicht als Methode.
     */
    public static function overhead(): callable
    {
        return static fn (string $name): int => self::LOCAL_FIXED + strlen($name) + self::LOCAL_EXTRA;
    }

    public function size(): int
    {
        if ($this->size === null) {
            $anzahl = $this->index->count();
            $verzeichnis = $anzahl * (self::CENTRAL_FIXED + self::CENTRAL_EXTRA) + $this->index->nameBytes();

            $this->size = $this->index->archiveOffset()
                + $verzeichnis
                + self::EOCD64 + self::EOCD64_LOCATOR + self::EOCD;
        }

        return $this->size;
    }

    public function readAt(int $offset, int $length): string
    {
        if ($length <= 0 || $offset < 0 || $offset >= $this->size()) {
            return '';
        }

        $ausgabe = '';
        $rest = min($length, $this->size() - $offset);

        // Das schrittweise Anhängen ist hier nicht der Kostentreiber, auch wenn ein
        // Abschnitt aus hunderten kleinen Dateien besteht: PHP vergrössert den Zielstring
        // in Sprüngen, nicht bei jedem Schritt. Gemessen gegen ein Sammeln mit implode
        // war kein Unterschied festzustellen. Was ein Abschnitt wirklich kostet, ergibt
        // sich aus seiner Grösse, und die richtet sich nach dem Speicherlimit des Servers
        // (siehe UploadJob::chunkFromServerLimits).
        while ($rest > 0) {
            $stueck = $this->readPiece($offset, $rest);
            if ($stueck === '') {
                break;
            }

            $ausgabe .= $stueck;
            $offset += strlen($stueck);
            $rest -= strlen($stueck);
        }

        return $ausgabe;
    }

    public function close(): void
    {
        $this->closeSource();
    }

    /**
     * Schreibt das ganze Archiv in eine Datei.
     *
     * Nur zum Prüfen gedacht: so lässt sich vergleichen, ob das streamende Lesen genau
     * dasselbe ergibt wie ein Durchlauf am Stück.
     */
    public function writeTo(string $ziel, int $chunk = 1048576): int
    {
        $handle = fopen($ziel, 'wb');
        if ($handle === false) {
            throw new \RuntimeException('Das Archiv konnte nicht geschrieben werden.');
        }

        $geschrieben = 0;
        try {
            $offset = 0;
            while ($offset < $this->size()) {
                $daten = $this->readAt($offset, $chunk);
                if ($daten === '') {
                    break;
                }
                fwrite($handle, $daten);
                $offset += strlen($daten);
                $geschrieben += strlen($daten);
            }
        } finally {
            fclose($handle);
        }

        return $geschrieben;
    }

    // ============================================================
    // Innereien
    // ============================================================

    /**
     * Liefert so viel wie am Stück möglich, höchstens bis zur nächsten Grenze.
     */
    private function readPiece(int $offset, int $length): string
    {
        $dateiTeil = $this->index->archiveOffset();

        if ($offset >= $dateiTeil) {
            return $this->readTrailer($offset - $dateiTeil, $length);
        }

        $eintrag = $this->index->locate($offset);
        if ($eintrag === null) {
            return '';
        }

        $kopfLaenge = self::LOCAL_FIXED + strlen($eintrag['name']) + self::LOCAL_EXTRA;
        $datenAnfang = $eintrag['start'] + $kopfLaenge;

        if ($offset < $datenAnfang) {
            $kopf = $this->localHeader($eintrag['name'], $eintrag['size'], $this->index->crcAt($eintrag['index']));
            $ab = $offset - $eintrag['start'];

            return substr($kopf, $ab, $length);
        }

        $imInhalt = $offset - $datenAnfang;
        $wieViel = min($length, $eintrag['size'] - $imInhalt);

        return $wieViel > 0 ? $this->readSource($eintrag['source'], $imInhalt, $wieViel, $eintrag['size']) : '';
    }

    /**
     * Liest aus einer Quelldatei und erzwingt dabei die aufgezeichnete Länge.
     *
     * Wurde die Datei seit dem Durchgang kürzer, wird mit Nullbytes aufgefüllt, wurde sie
     * länger, wird abgeschnitten. Das klingt grob, ist aber die einzige Möglichkeit, ein
     * gültiges Archiv zu behalten: der Aufbau steht fest, und eine Datei, die sich nicht
     * daran hält, würde alles Folgende verschieben. Ein Archiv mit einer unvollständigen
     * Datei ist deutlich besser als eines, das sich gar nicht mehr öffnen lässt.
     */
    private function readSource(string $pfad, int $offset, int $length, int $erwartet): string
    {
        if ($pfad !== $this->sourcePath) {
            $this->closeSource();

            $handle = @fopen($pfad, 'rb');
            $this->sourceHandle = $handle === false ? null : $handle;
            $this->sourcePath = $pfad;
        }

        if (! is_resource($this->sourceHandle)) {
            // Die Datei ist seit dem Durchgang verschwunden. Der Platz bleibt trotzdem
            // reserviert, sonst wäre das ganze Archiv hinüber.
            return str_repeat("\0", $length);
        }

        if (fseek($this->sourceHandle, $offset) !== 0) {
            return str_repeat("\0", $length);
        }

        $daten = fread($this->sourceHandle, $length);
        if ($daten === false) {
            $daten = '';
        }

        if (strlen($daten) < $length) {
            $daten .= str_repeat("\0", $length - strlen($daten));
        }

        return substr($daten, 0, $length);
    }

    private function closeSource(): void
    {
        if (is_resource($this->sourceHandle)) {
            fclose($this->sourceHandle);
        }

        $this->sourceHandle = null;
        $this->sourcePath = '';
    }

    private function localHeader(string $name, int $size, int $crc): string
    {
        $extra = pack('vv', 0x0001, 16) . pack('PP', $size, $size);

        return pack('V', 0x04034b50)
            . pack('v', 45)                 // benötigte Version: ZIP64
            . pack('v', 0)                  // keine Besonderheiten
            . pack('v', 0)                  // Methode 0: unverändert gespeichert
            . pack('v', 0) . pack('v', 0)   // Zeit und Datum, bewusst leer
            . pack('V', $crc)
            . pack('V', 0xFFFFFFFF)         // echte Grössen stehen im Zusatzfeld
            . pack('V', 0xFFFFFFFF)
            . pack('v', strlen($name))
            . pack('v', strlen($extra))
            . $name
            . $extra;
    }

    /**
     * Verzeichnis und Abschluss, einmal erzeugt und dann aus der Datei gelesen.
     *
     * Bei zehntausenden Einträgen sind das einige Megabyte. Im Speicher zu halten wäre
     * auf knappem Hosting riskant, und neu zu erzeugen bei jedem Zugriff verschwenderisch.
     */
    private function readTrailer(int $offset, int $length): string
    {
        $this->ensureTrailer();

        $handle = @fopen($this->trailerPath, 'rb');
        if ($handle === false) {
            return '';
        }

        try {
            if (fseek($handle, $offset) !== 0) {
                return '';
            }

            $daten = fread($handle, $length);

            return $daten === false ? '' : $daten;
        } finally {
            fclose($handle);
        }
    }

    private function ensureTrailer(): void
    {
        $erwartet = $this->size() - $this->index->archiveOffset();

        clearstatcache(true, $this->trailerPath);
        if (is_file($this->trailerPath) && filesize($this->trailerPath) === $erwartet) {
            return;
        }

        $handle = fopen($this->trailerPath, 'wb');
        if ($handle === false) {
            throw new \RuntimeException('Das Inhaltsverzeichnis des Archivs konnte nicht geschrieben werden.');
        }

        try {
            $i = 0;
            foreach ($this->index->each() as $eintrag) {
                fwrite($handle, $this->centralEntry(
                    $eintrag['name'],
                    $eintrag['size'],
                    $this->index->crcAt($i),
                    $eintrag['start']
                ));
                $i++;
            }

            fwrite($handle, $this->endRecords($i));
        } finally {
            fclose($handle);
        }

        clearstatcache(true, $this->trailerPath);
        if (filesize($this->trailerPath) !== $erwartet) {
            throw new \RuntimeException(sprintf(
                'Das Inhaltsverzeichnis ist %d Byte gross, erwartet waren %d.',
                (int) filesize($this->trailerPath),
                $erwartet
            ));
        }
    }

    private function centralEntry(string $name, int $size, int $crc, int $offset): string
    {
        $extra = pack('vv', 0x0001, 24) . pack('PPP', $size, $size, $offset);

        return pack('V', 0x02014b50)
            . pack('v', 45) . pack('v', 45)
            . pack('v', 0)
            . pack('v', 0)
            . pack('v', 0) . pack('v', 0)
            . pack('V', $crc)
            . pack('V', 0xFFFFFFFF)
            . pack('V', 0xFFFFFFFF)
            . pack('v', strlen($name))
            . pack('v', strlen($extra))
            . pack('v', 0)                  // kein Kommentar
            . pack('v', 0)                  // erster Datenträger
            . pack('v', 0)                  // interne Merkmale
            . pack('V', 0)                  // externe Merkmale
            . pack('V', 0xFFFFFFFF)         // Position steht im Zusatzfeld
            . $name
            . $extra;
    }

    private function endRecords(int $anzahl): string
    {
        $verzeichnisAnfang = $this->index->archiveOffset();
        $verzeichnisGroesse = $anzahl * (self::CENTRAL_FIXED + self::CENTRAL_EXTRA) + $this->index->nameBytes();
        $eocd64Position = $verzeichnisAnfang + $verzeichnisGroesse;

        $eocd64 = pack('V', 0x06064b50)
            . pack('P', 44)                 // Länge des Datensatzes ab hier
            . pack('v', 45) . pack('v', 45)
            . pack('V', 0) . pack('V', 0)
            . pack('P', $anzahl) . pack('P', $anzahl)
            . pack('P', $verzeichnisGroesse) . pack('P', $verzeichnisAnfang);

        $locator = pack('V', 0x07064b50)
            . pack('V', 0)
            . pack('P', $eocd64Position)
            . pack('V', 1);

        $eocd = pack('V', 0x06054b50)
            . pack('v', 0) . pack('v', 0)
            . pack('v', 0xFFFF) . pack('v', 0xFFFF)
            . pack('V', 0xFFFFFFFF)
            . pack('V', 0xFFFFFFFF)
            . pack('v', 0);

        return $eocd64 . $locator . $eocd;
    }
}
