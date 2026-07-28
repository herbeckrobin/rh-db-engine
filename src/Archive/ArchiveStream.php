<?php

declare(strict_types=1);

namespace RhDbEngine\Archive;

/**
 * Ein Archiv, aus dem sich an jeder Stelle lesen lässt.
 *
 * Der Sinn dieser Schnittstelle ist, dass der Leser nicht wissen muss, ob hinter dem
 * Archiv eine fertige Datei liegt oder ob es beim Lesen erst entsteht. Für den Upload
 * reicht beides, solange zwei Dinge feststehen: wie gross das Ganze wird, und was an
 * einer bestimmten Stelle steht.
 *
 * Das ist die Voraussetzung dafür, ein Archiv nach Google Drive zu übertragen, ohne es
 * vorher vollständig auf die Platte zu schreiben. Auf knappem Hosting ist genau das der
 * Unterschied zwischen "geht" und "geht nicht": wer 900 MB sichern will, hat oft keine
 * 900 MB frei.
 *
 * Wichtig für jede Umsetzung: die Grösse muss von Anfang an feststehen und darf sich
 * nicht mehr ändern. Der Upload teilt sich danach ein, und Google bekommt sie vorab
 * genannt.
 */
interface ArchiveStream
{
    /**
     * Gesamtgrösse des Archivs in Bytes. Steht ab dem ersten Aufruf fest.
     */
    public function size(): int;

    /**
     * Liest ab einer Stelle. Gibt weniger zurück, wenn das Archiv vorher endet.
     *
     * @throws \RuntimeException wenn an dieser Stelle nicht gelesen werden kann.
     */
    public function readAt(int $offset, int $length): string;

    /**
     * Gibt offene Handles frei. Danach wird nicht mehr gelesen.
     */
    public function close(): void;
}
