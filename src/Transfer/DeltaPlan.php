<?php

declare(strict_types=1);

namespace RhDbEngine\Transfer;

/**
 * Vergleicht zwei Datei-Manifeste (Quelle vs. Ziel) und plant das Delta:
 * welche Dateien übertragen und welche auf dem Ziel gelöscht werden müssen.
 *
 * Feature-frei: kennt nur Manifeste (siehe {@see Manifest}), keine Peers oder Sync-Profile.
 */
final class DeltaPlan
{
    /**
     * @param array<string, array{size: int, mtime: int, hash?: string}> $transfer Zu übertragende Pfade.
     * @param array<int, string> $delete Auf dem Ziel zu entfernende Pfade.
     */
    public function __construct(
        public readonly array $transfer,
        public readonly array $delete,
    ) {
    }

    /**
     * @param array<string, array{size: int, mtime: int, hash?: string}> $source
     * @param array<string, array{size: int, mtime: int, hash?: string}> $target
     */
    public static function compute(array $source, array $target): self
    {
        $transfer = [];
        foreach ($source as $rel => $entry) {
            if (!isset($target[$rel]) || self::differs($entry, $target[$rel])) {
                $transfer[$rel] = $entry;
            }
        }

        $delete = [];
        foreach (array_keys($target) as $rel) {
            if (!isset($source[$rel])) {
                $delete[] = $rel;
            }
        }

        return new self($transfer, $delete);
    }

    /**
     * @return array<int, string>
     */
    public function transferPaths(): array
    {
        return array_keys($this->transfer);
    }

    public function totalTransferBytes(): int
    {
        $total = 0;
        foreach ($this->transfer as $entry) {
            $total += (int) ($entry['size'] ?? 0);
        }

        return $total;
    }

    public function isEmpty(): bool
    {
        return $this->transfer === [] && $this->delete === [];
    }

    /**
     * Entscheidet, ob sich eine Datei geändert hat. Wenn beide Seiten einen Hash tragen, ist er
     * maßgeblich. Sonst: andere Größe => geändert, oder neuere Quell-mtime => geändert.
     *
     * @param array{size: int, mtime: int, hash?: string} $source
     * @param array{size: int, mtime: int, hash?: string} $target
     */
    private static function differs(array $source, array $target): bool
    {
        if (isset($source['hash'], $target['hash']) && $source['hash'] !== '' && $target['hash'] !== '') {
            return $source['hash'] !== $target['hash'];
        }

        if ((int) ($source['size'] ?? -1) !== (int) ($target['size'] ?? -2)) {
            return true;
        }

        return (int) ($source['mtime'] ?? 0) > (int) ($target['mtime'] ?? 0);
    }
}
