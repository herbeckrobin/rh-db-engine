<?php

declare(strict_types=1);

namespace RhDbEngine\Archive;

/**
 * Stand eines laufenden Verzeichnis-Durchgangs.
 *
 * Wie die anderen Cursor der Engine serialisierbar: ein Durchgang über zehntausende
 * Dateien passt in keinen einzelnen Request, und ein Abbruch darf nicht bedeuten, dass
 * wieder von vorn begonnen wird.
 *
 * Der offene Stapel führt die Verzeichnisse, die noch zu betreten sind. Dass daraus eine
 * Liste und keine Rekursion wird, ist Absicht: nur so lässt sich der Durchgang an jeder
 * Stelle anhalten und später fortsetzen.
 */
final class ScanCursor
{
    /**
     * @param array<int, array{name: string, path: string}> $roots     Noch nicht begonnene Wurzeln.
     * @param array<int, string>                            $pending   Offene Verzeichnisse der aktuellen Wurzel.
     * @param string                                        $rootName  Logischer Name der aktuellen Wurzel.
     * @param string                                        $rootPath  Aufgelöster Pfad der aktuellen Wurzel.
     */
    public function __construct(
        public array $roots = [],
        public array $pending = [],
        public string $rootName = '',
        public string $rootPath = '',
        public int $files = 0,
        public int $bytes = 0,
        public int $skipped = 0,
        public bool $done = false,
    ) {
    }

    /**
     * @param array<string, string> $roots Logischer Name im Archiv => absoluter Pfad.
     */
    public static function start(array $roots): self
    {
        $liste = [];
        foreach ($roots as $name => $path) {
            $liste[] = ['name' => (string) $name, 'path' => (string) $path];
        }

        return new self(roots: $liste);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'roots' => $this->roots,
            'pending' => $this->pending,
            'root_name' => $this->rootName,
            'root_path' => $this->rootPath,
            'files' => $this->files,
            'bytes' => $this->bytes,
            'skipped' => $this->skipped,
            'done' => $this->done,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $roots = [];
        foreach (is_array($data['roots'] ?? null) ? $data['roots'] : [] as $eintrag) {
            if (is_array($eintrag) && isset($eintrag['name'], $eintrag['path'])) {
                $roots[] = ['name' => (string) $eintrag['name'], 'path' => (string) $eintrag['path']];
            }
        }

        return new self(
            roots: $roots,
            pending: is_array($data['pending'] ?? null) ? array_map('strval', $data['pending']) : [],
            rootName: (string) ($data['root_name'] ?? ''),
            rootPath: (string) ($data['root_path'] ?? ''),
            files: (int) ($data['files'] ?? 0),
            bytes: (int) ($data['bytes'] ?? 0),
            skipped: (int) ($data['skipped'] ?? 0),
            done: (bool) ($data['done'] ?? false),
        );
    }
}
