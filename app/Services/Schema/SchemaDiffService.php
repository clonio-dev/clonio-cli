<?php

declare(strict_types=1);

namespace App\Services\Schema;

use App\Data\Schema\ColumnDiffData;
use App\Data\Schema\ColumnSchemaData;
use App\Data\Schema\DatabaseSchemaData;
use App\Data\Schema\SchemaDiffData;
use App\Data\Schema\TableDiffData;
use App\Data\Schema\TableSchemaData;

final class SchemaDiffService
{
    public function diff(DatabaseSchemaData $source, DatabaseSchemaData $target): SchemaDiffData
    {
        $missingTables = [];
        $modifiedTables = [];

        foreach ($source->tables as $sourceTable) {
            $targetTable = $target->getTable($sourceTable->name);

            if (! $targetTable instanceof TableSchemaData) {
                $missingTables[] = $sourceTable->name;
            } else {
                $tableDiff = $this->diffTable($sourceTable, $targetTable);

                if ($tableDiff->hasDifferences()) {
                    $modifiedTables[] = $tableDiff;
                }
            }
        }

        $extraTables = [];

        foreach ($target->tables as $targetTable) {
            if (! $source->hasTable($targetTable->name)) {
                $extraTables[] = $targetTable->name;
            }
        }

        return new SchemaDiffData(
            missingTables: $missingTables,
            extraTables: $extraTables,
            modifiedTables: $modifiedTables,
        );
    }

    private function diffTable(TableSchemaData $source, TableSchemaData $target): TableDiffData
    {
        $missingColumns = [];
        $modifiedColumns = [];

        foreach ($source->columns as $sourceColumn) {
            $targetColumn = $this->findColumn($target, $sourceColumn->name);

            if (! $targetColumn instanceof ColumnSchemaData) {
                $missingColumns[] = $sourceColumn->name;
            } elseif ($this->columnsAreDifferent($sourceColumn, $targetColumn)) {
                $modifiedColumns[] = new ColumnDiffData(
                    name: $sourceColumn->name,
                    sourceType: $sourceColumn->type,
                    targetType: $targetColumn->type,
                    sourceNullable: $sourceColumn->nullable,
                    targetNullable: $targetColumn->nullable,
                );
            }
        }

        $extraColumns = [];

        foreach ($target->columns as $targetColumn) {
            if (! $this->findColumn($source, $targetColumn->name) instanceof ColumnSchemaData) {
                $extraColumns[] = $targetColumn->name;
            }
        }

        return new TableDiffData(
            tableName: $source->name,
            missingColumns: $missingColumns,
            extraColumns: $extraColumns,
            modifiedColumns: $modifiedColumns,
        );
    }

    private function findColumn(TableSchemaData $table, string $name): ?ColumnSchemaData
    {
        foreach ($table->columns as $column) {
            if ($column->name === $name) {
                return $column;
            }
        }

        return null;
    }

    private function columnsAreDifferent(ColumnSchemaData $source, ColumnSchemaData $target): bool
    {
        return strtolower($source->type) !== strtolower($target->type)
            || $source->nullable !== $target->nullable;
    }
}
