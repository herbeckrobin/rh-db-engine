<?php

declare(strict_types=1);

namespace RhDbEngine\Transfer;

/**
 * Generischer Resume-Cursor für "übertrage eine Datei-Liste in Häppchen".
 *
 * Wird von der uploads-Phase des DB-Syncs UND später vom Filesystem-Sync genutzt: ein
 * einziger Mechanismus. Hält nur die Position (welche Datei, welcher Byte-Offset innerhalb),
 * keine Datei-Liste selbst (die ist serialisiert woanders, z.B. im DeltaPlan/Job-State).
 */
final class ChunkedFileCursor
{
    public function __construct(
        public int $fileIndex = 0,
        public int $byteOffset = 0,
        public bool $done = false,
    ) {
    }

    public function isDone(): bool
    {
        return $this->done;
    }

    /**
     * Rückt auf die nächste Datei vor (Offset zurück auf 0).
     */
    public function advanceFile(): void
    {
        $this->fileIndex++;
        $this->byteOffset = 0;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'file_index' => $this->fileIndex,
            'byte_offset' => $this->byteOffset,
            'done' => $this->done,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            fileIndex: (int) ($data['file_index'] ?? 0),
            byteOffset: (int) ($data['byte_offset'] ?? 0),
            done: (bool) ($data['done'] ?? false),
        );
    }
}
