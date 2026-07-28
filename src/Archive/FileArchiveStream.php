<?php

declare(strict_types=1);

namespace RhDbEngine\Archive;

/**
 * Liest aus einem fertigen Archiv auf der Platte.
 *
 * Der herkömmliche Weg: erst wird das Archiv vollständig geschrieben, dann übertragen.
 * Erprobt und für die meisten Installationen völlig ausreichend, solange genug Platz da
 * ist. Das Handle wird beim ersten Lesen geöffnet und bis zum Ende behalten, denn ein
 * Upload liest dieselbe Datei über viele Abschnitte hinweg.
 */
final class FileArchiveStream implements ArchiveStream
{
    /** @var resource|null */
    private $handle = null;

    private ?int $size = null;

    public function __construct(private readonly string $path)
    {
    }

    public function size(): int
    {
        if ($this->size === null) {
            if (! is_file($this->path)) {
                throw new \RuntimeException('Das lokale Backup ist verschwunden.');
            }

            $size = filesize($this->path);
            $this->size = $size === false ? 0 : (int) $size;
        }

        return $this->size;
    }

    public function readAt(int $offset, int $length): string
    {
        if ($length <= 0) {
            return '';
        }

        $handle = $this->handle();

        if (fseek($handle, $offset) !== 0) {
            throw new \RuntimeException('Im lokalen Backup konnte nicht an die richtige Stelle gesprungen werden.');
        }

        $chunk = fread($handle, $length);

        return $chunk === false ? '' : $chunk;
    }

    public function close(): void
    {
        if (is_resource($this->handle)) {
            fclose($this->handle);
        }

        $this->handle = null;
    }

    /**
     * @return resource
     */
    private function handle()
    {
        if (is_resource($this->handle)) {
            return $this->handle;
        }

        if (! is_file($this->path)) {
            throw new \RuntimeException('Das lokale Backup ist verschwunden, der Lauf kann nicht fortgesetzt werden.');
        }

        $handle = fopen($this->path, 'rb');
        if ($handle === false) {
            throw new \RuntimeException('Das lokale Backup ist nicht lesbar.');
        }

        $this->handle = $handle;

        return $handle;
    }
}
