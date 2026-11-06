<?php

declare(strict_types=1);

namespace App\Services\Cloning;

use App\Data\Cloning\CloningConfigData;
use App\Data\Cloning\ColumnCloningConfigData;
use App\Data\Cloning\KeyRemappingConfigData;
use App\Data\Cloning\KeyRemappingForeignKeyData;
use App\Data\Cloning\KeyRemappingTableData;
use App\Data\Cloning\TableCloningConfigData;
use App\Data\Schema\ColumnSchemaData;
use App\Data\Schema\DatabaseSchemaData;
use App\Data\Schema\TableSchemaData;
use InvalidArgumentException;

/**
 * Maps table names written by the user (YAML keys, `skip:` entries, flags) to
 * the source table they mean: an exact match wins, then a unique
 * case-insensitive match. A name that matches nothing is kept as written.
 *
 * A `tables:` key or `skip:` entry written as `/pattern/flags` (see
 * {@see TablePattern}) stands for every table whose name matches it.
 */
final readonly class TableNameResolver
{
    /** @var list<string> */
    private array $tableNames;

    public function __construct(private DatabaseSchemaData $schema)
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
     * `key_remapping` reference resolved. A pattern key becomes one entry per
     * matching source table, in schema order, with only the column rules for
     * columns that table has. A pattern `skip:` entry stands for every
     * configured table it matches.
     *
     * @throws InvalidArgumentException when a name is ambiguous, a table matches two `tables:`
     *                                  entries, two `key_remapping` entries resolve to the same
     *                                  table, a `key_remapping` entry names a table without a
     *                                  `tables:` entry, or a pattern does not compile
     */
    public function resolveConfig(CloningConfigData $config): CloningConfigData
    {
        /** @var list<TableCloningConfigData> $resolved */
        $resolved = [];
        /** @var array<string, string> $owner resolved table name => tables: key as written */
        $owner = [];

        foreach ($config->tables as $table) {
            foreach ($this->expand($table) as $entry) {
                $earlier = $owner[$entry->tableName] ?? null;

                if ($earlier !== null) {
                    throw new InvalidArgumentException(sprintf(
                        'Table "%s" matches more than one tables: entry: "%s" and "%s". Each table may match only one entry.',
                        $entry->tableName,
                        $earlier,
                        $table->tableName,
                    ));
                }

                $owner[$entry->tableName] = $table->tableName;
                $resolved[] = $entry;
            }
        }

        $configured = array_map(static fn (TableCloningConfigData $t): string => $t->tableName, $resolved);

        return new CloningConfigData(
            version: $config->version,
            connectionName: $config->connectionName,
            options: $config->options,
            tables: $resolved,
            keyRemapping: $this->resolveKeyRemapping($config->keyRemapping, $resolved),
            skipTables: $this->resolveSkipTables($config->skipTables, $configured),
        );
    }

    /**
     * Returns the patterns in the written config that match no table: `tables:`
     * keys checked against the source schema, `skip:` entries against the
     * tables of the resolved config.
     *
     * @return list<string>
     *
     * @throws InvalidArgumentException when a pattern does not compile
     */
    public function unmatchedPatterns(CloningConfigData $written, CloningConfigData $resolved): array
    {
        $configured = array_map(static fn (TableCloningConfigData $t): string => $t->tableName, $resolved->tables);
        $unmatched = [];

        foreach ($written->tables as $table) {
            if (TablePattern::isPattern($table->tableName) && $this->matching($table->tableName, $this->tableNames) === []) {
                $unmatched[] = $table->tableName;
            }
        }

        foreach ($written->skipTables as $name) {
            if (TablePattern::isPattern($name) && $this->matching($name, $configured) === []) {
                $unmatched[] = $name;
            }
        }

        return array_values(array_unique($unmatched)); // @pest-mutate-ignore: UnwrapArrayValues
    }

    /**
     * @return list<TableCloningConfigData>
     *
     * @throws InvalidArgumentException
     */
    private function expand(TableCloningConfigData $table): array
    {
        if (! TablePattern::isPattern($table->tableName)) {
            $name = $this->resolve($table->tableName);

            return [$name === $table->tableName ? $table : new TableCloningConfigData($name, $table->rows, $table->columns)];
        }

        return array_map(
            fn (string $name): TableCloningConfigData => new TableCloningConfigData($name, $table->rows, $this->existingColumns($name, $table->columns)),
            $this->matching($table->tableName, $this->tableNames),
        );
    }

    /**
     * Keeps the column rules for the columns the source table has, so one
     * pattern entry can carry rules for columns only some tables have.
     *
     * @param  list<ColumnCloningConfigData>  $columns
     * @return list<ColumnCloningConfigData>
     */
    private function existingColumns(string $tableName, array $columns): array
    {
        $names = array_map(static fn (ColumnSchemaData $c): string => $c->name, $this->schema->getTable($tableName)->columns ?? []);

        return array_values(array_filter( // @pest-mutate-ignore: UnwrapArrayValues
            $columns,
            static fn (ColumnCloningConfigData $c): bool => in_array($c->columnName, $names, true),
        ));
    }

    /**
     * @param  list<string>  $names  the `skip:` entries as written
     * @param  list<string>  $configured  the resolved `tables:` names
     * @return list<string>
     *
     * @throws InvalidArgumentException
     */
    private function resolveSkipTables(array $names, array $configured): array
    {
        $resolved = [];

        foreach ($names as $name) {
            if (TablePattern::isPattern($name)) {
                array_push($resolved, ...$this->matching($name, $configured));
            } else {
                $resolved[] = $this->resolve($name);
            }
        }

        return array_values(array_unique($resolved)); // @pest-mutate-ignore: UnwrapArrayValues
    }

    /**
     * @param  list<string>  $tableNames
     * @return list<string>
     *
     * @throws InvalidArgumentException when the pattern does not compile
     */
    private function matching(string $pattern, array $tableNames): array
    {
        return array_values(array_filter( // @pest-mutate-ignore: UnwrapArrayValues
            $tableNames,
            static fn (string $name): bool => TablePattern::matches($pattern, $name),
        ));
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
                        typeColumn: $fk->typeColumn,
                        typeValue: $fk->typeValue,
                    ),
                    $entry->foreignKeys,
                ),
            );
        }

        return new KeyRemappingConfigData(tables: $resolved);
    }
}
