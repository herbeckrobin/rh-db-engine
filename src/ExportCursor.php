<?php

declare(strict_types=1);

namespace RhDbEngine;

/**
 * Resume-Cursor für einen zustandsbehafteten Export.
 *
 * Analog zu {@see ImportCursor}: serialisierbar, hält den Fortschritt eines über mehrere
 * Hintergrund-Ticks laufenden Exports. Feature-frei (kein Sync-Wissen).
 */
final class ExportCursor
{
    public const PHASE_SQL = 'sql';
    public const PHASE_MANIFEST = 'manifest';
    public const PHASE_ZIP_DB = 'zip_db';
    public const PHASE_ZIP_UPLOADS = 'zip_uploads';
    public const PHASE_DONE = 'done';

    /**
     * @param array<int, string>      $excludedTables Vollqualifizierte Tabellennamen, die nicht gedumpt werden.
     * @param array<int, string>|null $rowKey         Primärschlüssel der zuletzt gedumpten Zeile. Damit blättert
     *                                                der Dump über den Schlüssel statt über OFFSET und übersteht
     *                                                Einfügungen und Löschungen während des Laufs.
     * @param string|null            $stopBefore     Phase, vor der angehalten wird. Für Aufrufer, die
     *                                                nur Dump und Manifest brauchen und das Archiv
     *                                                selbst erzeugen.
     * @param array<int, string>     $excludedOptions Options, die nicht in den Dump wandern. Ein Eintrag
     *                                                mit `*` am Ende schliesst alles mit diesem Anfang
     *                                                aus, sonst zählt der genaue Name. Greift nur auf
     *                                                der Options-Tabelle. Gedacht für instanzgebundene
     *                                                Schlüssel, die auf der Gegenseite nichts verloren
     *                                                haben (Kopplungen, Zugangsdaten, Protokolle).
     */
    public function __construct(
        public string $workdir,
        public bool $includeUploads = false,
        public array $excludedTables = [],
        public string $phase = self::PHASE_SQL,
        public ?string $sqlPath = null,
        public ?string $manifestPath = null,
        public ?string $zipPath = null,
        public int $tableIndex = 0,
        public int $rowOffset = 0,
        public int $uploadsFileIndex = 0,
        public bool $headerWritten = false,
        public ?string $targetDir = null,
        public ?array $rowKey = null,
        public ?string $stopBefore = null,
        public array $excludedOptions = [],
    ) {
    }

    /**
     * Gehört diese Option zu den ausgeschlossenen?
     *
     * Bewusst kein SQL-LIKE: dort ist `_` ein Platzhalter, und genau der steckt in jedem
     * WordPress-Optionsnamen. Ein Stern am Ende meint "alles mit diesem Anfang", alles
     * andere ist der genaue Name.
     *
     * @param array<int, string> $excluded
     */
    public static function optionExcluded(string $name, array $excluded): bool
    {
        foreach ($excluded as $muster) {
            $muster = (string) $muster;
            if ($muster === '') {
                continue;
            }

            if (str_ends_with($muster, '*')) {
                if (str_starts_with($name, substr($muster, 0, -1))) {
                    return true;
                }
                continue;
            }

            if ($name === $muster) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param string|null $targetDir Zielordner für das fertige ZIP. Null = der normale
     *                               backups/-Ordner. Wird für Sicherungskopien genutzt,
     *                               die nicht in der Backup-Liste des Nutzers auftauchen sollen.
     */
    public static function start(
        string $workdir,
        bool $includeUploads,
        array $excludedTables = [],
        ?string $targetDir = null,
        array $excludedOptions = []
    ): self {
        return new self(
            $workdir,
            $includeUploads,
            $excludedTables,
            targetDir: $targetDir,
            excludedOptions: $excludedOptions
        );
    }

    public function isDone(): bool
    {
        return $this->phase === self::PHASE_DONE;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'workdir' => $this->workdir,
            'include_uploads' => $this->includeUploads,
            'excluded_tables' => $this->excludedTables,
            'phase' => $this->phase,
            'sql_path' => $this->sqlPath,
            'manifest_path' => $this->manifestPath,
            'zip_path' => $this->zipPath,
            'table_index' => $this->tableIndex,
            'row_offset' => $this->rowOffset,
            'uploads_file_index' => $this->uploadsFileIndex,
            'header_written' => $this->headerWritten,
            'target_dir' => $this->targetDir,
            'row_key' => $this->rowKey,
            'stop_before' => $this->stopBefore,
            'excluded_options' => $this->excludedOptions,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            workdir: (string) ($data['workdir'] ?? ''),
            includeUploads: (bool) ($data['include_uploads'] ?? false),
            excludedTables: is_array($data['excluded_tables'] ?? null) ? array_map('strval', $data['excluded_tables']) : [],
            phase: (string) ($data['phase'] ?? self::PHASE_SQL),
            sqlPath: isset($data['sql_path']) ? (string) $data['sql_path'] : null,
            manifestPath: isset($data['manifest_path']) ? (string) $data['manifest_path'] : null,
            zipPath: isset($data['zip_path']) ? (string) $data['zip_path'] : null,
            tableIndex: (int) ($data['table_index'] ?? 0),
            rowOffset: (int) ($data['row_offset'] ?? 0),
            uploadsFileIndex: (int) ($data['uploads_file_index'] ?? 0),
            headerWritten: (bool) ($data['header_written'] ?? false),
            targetDir: isset($data['target_dir']) ? (string) $data['target_dir'] : null,
            rowKey: is_array($data['row_key'] ?? null) ? array_map('strval', $data['row_key']) : null,
            stopBefore: isset($data['stop_before']) ? (string) $data['stop_before'] : null,
            excludedOptions: is_array($data['excluded_options'] ?? null) ? array_map('strval', $data['excluded_options']) : [],
        );
    }
}
