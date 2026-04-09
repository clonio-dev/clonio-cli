<?php

declare(strict_types=1);

namespace App\Services\Cloning;

use App\Data\Cloning\KeyRemappingConfigData;
use App\Data\Cloning\KeyRemappingTableData;
use App\Data\ConnectionData;
use App\Enums\KeyRemappingStrategy;
use App\Services\Database\DatabaseConnectionService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class KeyRemappingService
{
    /**
     * In-memory maps: [tableName => [oldValue => newValue]]
     * Only populated when $fileStore is null (default in-memory mode).
     *
     * @var array<string, array<string, string>>
     */
    private array $mappings = [];

    public function __construct(
        private readonly DatabaseConnectionService $connector,
        private readonly ?EncryptedFileKeyRemappingStore $fileStore = null,
    ) {}

    /**
     * Phase 5b: Generate all PK mappings from source before transfer.
     * Returns [tableName => rowCount] for verbose logging.
     *
     * @param  list<string>  $orderedTables  Dependency-sorted table names
     * @return array<string, int>
     */
    public function generateMappings(
        KeyRemappingConfigData $config,
        ConnectionData $source,
        array $orderedTables,
    ): array {
        $counts = [];

        // Process in dependency order
        foreach ($orderedTables as $tableName) {
            $tableConfig = $config->getTable($tableName);
            if (! $tableConfig instanceof KeyRemappingTableData) {
                continue;
            }

            $counts[$tableName] = $this->generateTableMapping($tableConfig, $source);
        }

        return $counts;
    }

    /**
     * @return int Number of rows mapped
     */
    private function generateTableMapping(KeyRemappingTableData $config, ConnectionData $source): int
    {
        $connName = $this->connector->open($source);
        $pkCol = $config->primaryKey;
        $table = $config->table;

        try {
            $driver = $source->type->value;
            $quotedTable = match ($driver) {
                'mysql', 'mariadb' => '`'.$table.'`',
                'sqlsrv' => '['.$table.']',
                default => '"'.$table.'"',
            };
            $quotedCol = match ($driver) {
                'mysql', 'mariadb' => '`'.$pkCol.'`',
                'sqlsrv' => '['.$pkCol.']',
                default => '"'.$pkCol.'"',
            };

            /** @var list<object> $rows */
            $rows = DB::connection($connName)->select(
                sprintf('SELECT %s FROM %s', $quotedCol, $quotedTable)
            );

            /** @var array<string, string> $tableMappings */
            $tableMappings = [];
            $usedIntegers = [];

            foreach ($rows as $row) {
                $rowArray = (array) $row;
                $rawValue = $rowArray[$pkCol] ?? null;
                $oldValue = is_scalar($rawValue) ? (string) $rawValue : '';

                $newValue = match ($config->strategy) {
                    KeyRemappingStrategy::NewUuid => (string) Str::uuid(),
                    KeyRemappingStrategy::RandomInteger => $this->generateUniqueInteger(
                        $config->rangeMin,
                        $config->rangeMax,
                        $usedIntegers,
                        $table,
                    ),
                };

                $tableMappings[$oldValue] = $newValue;
            }

            if ($this->fileStore !== null) {
                // Persist to encrypted temp file; do not hold in RAM
                $this->fileStore->storeTable($table, $tableMappings);
            } else {
                $this->mappings[$table] = $tableMappings;
            }

            return count($tableMappings);
        } catch (Throwable) {
            return 0;
        } finally {
            DB::purge($connName);
        }
    }

    /**
     * @param  array<int, true>  $used
     */
    private function generateUniqueInteger(int $min, int $max, array &$used, string $table): string
    {
        $range = $max - $min;
        if ($range < 1) {
            throw new RuntimeException(
                sprintf("Key remapping range for table '%s' is exhausted (min=%d, max=%d).", $table, $min, $max)
            );
        }

        $attempts = 0;
        do {
            $value = random_int($min, $max);
            $attempts++;
            if ($attempts > $range * 2 + 100) {
                throw new RuntimeException(
                    sprintf("Key remapping range exhausted for table '%s'. Increase range_min/range_max.", $table)
                );
            }
        } while (isset($used[$value]));

        $used[$value] = true;

        return (string) $value;
    }

    /**
     * Apply PK and FK remapping to a single row during transfer.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    public function applyToRow(array $row, string $tableName, KeyRemappingConfigData $config): array
    {
        // Remap this table's own PK
        $tableConfig = $config->getTable($tableName);
        if ($tableConfig instanceof KeyRemappingTableData) {
            $pkCol = $tableConfig->primaryKey;
            if (array_key_exists($pkCol, $row)) {
                $rawPk = $row[$pkCol];
                $oldPk = is_scalar($rawPk) ? (string) $rawPk : '';
                $newPk = $this->fileStore !== null
                    ? ($this->fileStore->lookup($tableName, $oldPk) ?? $row[$pkCol])
                    : ($this->mappings[$tableName][$oldPk] ?? $row[$pkCol]);
                $row[$pkCol] = $newPk;
            }
        }

        // Remap FK columns on this table that reference other remapped tables
        foreach ($config->tables as $remappedTable) {
            foreach ($remappedTable->foreignKeys as $fk) {
                if ($fk->table !== $tableName) {
                    continue;
                }

                if (! array_key_exists($fk->column, $row)) {
                    continue;
                }

                $rawFk = $row[$fk->column];
                $fkValue = is_scalar($rawFk) ? (string) $rawFk : '';
                $newFk = $this->fileStore !== null
                    ? ($this->fileStore->lookup($remappedTable->table, $fkValue) ?? $row[$fk->column])
                    : ($this->mappings[$remappedTable->table][$fkValue] ?? $row[$fk->column]);
                $row[$fk->column] = $newFk;
            }
        }

        return $row;
    }

    /**
     * Phase 7: Release in-memory mappings and encrypted temp files.
     */
    public function cleanup(): void
    {
        $this->mappings = [];
        $this->fileStore?->cleanup();
    }

    /**
     * @return array<string, array<string, string>>
     */
    public function getMappings(): array
    {
        return $this->mappings;
    }
}
