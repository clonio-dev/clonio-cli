<?php

declare(strict_types=1);

namespace App\Services\Cloning;

use App\Data\ConnectionData;
use App\Data\Schema\ColumnSchemaData;
use App\Data\Schema\DatabaseSchemaData;
use App\Data\Schema\TableSchemaData;
use App\Enums\DatabaseConnectionType;
use App\Services\Database\DatabaseConnectionService;
use App\Services\Schema\SchemaInspector;
use Illuminate\Support\Facades\DB;

class SchemaReplicator
{
    public function __construct(
        private readonly SchemaInspector $inspector,
        private readonly DatabaseConnectionService $connector,
    ) {}

    /**
     * Replicate source schema tables to target.
     *
     * @param  list<string>  $tables  table names to replicate
     */
    public function replicate(
        ConnectionData $source,
        ConnectionData $target,
        DatabaseSchemaData $sourceSchema,
        array $tables,
        bool $enforceColumnTypes,
        bool $dropUnknownTables,
        bool $dropExtraColumns = false,
    ): void {
        $targetConnName = $this->connector->open($target);

        try {
            $targetSchema = $this->inspector->inspect($target);

            foreach ($tables as $tableName) {
                $sourceTable = $sourceSchema->getTable($tableName);

                if (! $sourceTable instanceof TableSchemaData) {
                    continue;
                }

                $targetTable = $targetSchema->getTable($tableName);

                if (! $targetTable instanceof TableSchemaData) {
                    // Create table
                    $sql = $this->buildCreateTableSql($tableName, $sourceTable->columns, $target->type);
                    DB::connection($targetConnName)->statement($sql);
                } else {
                    $sourceColNames = array_map(static fn (ColumnSchemaData $c): string => $c->name, $sourceTable->columns);
                    $targetColNames = array_map(static fn (ColumnSchemaData $c): string => $c->name, $targetTable->columns);

                    if ($enforceColumnTypes) {
                        // Add missing columns
                        foreach ($sourceTable->columns as $col) {
                            if (! in_array($col->name, $targetColNames, true)) {
                                $alterSql = $this->buildAddColumnSql($tableName, $col, $target->type);
                                DB::connection($targetConnName)->statement($alterSql);
                            }
                        }
                    }

                    if ($dropExtraColumns) {
                        // Drop columns that exist in target but not in source
                        foreach ($targetTable->columns as $col) {
                            if (! in_array($col->name, $sourceColNames, true)) {
                                $dropColSql = $this->buildDropColumnSql($tableName, $col->name, $target->type);
                                DB::connection($targetConnName)->statement($dropColSql);
                            }
                        }
                    }
                }
            }

            if ($dropUnknownTables) {
                $sourceTableNames = array_map(static fn (TableSchemaData $t): string => $t->name, $sourceSchema->tables);

                foreach ($targetSchema->tables as $targetTable) {
                    if (! in_array($targetTable->name, $sourceTableNames, true)) {
                        $dropSql = $this->buildDropTableSql($targetTable->name, $target->type);
                        DB::connection($targetConnName)->statement($dropSql);
                    }
                }
            }
        } finally {
            DB::purge($targetConnName);
        }
    }

    /**
     * @param  list<ColumnSchemaData>  $columns
     */
    private function buildCreateTableSql(string $tableName, array $columns, DatabaseConnectionType $driver): string
    {
        $colDefs = [];
        $pkCols = [];

        foreach ($columns as $col) {
            $mappedType = $this->mapType($col->type, $driver);
            $nullable = $col->nullable ? 'NULL' : 'NOT NULL';
            $quotedName = $this->quoteIdentifier($col->name, $driver);
            $colDefs[] = sprintf('  %s %s %s', $quotedName, $mappedType, $nullable);

            if ($col->isPrimary) {
                $pkCols[] = $this->quoteIdentifier($col->name, $driver);
            }
        }

        if ($pkCols !== []) {
            $colDefs[] = '  PRIMARY KEY ('.implode(', ', $pkCols).')';
        }

        $colsSql = implode(",\n", $colDefs);
        $quotedTable = $this->quoteIdentifier($tableName, $driver);

        if ($driver === DatabaseConnectionType::SqlServer) {
            return "IF OBJECT_ID('{$tableName}', 'U') IS NULL CREATE TABLE {$quotedTable} (\n{$colsSql}\n)";
        }

        return "CREATE TABLE IF NOT EXISTS {$quotedTable} (\n{$colsSql}\n)";
    }

    private function buildAddColumnSql(string $tableName, ColumnSchemaData $col, DatabaseConnectionType $driver): string
    {
        $mappedType = $this->mapType($col->type, $driver);
        $nullable = $col->nullable ? 'NULL' : 'NOT NULL';
        $quotedTable = $this->quoteIdentifier($tableName, $driver);
        $quotedCol = $this->quoteIdentifier($col->name, $driver);

        return sprintf('ALTER TABLE %s ADD COLUMN %s %s %s', $quotedTable, $quotedCol, $mappedType, $nullable);
    }

    private function buildDropColumnSql(string $tableName, string $columnName, DatabaseConnectionType $driver): string
    {
        $quotedTable = $this->quoteIdentifier($tableName, $driver);
        $quotedCol = $this->quoteIdentifier($columnName, $driver);

        return sprintf('ALTER TABLE %s DROP COLUMN %s', $quotedTable, $quotedCol);
    }

    private function buildDropTableSql(string $tableName, DatabaseConnectionType $driver): string
    {
        $quotedTable = $this->quoteIdentifier($tableName, $driver);

        if ($driver === DatabaseConnectionType::SqlServer) {
            return sprintf("IF OBJECT_ID('%s', 'U') IS NOT NULL DROP TABLE %s", $tableName, $quotedTable);
        }

        return 'DROP TABLE IF EXISTS '.$quotedTable;
    }

    private function mapType(string $sourceType, DatabaseConnectionType $driver): string
    {
        $type = strtolower($sourceType);

        return match (true) {
            in_array($type, ['varchar', 'character varying', 'nvarchar'], true) => match ($driver) {
                DatabaseConnectionType::SqlServer => 'NVARCHAR(255)',
                default => 'VARCHAR(255)',
            },
            in_array($type, ['int', 'integer', 'int4'], true) => 'INT',
            in_array($type, ['bigint', 'int8'], true) => 'BIGINT',
            in_array($type, ['text', 'longtext', 'mediumtext', 'clob'], true) => 'TEXT',
            in_array($type, ['datetime', 'timestamp', 'timestamp without time zone', 'timestamp with time zone'], true) => match ($driver) {
                DatabaseConnectionType::PostgreSQL => 'TIMESTAMP',
                DatabaseConnectionType::SqlServer => 'DATETIME2',
                default => 'DATETIME',
            },
            in_array($type, ['decimal', 'numeric'], true) => 'DECIMAL(10,2)',
            in_array($type, ['boolean', 'bool', 'tinyint', 'tinyint(1)'], true) => match ($driver) {
                DatabaseConnectionType::Mysql, DatabaseConnectionType::MariaDB => 'TINYINT(1)',
                DatabaseConnectionType::SqlServer => 'BIT',
                default => 'BOOLEAN',
            },
            in_array($type, ['float', 'double', 'real', 'float4', 'float8', 'double precision'], true) => 'FLOAT',
            in_array($type, ['char', 'character'], true) => match ($driver) {
                DatabaseConnectionType::SqlServer => 'NCHAR(1)',
                default => 'CHAR(1)',
            },
            $type === 'date' => 'DATE',
            in_array($type, ['time', 'time without time zone'], true) => 'TIME',
            in_array($type, ['json', 'jsonb'], true) => match ($driver) {
                DatabaseConnectionType::SqlServer => 'NVARCHAR(MAX)',
                DatabaseConnectionType::Sqlite => 'TEXT',
                default => 'JSON',
            },
            default => 'TEXT',
        };
    }

    private function quoteIdentifier(string $name, DatabaseConnectionType $driver): string
    {
        return match ($driver) {
            DatabaseConnectionType::Mysql, DatabaseConnectionType::MariaDB => '`'.$name.'`',
            DatabaseConnectionType::SqlServer => '['.$name.']',
            default => '"'.$name.'"',
        };
    }
}
