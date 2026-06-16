<?php

declare(strict_types=1);

namespace RhDbEngine;

/**
 * Resume-Cursor für einen zustandsbehafteten Import.
 *
 * Beschreibt exakt, wo der nächste Tick weitermacht. Serialisierbar (toArray/fromArray),
 * damit der Zustand zwischen einzelnen Hintergrund-Requests im Job-State überlebt. Hält
 * KEIN Callable (Table-Filter wird pro Tick vom Aufrufer übergeben) und KEIN Sync-Wissen,
 * damit die db-engine feature-frei bleibt.
 */
final class ImportCursor
{
    public const PHASE_EXTRACT = 'extract';
    public const PHASE_SQL = 'sql';
    public const PHASE_META_REWRITE = 'meta_rewrite';
    public const PHASE_URL_REWRITE = 'url_rewrite';
    public const PHASE_UPLOADS = 'uploads';
    public const PHASE_DONE = 'done';

    /**
     * @param array<string, mixed> $manifest Aus dem Backup gelesenes Manifest (nach extract gefüllt).
     */
    public function __construct(
        public string $zipPath,
        public string $workdir,
        public string $phase = self::PHASE_EXTRACT,
        public int $sqlByteOffset = 0,
        public ?string $currentTable = null,
        public int $urlRewriteTableIndex = 0,
        public int $urlRewriteRowOffset = 0,
        public int $uploadsFileIndex = 0,
        public string $sourcePrefix = '',
        public string $targetPrefix = '',
        public bool $includesUploads = false,
        public array $manifest = [],
    ) {
    }

    public static function start(string $zipPath, string $workdir): self
    {
        return new self($zipPath, $workdir);
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
            'zip_path' => $this->zipPath,
            'workdir' => $this->workdir,
            'phase' => $this->phase,
            'sql_byte_offset' => $this->sqlByteOffset,
            'current_table' => $this->currentTable,
            'url_rewrite_table_index' => $this->urlRewriteTableIndex,
            'url_rewrite_row_offset' => $this->urlRewriteRowOffset,
            'uploads_file_index' => $this->uploadsFileIndex,
            'source_prefix' => $this->sourcePrefix,
            'target_prefix' => $this->targetPrefix,
            'includes_uploads' => $this->includesUploads,
            'manifest' => $this->manifest,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            zipPath: (string) ($data['zip_path'] ?? ''),
            workdir: (string) ($data['workdir'] ?? ''),
            phase: (string) ($data['phase'] ?? self::PHASE_EXTRACT),
            sqlByteOffset: (int) ($data['sql_byte_offset'] ?? 0),
            currentTable: isset($data['current_table']) ? (string) $data['current_table'] : null,
            urlRewriteTableIndex: (int) ($data['url_rewrite_table_index'] ?? 0),
            urlRewriteRowOffset: (int) ($data['url_rewrite_row_offset'] ?? 0),
            uploadsFileIndex: (int) ($data['uploads_file_index'] ?? 0),
            sourcePrefix: (string) ($data['source_prefix'] ?? ''),
            targetPrefix: (string) ($data['target_prefix'] ?? ''),
            includesUploads: (bool) ($data['includes_uploads'] ?? false),
            manifest: is_array($data['manifest'] ?? null) ? $data['manifest'] : [],
        );
    }
}
