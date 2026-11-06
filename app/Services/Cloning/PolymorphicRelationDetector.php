<?php

declare(strict_types=1);

namespace App\Services\Cloning;

use App\Data\Cloning\KeyRemappingForeignKeyData;
use App\Data\Cloning\PolymorphicDetectionData;
use App\Data\Cloning\PolymorphicRelationData;
use App\Data\ConnectionData;
use App\Data\Schema\ColumnSchemaData;
use App\Data\Schema\DatabaseSchemaData;
use App\Data\Schema\ForeignKeyData;
use App\Services\Database\DatabaseConnectionService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Finds polymorphic relations (`<name>_type` + `<name>_id`) that point at
 * remapped tables. Database constraints cannot express them, so the type
 * values are read from the source data.
 */
class PolymorphicRelationDetector
{
    /**
     * At most this many distinct type values are read per type column.
     */
    private const int MAX_TYPE_VALUES = 100;

    public function __construct(private readonly DatabaseConnectionService $connector) {}

    /**
     * Column pairs that look like a polymorphic relation: a string
     * `<name>_type` column next to an integer `<name>_id` column without a
     * foreign key constraint.
     *
     * @return list<PolymorphicRelationData>
     */
    public function candidates(DatabaseSchemaData $schema): array
    {
        $candidates = [];

        foreach ($schema->tables as $table) {
            $columns = [];

            foreach ($table->columns as $column) {
                $columns[$column->name] = $column;
            }

            $constrained = array_map(static fn (ForeignKeyData $fk): string => $fk->columnName, $table->foreignKeys);

            foreach ($columns as $name => $column) {
                if (! str_ends_with($name, '_type') || ! $this->isStringType($column->type)) {
                    continue;
                }

                $idColumn = substr($name, 0, -strlen('_type')).'_id';
                $id = $columns[$idColumn] ?? null;

                if (! $id instanceof ColumnSchemaData || ! $this->isIntegerType($id->type) || in_array($idColumn, $constrained, true)) {
                    continue;
                }

                $candidates[] = new PolymorphicRelationData($table->name, $name, $idColumn);
            }
        }

        return $candidates;
    }

    /**
     * Reads the type values of every candidate pair and maps each one to a
     * remapped table. The source database is only opened when the schema
     * has candidate pairs.
     *
     * @param  array<string, string>  $remappedTables  primary key column, keyed by table name
     */
    public function detect(ConnectionData $connection, DatabaseSchemaData $schema, array $remappedTables): PolymorphicDetectionData
    {
        $candidates = $this->candidates($schema);

        if ($candidates === [] || $remappedTables === []) {
            return new PolymorphicDetectionData;
        }

        $connName = $this->connector->open($connection);

        try {
            $foreignKeysByTable = [];
            $unresolved = [];

            foreach ($candidates as $candidate) {
                foreach ($this->typeValues($connName, $candidate) as $value) {
                    $target = $this->resolveTable($value, $remappedTables);

                    if ($target === null) {
                        $unresolved[] = sprintf("%s.%s = '%s'", $candidate->table, $candidate->typeColumn, $value);

                        continue;
                    }

                    $foreignKeysByTable[$target][] = new KeyRemappingForeignKeyData(
                        table: $candidate->table,
                        column: $candidate->idColumn,
                        selfReferential: $candidate->table === $target,
                        typeColumn: $candidate->typeColumn,
                        typeValue: $value,
                    );
                }
            }

            return new PolymorphicDetectionData($foreignKeysByTable, $unresolved);
        } finally {
            DB::purge($connName);
        }
    }

    /**
     * @return list<string>
     */
    private function typeValues(string $connName, PolymorphicRelationData $candidate): array
    {
        $values = DB::connection($connName)
            ->table($candidate->table)
            ->whereNotNull($candidate->typeColumn)
            ->distinct()
            ->orderBy($candidate->typeColumn)
            ->limit(self::MAX_TYPE_VALUES)
            ->pluck($candidate->typeColumn)
            ->all();

        $strings = [];

        foreach ($values as $value) {
            if (is_string($value) && $value !== '') {
                $strings[] = $value;
            }
        }

        return $strings;
    }

    /**
     * The value itself as a table name, or the plural snake case of its
     * class basename (`App\Models\User` → `users`, `company` → `companies`).
     *
     * @param  array<string, string>  $remappedTables
     */
    private function resolveTable(string $value, array $remappedTables): ?string
    {
        $names = [$value, Str::snake(Str::pluralStudly(class_basename($value)))];

        return array_find($names, static fn (string $name): bool => isset($remappedTables[$name]));
    }

    private function isStringType(string $type): bool
    {
        $normalized = strtolower($type);

        return str_contains($normalized, 'char') || str_contains($normalized, 'text') || str_contains($normalized, 'string');
    }

    private function isIntegerType(string $type): bool
    {
        $normalized = strtolower($type);

        return str_contains($normalized, 'int') || in_array($normalized, ['serial', 'bigserial'], true);
    }
}
