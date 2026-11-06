<?php

declare(strict_types=1);

namespace App\Services\Cloning;

use App\Data\Cloning\CloningConfigData;
use App\Data\Cloning\KeyRemappingConfigData;
use App\Data\Cloning\KeyRemappingForeignKeyData;
use App\Data\Cloning\KeyRemappingTableData;
use App\Data\Cloning\TableCloningConfigData;
use App\Data\Schema\DatabaseSchemaData;
use App\Data\Schema\TableSchemaData;
use InvalidArgumentException;

/**
 * Maps table names written by the user (YAML keys, `skip:` entries, flags) to
 * the source table they mean: an exact match wins, then a unique
 * case-insensitive match. A name that matches nothing is kept as written.
 */
final readonly class TableNameResolver
{
    /** @var list<string> */
    private array $tableNames;

    public function __construct(DatabaseSchemaData $schema)
    {
        $this->tableNames = array_map(static fn (TableSchemaData $t): string => $t->name, $schema->tables);
    }

    /**
     * @throws InvalidArgumentException when the name matches several tables case-insensitively
     */
    public function resolve(string $name): string
    {
        if (in_array($name, $this->tableNames, true)) {
            return $name;
        }

        $matches = array_values(array_filter(
            $this->tableNames,
            static fn (string $table): bool => strcasecmp($table, $name) === 0,
        ));

        if (count($matches) > 1) {
            throw new InvalidArgumentException(sprintf(
                'Table name "%s" is ambiguous: it matches %s. Use the exact name.',
                $name,
                implode(', ', $matches),
            ));
        }

        return $matches[0] ?? $name;
    }

    /**
     * @param  list<string>  $names
     * @return list<string>
     *
     * @throws InvalidArgumentException when a name is ambiguous
     */
    public function resolveAll(array $names): array
    {
        return array_values(array_unique(array_map($this->resolve(...), $names))); // @pest-mutate-ignore: UnwrapArrayValues
    }

    /**
     * Returns the config with every `tables:` key, `skip:` entry and
     * `key_remapping` reference resolved.
     *
     * @throws InvalidArgumentException when a name is ambiguous, two `tables:` keys or two
     *                                  `key_remapping` entries resolve to the same table, or a
     *                                  `key_remapping` entry names a table without a `tables:` entry
     */
    public function resolveConfig(CloningConfigData $config): CloningConfigData
    {
        /** @var list<TableCloningConfigData> $resolved */
        $resolved = [];

        foreach ($config->tables as $table) {
            $name = $this->resolve($table->tableName);
            $earlier = array_find($resolved, static fn (TableCloningConfigData $t): bool => $t->tableName === $name);

            if ($earlier instanceof TableCloningConfigData) {
                throw new InvalidArgumentException(sprintf(
                    'Two tables: entries refer to table "%s". Keep one entry.',
                    $name,
                ));
            }

            $resolved[] = $name === $table->tableName ? $table : new TableCloningConfigData($name, $table->rows, $table->columns);
        }

        return new CloningConfigData(
            version: $config->version,
            connectionName: $config->connectionName,
            options: $config->options,
            tables: $resolved,
            keyRemapping: $this->resolveKeyRemapping($config->keyRemapping, $resolved),
            skipTables: $this->resolveAll($config->skipTables),
        );
    }

    /**
     * @param  list<TableCloningConfigData>  $tables  the resolved `tables:` entries
     *
     * @throws InvalidArgumentException
     */
    private function resolveKeyRemapping(?KeyRemappingConfigData $keyRemapping, array $tables): ?KeyRemappingConfigData
    {
        if (! $keyRemapping instanceof KeyRemappingConfigData) {
            return null;
        }

        $configured = array_map(static fn (TableCloningConfigData $t): string => $t->tableName, $tables);
        /** @var array<string, string> $writtenAs resolved name => name as written */
        $writtenAs = [];
        $resolved = [];

        foreach ($keyRemapping->tables as $entry) {
            $name = $this->resolve($entry->table);

            if (($writtenAs[$name] ?? $entry->table) !== $entry->table) {
                throw new InvalidArgumentException(sprintf(
                    'Two key_remapping entries refer to table "%s". Keep one entry.',
                    $name,
                ));
            }

            if (! in_array($name, $configured, true)) {
                throw new InvalidArgumentException(sprintf(
                    'key_remapping refers to table "%s", which has no tables: entry.',
                    $name,
                ));
            }

            $writtenAs[$name] = $entry->table;
            $resolved[] = new KeyRemappingTableData(
                table: $name,
                primaryKey: $entry->primaryKey,
                strategy: $entry->strategy,
                foreignKeys: array_map(
                    fn (KeyRemappingForeignKeyData $fk): KeyRemappingForeignKeyData => new KeyRemappingForeignKeyData(
                        table: $this->resolve($fk->table),
                        column: $fk->column,
                        selfReferential: $fk->selfReferential,
                    ),
                    $entry->foreignKeys,
                ),
            );
        }

        return new KeyRemappingConfigData(tables: $resolved);
    }
}
