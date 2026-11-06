<?php

declare(strict_types=1);

namespace App\Services\Cloning;

use App\Data\Cloning\CloningConfigData;
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
     * Returns the config with every `tables:` key and `skip:` entry resolved.
     *
     * @throws InvalidArgumentException when a name is ambiguous, or two `tables:` keys resolve to the same table
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
            keyRemapping: $config->keyRemapping,
            skipTables: $this->resolveAll($config->skipTables),
        );
    }
}
