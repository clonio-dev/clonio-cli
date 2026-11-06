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
        $entries = [];

        foreach ($config->tables as $table) {
            $entry = $config->keyRemapping?->getTable($table->tableName);

            if ($table->rows->strategy === 'schema' || ! $entry instanceof KeyRemappingTableData) {
                continue;
            }

            $entries[] = $entry;
            $problem = $this->problem($entry, $sourceSchema);

            if ($problem !== null) {
                $problems[] = $problem;
            }

            array_push($problems, ...$this->typeColumnProblems($entry, $sourceSchema));
        }

        return [...$problems, ...$this->foreignKeyConflicts($entries)];
    }

    /**
     * A polymorphic foreign key needs its type column in the source table.
     *
     * @return list<string>
     */
    private function typeColumnProblems(KeyRemappingTableData $entry, DatabaseSchemaData $sourceSchema): array
    {
        $problems = [];

        foreach ($entry->foreignKeys as $fk) {
            $tableSchema = $sourceSchema->getTable($fk->table);

            if ($fk->typeColumn === null || ! $tableSchema instanceof TableSchemaData) {
                continue;
            }

            $typeColumn = $fk->typeColumn;

            if (! array_any($tableSchema->columns, static fn (ColumnSchemaData $c): bool => $c->name === $typeColumn)) {
                $problems[] = sprintf(
                    'Cannot remap %s.%s for %s: type column "%s" does not exist in the source table.',
                    $fk->table,
                    $fk->column,
                    $entry->table,
                    $fk->typeColumn,
                );
            }
        }

        return $problems;
    }

    /**
     * A foreign key column listed under more than one remapped table would be
     * rewritten once per table. That only works for a polymorphic column, with
     * a distinct type value per table.
     *
     * @param  list<KeyRemappingTableData>  $entries
     * @return list<string>
     */
    private function foreignKeyConflicts(array $entries): array
    {
        /** @var array<string, list<array{owner: string, type: ?string}>> $byColumn */
        $byColumn = [];

        foreach ($entries as $entry) {
            foreach ($entry->foreignKeys as $fk) {
                $byColumn[$fk->table.'.'.$fk->column][] = [
                    'owner' => $entry->table,
                    'type' => $fk->typeColumn === null ? null : $fk->typeValue,
                ];
            }
        }

        $problems = [];

        foreach ($byColumn as $column => $uses) {
            if (count($uses) < 2) {
                continue;
            }

            $owners = implode(', ', array_unique(array_column($uses, 'owner')));

            if (in_array(null, array_column($uses, 'type'), true)) {
                $problems[] = sprintf(
                    'Cannot remap %s: it is listed under %s. Set type_column and type_value on each entry for a polymorphic relation.',
                    $column,
                    $owners,
                );

                continue;
            }

            $types = array_column($uses, 'type');

            if (count(array_unique($types)) !== count($types)) {
                $problems[] = sprintf(
                    'Cannot remap %s: the same type_value is listed more than once (under %s).',
                    $column,
                    $owners,
                );
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
