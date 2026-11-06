<?php

declare(strict_types=1);

namespace App\Services\Cloning;

use App\Data\Cloning\CloningConfigData;
use App\Data\Cloning\KeyRemappingTableData;
use App\Data\Schema\ColumnSchemaData;
use App\Data\Schema\DatabaseSchemaData;
use App\Data\Schema\TableSchemaData;
use App\Enums\KeyRemappingStrategy;
use App\Exceptions\UnsupportedKeyColumnTypeException;

/**
 * Checks key remapping entries against the source schema before any mapping
 * is generated, so a config that cannot work fails as a validation error.
 */
final readonly class KeyRemappingPreflight
{
    public function __construct(
        private KeyTypeBoundsResolver $boundsResolver = new KeyTypeBoundsResolver,
    ) {}

    /**
     * Checks the tables that get key mappings: every table without `rows.strategy: schema`.
     *
     * @return list<string> one message per problem
     */
    public function problems(CloningConfigData $config, DatabaseSchemaData $sourceSchema): array
    {
        $problems = [];

        foreach ($config->tables as $table) {
            $entry = $config->keyRemapping?->getTable($table->tableName);

            if ($table->rows->strategy === 'schema' || ! $entry instanceof KeyRemappingTableData) {
                continue;
            }

            $problem = $this->problem($entry, $sourceSchema);

            if ($problem !== null) {
                $problems[] = $problem;
            }
        }

        return $problems;
    }

    private function problem(KeyRemappingTableData $entry, DatabaseSchemaData $sourceSchema): ?string
    {
        $label = $entry->table.'.'.$entry->primaryKey;
        $tableSchema = $sourceSchema->getTable($entry->table);

        if (! $tableSchema instanceof TableSchemaData) {
            return sprintf('Cannot remap %s: table "%s" does not exist in the source.', $label, $entry->table);
        }

        $column = array_find($tableSchema->columns, static fn (ColumnSchemaData $c): bool => $c->name === $entry->primaryKey);

        if (! $column instanceof ColumnSchemaData) {
            return sprintf('Cannot remap %s: column "%s" does not exist in the source table.', $label, $entry->primaryKey);
        }

        if ($entry->strategy !== KeyRemappingStrategy::RandomInteger) {
            return null;
        }

        try {
            $this->boundsResolver->ceilingFor($column);
        } catch (UnsupportedKeyColumnTypeException) {
            return sprintf(
                'Cannot remap %s: random_integer needs an integer column, but "%s" is %s. Use new_uuid for UUID or text keys.',
                $label,
                $column->name,
                $column->type,
            );
        }

        return null;
    }
}
