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
use Throwable;

class SchemaReplicator
{
    public function __construct(
        private readonly SchemaInspector $inspector,
        private readonly DatabaseConnectionService $connector,
    ) {}

    /**
     * Replicate source schema tables to target.
     *
     * @param  list<string>  $tables
     * @return array<string, string>  Map of tableName => errorMessage for tables that could not be created
     */
    public function replicate(
        ConnectionData $source,
        ConnectionData $target,
        DatabaseSchemaData $sourceSchema,
        array $tables,
        bool $enforceColumnTypes,
        bool $dropUnknownTables,
        bool $dropExtraColumns = false,
    ): array {
        $targetConnName = $this->connector->open($target);
        $sameDbType = $source->type === $target->type;

        /** @var array<string, string> $failedTables */
        $failedTables = [];

        try {
            $targetSchema = $this->inspector->inspect($target);

            foreach ($tables as $tableName) {
                $sourceTable = $sourceSchema->getTable($tableName);

                if (! $sourceTable instanceof TableSchemaData) {
                    continue;
                }

                $targetTable = $targetSchema->getTable($tableName);

                if (! $targetTable instanceof TableSchemaData) {
                    $created = false;

                    if ($sameDbType) {
                        $nativeSql = $this->fetchNativeCreateTableDdl($source, $tableName);

                        if ($nativeSql !== null) {
                            try {
                                DB::connection($targetConnName)->statement($nativeSql);
                                $created = true;
                            } catch (Throwable) {
                                // native DDL failed — fall through to inspector-based fallback
                            }
                        }
                    }

                    if (! $created) {
                        try {
                            $fallbackSql = $this->buildCreateTableSql($tableName, $sourceTable->columns, $target->type);
                            DB::connection($targetConnName)->statement($fallbackSql);
                        } catch (Throwable $e) {
                            $failedTables[$tableName] = $e->getMessage();
                        }
                    }
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

        return $failedTables;
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
        $type = strtolower(trim($sourceType));

        return match (true) {
            // String types
            in_array($type, ['varchar', 'character varying', 'nvarchar'], true) => match ($driver) {
                DatabaseConnectionType::SqlServer => 'NVARCHAR(255)',
                default => 'VARCHAR(255)',
            },
            in_array($type, ['char', 'character'], true) => match ($driver) {
                DatabaseConnectionType::SqlServer => 'NCHAR(1)',
                default => 'CHAR(1)',
            },
            // Integer types — ordered from smallest to largest to avoid partial-match issues
            in_array($type, ['tinyint(1)', 'boolean', 'bool'], true) => match ($driver) {
                DatabaseConnectionType::Mysql, DatabaseConnectionType::MariaDB => 'TINYINT(1)',
                DatabaseConnectionType::SqlServer => 'BIT',
                default => 'BOOLEAN',
            },
            in_array($type, ['tinyint', 'int1'], true) => match ($driver) {
                DatabaseConnectionType::Mysql, DatabaseConnectionType::MariaDB => 'TINYINT',
                DatabaseConnectionType::SqlServer => 'TINYINT',
                default => 'SMALLINT',
            },
            in_array($type, ['smallint', 'int2'], true) => 'SMALLINT',
            in_array($type, ['mediumint', 'int3'], true) => match ($driver) {
                DatabaseConnectionType::Mysql, DatabaseConnectionType::MariaDB => 'MEDIUMINT',
                default => 'INT',
            },
            in_array($type, ['int', 'integer', 'int4'], true) => 'INT',
            in_array($type, ['bigint', 'int8'], true) => 'BIGINT',
            in_array($type, ['serial', 'serial4'], true) => match ($driver) {
                DatabaseConnectionType::PostgreSQL => 'SERIAL',
                default => 'INT',
            },
            $type === 'bigserial' => match ($driver) {
                DatabaseConnectionType::PostgreSQL => 'BIGSERIAL',
                default => 'BIGINT',
            },
            // Floating point
            in_array($type, ['float', 'float4', 'real'], true) => 'FLOAT',
            in_array($type, ['double', 'double precision', 'float8'], true) => 'DOUBLE',
            in_array($type, ['decimal', 'numeric'], true) => 'DECIMAL(10,2)',
            // Text types
            in_array($type, ['text', 'clob'], true) => 'TEXT',
            $type === 'tinytext' => match ($driver) {
                DatabaseConnectionType::Mysql, DatabaseConnectionType::MariaDB => 'TINYTEXT',
                default => 'TEXT',
            },
            $type === 'mediumtext' => match ($driver) {
                DatabaseConnectionType::Mysql, DatabaseConnectionType::MariaDB => 'MEDIUMTEXT',
                default => 'TEXT',
            },
            $type === 'longtext' => match ($driver) {
                DatabaseConnectionType::Mysql, DatabaseConnectionType::MariaDB => 'LONGTEXT',
                DatabaseConnectionType::SqlServer => 'NVARCHAR(MAX)',
                default => 'TEXT',
            },
            // Binary/blob types
            in_array($type, ['blob', 'binary'], true) => match ($driver) {
                DatabaseConnectionType::SqlServer => 'VARBINARY(MAX)',
                default => 'BLOB',
            },
            $type === 'tinyblob' => match ($driver) {
                DatabaseConnectionType::Mysql, DatabaseConnectionType::MariaDB => 'TINYBLOB',
                default => 'BLOB',
            },
            $type === 'mediumblob' => match ($driver) {
                DatabaseConnectionType::Mysql, DatabaseConnectionType::MariaDB => 'MEDIUMBLOB',
                default => 'BLOB',
            },
            $type === 'longblob' => match ($driver) {
                DatabaseConnectionType::Mysql, DatabaseConnectionType::MariaDB => 'LONGBLOB',
                DatabaseConnectionType::SqlServer => 'VARBINARY(MAX)',
                default => 'BLOB',
            },
            $type === 'varbinary' => match ($driver) {
                DatabaseConnectionType::SqlServer => 'VARBINARY(255)',
                default => 'BLOB',
            },
            // Enum / set — cross-DB fallback to VARCHAR; preserved exactly via native DDL for same-DB
            $type === 'enum' => match ($driver) {
                DatabaseConnectionType::Mysql, DatabaseConnectionType::MariaDB => 'VARCHAR(255)',
                DatabaseConnectionType::SqlServer => 'NVARCHAR(255)',
                default => 'VARCHAR(255)',
            },
            $type === 'set' => match ($driver) {
                DatabaseConnectionType::Mysql, DatabaseConnectionType::MariaDB => 'VARCHAR(255)',
                default => 'TEXT',
            },
            // Date/time
            $type === 'date' => 'DATE',
            in_array($type, ['time', 'time without time zone'], true) => 'TIME',
            in_array($type, ['datetime', 'timestamp', 'timestamp without time zone', 'timestamp with time zone'], true) => match ($driver) {
                DatabaseConnectionType::PostgreSQL => 'TIMESTAMP',
                DatabaseConnectionType::SqlServer => 'DATETIME2',
                default => 'DATETIME',
            },
            $type === 'year' => match ($driver) {
                DatabaseConnectionType::Mysql, DatabaseConnectionType::MariaDB => 'YEAR',
                default => 'SMALLINT',
            },
            // JSON
            in_array($type, ['json', 'jsonb'], true) => match ($driver) {
                DatabaseConnectionType::SqlServer => 'NVARCHAR(MAX)',
                DatabaseConnectionType::Sqlite => 'TEXT',
                default => 'JSON',
            },
            // UUID
            $type === 'uuid' => match ($driver) {
                DatabaseConnectionType::SqlServer => 'UNIQUEIDENTIFIER',
                DatabaseConnectionType::Mysql, DatabaseConnectionType::MariaDB => 'CHAR(36)',
                default => 'UUID',
            },
            default => 'TEXT',
        };
    }

    /**
     * Fetch native CREATE TABLE DDL from source and adapt it for the target.
     * Only called when source and target are the same DB type.
     * FK constraints and AUTO_INCREMENT counters are stripped so the statement
     * is safe to run on a target that may not yet have all referenced tables.
     */
    private function fetchNativeCreateTableDdl(ConnectionData $source, string $tableName): ?string
    {
        if (! in_array($source->type, [DatabaseConnectionType::Mysql, DatabaseConnectionType::MariaDB], true)) {
            return null;
        }

        try {
            $sourceConnName = $this->connector->open($source);

            try {
                $quotedTable = '`'.$tableName.'`';

                /** @var list<object> $rows */
                $rows = DB::connection($sourceConnName)->select('SHOW CREATE TABLE '.$quotedTable);

                if ($rows === []) {
                    return null;
                }

                $row = (array) $rows[0];
                $ddl = $row['Create Table'] ?? null;

                if (! is_string($ddl)) {
                    return null;
                }

                return $this->sanitiseNativeDdl($ddl);
            } finally {
                DB::purge($sourceConnName);
            }
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Strip parts of a native CREATE TABLE DDL that would fail on a fresh target:
     * - CONSTRAINT / FOREIGN KEY lines (referenced tables may not exist yet)
     * - AUTO_INCREMENT=N table option (counter value is irrelevant on target)
     * - Wraps with IF NOT EXISTS
     */
    private function sanitiseNativeDdl(string $ddl): string
    {
        // Remove CONSTRAINT ... FOREIGN KEY definitions (full definition including REFERENCES and ON clauses).
        // MySQL SHOW CREATE TABLE outputs each FK on its own line, but we match broadly for safety.
        $ddl = preg_replace('/,?\s*CONSTRAINT\s+`[^`]+`\s+FOREIGN\s+KEY\s*\([^)]+\)\s*REFERENCES\s+`[^`]+`\s*\([^)]+\)(?:\s+ON\s+(?:DELETE|UPDATE)\s+\w+(?:\s+\w+)?)*/i', '', $ddl) ?? $ddl;

        // Remove dangling commas before the closing parenthesis
        $ddl = preg_replace('/,(\s*\))/m', '$1', $ddl) ?? $ddl;

        // Strip AUTO_INCREMENT=N table option
        $ddl = preg_replace('/\s+AUTO_INCREMENT=\d+/i', '', $ddl) ?? $ddl;

        // Replace "CREATE TABLE `name`" with "CREATE TABLE IF NOT EXISTS `name`"
        $ddl = preg_replace('/^CREATE TABLE /i', 'CREATE TABLE IF NOT EXISTS ', $ddl) ?? $ddl;

        return $ddl;
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
