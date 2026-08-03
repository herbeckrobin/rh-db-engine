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
    public const PHASE_SWAP = 'swap';
    public const PHASE_CONSTRAINTS = 'constraints';
    public const PHASE_UPLOADS = 'uploads';
    public const PHASE_DONE = 'done';

    /**
     * @param array<string, mixed> $manifest Aus dem Backup gelesenes Manifest (nach extract gefüllt).
     * @param bool $swapMode Import schreibt in Schattentabellen und schaltet am Ende atomar um.
     * @param string $workPrefix Prefix, unter dem dieser Import arbeitet. Im Umschalt-Modus der
     *                           Stage-Prefix, sonst der Ziel-Prefix.
     * @param array<int, string> $createdTables Basisnamen (ohne Prefix) der vom Dump angelegten
     *                                          Tabellen. Das ist die Liste, die umgeschaltet wird.
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
        public int $uploadsFailed = 0,
        public string $targetSiteUrl = '',
        public string $targetHomeUrl = '',
        public bool $swapMode = false,
        public string $workPrefix = '',
        public array $createdTables = [],
        public bool $swapDone = false,
        public array $deferredKeys = [],
        public int $deferredKeyIndex = 0,
    ) {
    }

    /**
     * Wurden die Live-Daten dieses Imports schon angefasst?
     *
     * Im Umschalt-Modus erst mit dem `RENAME TABLE`. Vorher liegt alles in Schattentabellen,
     * ein Fehlschlag braucht dann auch kein Zurückspielen: es gibt nichts zurückzuspielen.
     */
    public function liveDataTouched(): bool
    {
        return !$this->swapMode || $this->swapDone;
    }

    public static function start(string $zipPath, string $workdir): self
    {
        return new self($zipPath, $workdir);
    }

    /**
     * Prefix, unter dem dieser Import gerade schreibt.
     *
     * Im Umschalt-Modus der Stage-Prefix, sonst der Ziel-Prefix. Der Fallback auf den
     * Ziel-Prefix greift auch für Cursor aus einer älteren Version, die das Feld nicht kennen.
     */
    public function effectivePrefix(): string
    {
        return $this->workPrefix !== '' ? $this->workPrefix : $this->targetPrefix;
    }

    /**
     * Merkt sich eine vom Dump angelegte Tabelle (Basisname ohne Prefix).
     */
    public function rememberTable(string $baseName): void
    {
        if ($baseName !== '' && !in_array($baseName, $this->createdTables, true)) {
            $this->createdTables[] = $baseName;
        }
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
            'uploads_failed' => $this->uploadsFailed,
            'target_site_url' => $this->targetSiteUrl,
            'target_home_url' => $this->targetHomeUrl,
            'swap_mode' => $this->swapMode,
            'work_prefix' => $this->workPrefix,
            'created_tables' => $this->createdTables,
            'swap_done' => $this->swapDone,
            'deferred_keys' => $this->deferredKeys,
            'deferred_key_index' => $this->deferredKeyIndex,
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
            uploadsFailed: (int) ($data['uploads_failed'] ?? 0),
            targetSiteUrl: (string) ($data['target_site_url'] ?? ''),
            targetHomeUrl: (string) ($data['target_home_url'] ?? ''),
            swapMode: (bool) ($data['swap_mode'] ?? false),
            workPrefix: (string) ($data['work_prefix'] ?? ''),
            createdTables: array_values(array_map(
                'strval',
                is_array($data['created_tables'] ?? null) ? $data['created_tables'] : []
            )),
            swapDone: (bool) ($data['swap_done'] ?? false),
            deferredKeys: is_array($data['deferred_keys'] ?? null) ? $data['deferred_keys'] : [],
            deferredKeyIndex: (int) ($data['deferred_key_index'] ?? 0),
        );
    }
}
