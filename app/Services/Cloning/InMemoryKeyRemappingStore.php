<?php

declare(strict_types=1);

namespace App\Services\Cloning;

/**
 * Default in-memory key-remapping store.
 *
 * Holds all PK mappings for all tables in RAM simultaneously.
 * Fast and simple — suitable for databases where total mapping data fits
 * comfortably in PHP's configured memory limit.
 *
 * For very large databases use EncryptedFileKeyRemappingStore instead
 * (cloning:run --file-based) or remove the memory cap (--no-memory-limit).
 */
class InMemoryKeyRemappingStore implements KeyRemappingStoreInterface
{
    /** @var array<string, array<string, string>> [table => [oldValue => newValue]] */
    private array $mappings = [];

    /**
     * @param  array<string, string>  $mappings
     */
    public function storeTable(string $table, array $mappings): void
    {
        $this->mappings[$table] = $mappings;
    }

    public function lookup(string $table, string $oldValue): ?string
    {
        return $this->mappings[$table][$oldValue] ?? null;
    }

    public function cleanup(): void
    {
        $this->mappings = [];
    }
}
