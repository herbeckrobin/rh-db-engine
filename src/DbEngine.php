<?php

declare(strict_types=1);

namespace RhDbEngine;

/**
 * Singleton der DB-Engine. Wird einmal pro Request gebootet (Negotiation-Gewinner)
 * und ist über `rh_db_engine()` erreichbar. Hält Storage, Exporter und Importer.
 */
final class DbEngine
{
    private static ?self $instance = null;

    private Storage $storage;

    private Exporter $exporter;

    private Importer $importer;

    private function __construct(
        private readonly string $version,
        private readonly string $dir,
    ) {
        $this->storage = new Storage();
        $searchReplace = new SearchReplace();
        $this->exporter = new Exporter($this->storage);
        $this->importer = new Importer($this->storage, $searchReplace);
    }

    public static function boot(string $version, string $dir): void
    {
        if (self::$instance !== null) {
            return;
        }

        self::$instance = new self($version, $dir);
    }

    public static function instance(): self
    {
        if (self::$instance === null) {
            throw new \RuntimeException('RH DB-Engine wurde noch nicht gebootet.');
        }

        return self::$instance;
    }

    public static function isBooted(): bool
    {
        return self::$instance !== null;
    }

    public function storage(): Storage
    {
        return $this->storage;
    }

    public function exporter(): Exporter
    {
        return $this->exporter;
    }

    public function importer(): Importer
    {
        return $this->importer;
    }

    public function version(): string
    {
        return $this->version;
    }

    public function dir(): string
    {
        return $this->dir;
    }
}
