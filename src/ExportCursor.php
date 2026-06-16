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
     * @param array<int, string> $excludedTables Vollqualifizierte Tabellennamen, die nicht gedumpt werden.
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
    ) {
    }

    public static function start(string $workdir, bool $includeUploads, array $excludedTables = []): self
    {
        return new self($workdir, $includeUploads, $excludedTables);
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
        );
    }
}
