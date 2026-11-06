<?php

declare(strict_types=1);

namespace App\Services\Schema;

use App\Data\ConnectionData;
use App\Data\Schema\ColumnSchemaData;
use App\Data\Schema\DatabaseSchemaData;
use App\Data\Schema\ForeignKeyData;
use App\Data\Schema\TableSchemaData;
use App\Enums\DatabaseConnectionType;
use App\Services\Database\DatabaseConnectionService;
use Illuminate\Support\Facades\DB;
use PDO;
use RuntimeException;
use stdClass;

class SchemaInspector
{
    public function __construct(private readonly DatabaseConnectionService $connector) {}

    public function inspect(ConnectionData $connection): DatabaseSchemaData
    {
        $connName = $this->connector->open($connection);

        try {
            return $this->doInspect($connName, $connection);
        } finally {
            DB::purge($connName);
        }
    }

    private function doInspect(string $connName, ConnectionData $connection): DatabaseSchemaData
    {
        return match ($connection->type) {
            DatabaseConnectionType::Mysql, DatabaseConnectionType::MariaDB => $this->inspectMysql($connName, $connection),
            DatabaseConnectionType::PostgreSQL => $this->inspectPgsql($connName, $connection),
            DatabaseConnectionType::Sqlite => $this->inspectSqlite($connName, $connection),
            DatabaseConnectionType::SqlServer => $this->inspectSqlServer($connName, $connection),
            DatabaseConnectionType::Dump => throw new RuntimeException('Dump connections have no schema to inspect.'),
        };
    }

    private function inspectMysql(string $connName, ConnectionData $connection): DatabaseSchemaData
    {
        $dbName = $connection->database ?? '';

        /** @var list<stdClass> $tableRows */
        $tableRows = DB::connection($connName)->select(
            "SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE' ORDER BY TABLE_NAME"
        );

        $tables = [];

        foreach ($tableRows as $tableRow) {
            /** @var string $tableName */
            $tableName = $tableRow->TABLE_NAME;

            /** @var list<stdClass> $columnRows */
            $columnRows = DB::connection($connName)->select(
                "SELECT c.COLUMN_NAME, c.DATA_TYPE, c.COLUMN_TYPE, c.IS_NULLABLE, c.COLUMN_DEFAULT, c.COLUMN_KEY, c.EXTRA,
                    (SELECT k.ORDINAL_POSITION FROM information_schema.KEY_COLUMN_USAGE k
                     WHERE k.TABLE_SCHEMA = c.TABLE_SCHEMA AND k.TABLE_NAME = c.TABLE_NAME
                     AND k.COLUMN_NAME = c.COLUMN_NAME AND k.CONSTRAINT_NAME = 'PRIMARY') AS PK_POSITION
                FROM information_schema.COLUMNS c WHERE c.TABLE_SCHEMA = DATABASE() AND c.TABLE_NAME = ? ORDER BY c.ORDINAL_POSITION",
                [$tableName]
            );

            $columns = [];

            foreach ($columnRows as $col) {
                /** @var string $colName */
                $colName = $col->COLUMN_NAME;
                /** @var string $dataType */
                $dataType = $col->DATA_TYPE;
                /** @var string $columnType */
                $columnType = $col->COLUMN_TYPE;
                /** @var string $isNullable */
                $isNullable = $col->IS_NULLABLE;
                $rawDefault = $col->COLUMN_DEFAULT ?? null;
                $colDefault = is_scalar($rawDefault) ? (string) $rawDefault : null;
                /** @var string $colKey */
                $colKey = $col->COLUMN_KEY;
                $extra = is_string($col->EXTRA ?? null) ? $col->EXTRA : ''; // @pest-mutate-ignore: EmptyStringToNotEmpty

                $mods = ColumnTypeParser::parse($columnType);

                $columns[] = new ColumnSchemaData(
                    name: $colName,
                    type: $dataType,
                    nullable: $isNullable === 'YES',
                    default: $colDefault,
                    isPrimary: $colKey === 'PRI',
                    unsigned: str_contains(strtolower($columnType), 'unsigned'),
                    length: $mods['length'],
                    precision: $mods['precision'],
                    scale: $mods['scale'],
                    generated: $this->isMysqlGenerated($extra),
                    primaryKeyPosition: $this->intOrNull($col->PK_POSITION ?? null),
                );
            }

            /** @var list<stdClass> $fkRows */
            $fkRows = DB::connection($connName)->select(
                'SELECT COLUMN_NAME, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND REFERENCED_TABLE_NAME IS NOT NULL',
                [$tableName]
            );

            $foreignKeys = [];

            foreach ($fkRows as $fk) {
                /** @var string $fkColName */
                $fkColName = $fk->COLUMN_NAME;
                /** @var string $refTable */
                $refTable = $fk->REFERENCED_TABLE_NAME;
                /** @var string $refCol */
                $refCol = $fk->REFERENCED_COLUMN_NAME;

                $foreignKeys[] = new ForeignKeyData(
                    columnName: $fkColName,
                    referencedTable: $refTable,
                    referencedColumn: $refCol,
                );
            }

            $tables[] = new TableSchemaData(
                name: $tableName,
                columns: $columns,
                foreignKeys: $foreignKeys,
            );
        }

        return new DatabaseSchemaData(databaseName: $dbName, tables: $tables);
    }

    private function inspectPgsql(string $connName, ConnectionData $connection): DatabaseSchemaData
    {
        $dbName = $connection->database ?? '';

        // Ordinary ('r') and partitioned ('p') tables, without their partitions:
        // reading a partitioned table already returns the rows of every partition,
        // so listing the partitions too would copy those rows twice.
        /** @var list<stdClass> $tableRows */
        $tableRows = DB::connection($connName)->select(
            "SELECT c.relname AS tablename FROM pg_class c
            JOIN pg_namespace n ON n.oid = c.relnamespace
            WHERE n.nspname = current_schema() AND c.relkind IN ('r', 'p') AND NOT c.relispartition
            ORDER BY c.relname"
        );

        $tables = [];

        foreach ($tableRows as $tableRow) {
            /** @var string $tableName */
            $tableName = $tableRow->tablename;

            // Keys come from pg_catalog, not information_schema: information_schema
            // shows constraints only to the table owner (or a user with a privilege
            // other than SELECT), so a read-only user would see no keys at all.
            /** @var list<stdClass> $columnRows */
            $columnRows = DB::connection($connName)->select(
                "SELECT c.column_name, c.data_type, c.is_nullable, c.column_default,
                    c.character_maximum_length, c.numeric_precision, c.numeric_scale, c.is_generated,
                    pk.position IS NOT NULL AS is_primary, pk.position AS pk_position
                FROM information_schema.columns c
                LEFT JOIN LATERAL (
                    SELECT key.position FROM pg_constraint con
                    JOIN pg_class t ON t.oid = con.conrelid
                    JOIN pg_namespace n ON n.oid = t.relnamespace
                    CROSS JOIN LATERAL unnest(con.conkey) WITH ORDINALITY AS key(attnum, position)
                    JOIN pg_attribute a ON a.attrelid = con.conrelid AND a.attnum = key.attnum
                    WHERE con.contype = 'p' AND n.nspname = c.table_schema AND t.relname = c.table_name
                    AND a.attname = c.column_name
                ) pk ON true
                WHERE c.table_schema = current_schema() AND c.table_name = ?
                ORDER BY c.ordinal_position",
                [$tableName]
            );

            $columns = [];

            foreach ($columnRows as $col) {
                /** @var string $colName */
                $colName = $col->column_name;
                /** @var string $dataType */
                $dataType = $col->data_type;
                /** @var string $isNullable */
                $isNullable = $col->is_nullable;
                $rawColDefault = $col->column_default ?? null;
                $colDefault = is_scalar($rawColDefault) ? (string) $rawColDefault : null;
                $isPrimary = (bool) $col->is_primary;

                $columns[] = new ColumnSchemaData(
                    name: $colName,
                    type: $dataType,
                    nullable: $isNullable === 'YES',
                    default: $colDefault,
                    isPrimary: $isPrimary,
                    length: $this->intOrNull($col->character_maximum_length ?? null),
                    precision: $this->intOrNull($col->numeric_precision ?? null),
                    scale: $this->intOrNull($col->numeric_scale ?? null),
                    generated: ($col->is_generated ?? null) === 'ALWAYS',
                    primaryKeyPosition: $this->intOrNull($col->pk_position ?? null),
                );
            }

            // One row per column pair of each foreign key, in key order, so a
            // composite key maps each column to its own referenced column.
            /** @var list<stdClass> $fkRows */
            $fkRows = DB::connection($connName)->select(
                "SELECT a.attname AS column_name, rt.relname AS referenced_table, ra.attname AS referenced_column
                FROM pg_constraint con
                JOIN pg_class t ON t.oid = con.conrelid
                JOIN pg_namespace n ON n.oid = t.relnamespace
                JOIN pg_class rt ON rt.oid = con.confrelid
                CROSS JOIN LATERAL unnest(con.conkey, con.confkey) WITH ORDINALITY AS key(attnum, refattnum, position)
                JOIN pg_attribute a ON a.attrelid = con.conrelid AND a.attnum = key.attnum
                JOIN pg_attribute ra ON ra.attrelid = con.confrelid AND ra.attnum = key.refattnum
                WHERE con.contype = 'f' AND n.nspname = current_schema() AND t.relname = ?
                ORDER BY con.conname, key.position",
                [$tableName]
            );

            $foreignKeys = [];

            foreach ($fkRows as $fk) {
                /** @var string $fkColName */
                $fkColName = $fk->column_name;
                /** @var string $refTable */
                $refTable = $fk->referenced_table;
                /** @var string $refCol */
                $refCol = $fk->referenced_column;

                $foreignKeys[] = new ForeignKeyData(
                    columnName: $fkColName,
                    referencedTable: $refTable,
                    referencedColumn: $refCol,
                );
            }

            $tables[] = new TableSchemaData(
                name: $tableName,
                columns: $columns,
                foreignKeys: $foreignKeys,
            );
        }

        return new DatabaseSchemaData(databaseName: $dbName, tables: $tables);
    }

    private function inspectSqlite(string $connName, ConnectionData $connection): DatabaseSchemaData
    {
        $dbName = $connection->database ?? 'sqlite';

        $pdo = DB::connection($connName)->getPdo();

        /** @var list<stdClass> $tableRows */
        $tableRows = DB::connection($connName)->select(
            "SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name"
        );

        $tables = [];

        foreach ($tableRows as $tableRow) {
            /** @var string $tableName */
            $tableName = $tableRow->name;

            // table_xinfo (not table_info) also lists generated columns.
            $colRows = $pdo->query('PRAGMA table_xinfo('.$tableName.')');

            if ($colRows === false) {
                throw new RuntimeException(sprintf('Failed to get column info for table %s', $tableName));
            }

            /** @var list<stdClass> $colData */
            $colData = $colRows->fetchAll(PDO::FETCH_OBJ);

            $columns = [];

            foreach ($colData as $col) {
                // hidden: 0 = normal, 1 = hidden column of a virtual table,
                // 2 = virtual generated, 3 = stored generated.
                $hidden = is_numeric($col->hidden ?? null) ? (int) $col->hidden : 0; // @pest-mutate-ignore: DecrementInteger

                if ($hidden === 1) {
                    continue;
                }

                /** @var string $colName */
                $colName = $col->name;
                /** @var string $colType */
                $colType = $col->type;
                $notNull = (bool) $col->notnull; // @pest-mutate-ignore: RemoveBooleanCast
                $rawDfltValue = $col->dflt_value ?? null;
                $dfltValue = is_scalar($rawDfltValue) ? (string) $rawDfltValue : null;
                // PRAGMA table_info reports the 1-based position in the primary key, 0 outside it.
                $pkPosition = is_numeric($col->pk) ? (int) $col->pk : 0; // @pest-mutate-ignore: DecrementInteger
                $pk = $pkPosition > 0;

                $mods = ColumnTypeParser::parse($colType);

                $columns[] = new ColumnSchemaData(
                    name: $colName,
                    type: strtolower($colType),
                    nullable: ! $notNull,
                    default: $dfltValue,
                    isPrimary: $pk,
                    length: $mods['length'],
                    precision: $mods['precision'],
                    scale: $mods['scale'],
                    generated: $hidden === 2 || $hidden === 3,
                    primaryKeyPosition: $pk ? $pkPosition : null,
                );
            }

            $fkRows = $pdo->query('PRAGMA foreign_key_list('.$tableName.')');

            if ($fkRows === false) {
                throw new RuntimeException(sprintf('Failed to get foreign key info for table %s', $tableName));
            }

            /** @var list<stdClass> $fkData */
            $fkData = $fkRows->fetchAll(PDO::FETCH_OBJ);

            $foreignKeys = [];

            foreach ($fkData as $fk) {
                /** @var string $fkFrom */
                $fkFrom = $fk->from;
                /** @var string $fkTable */
                $fkTable = $fk->table;
                /** @var string $fkTo */
                $fkTo = $fk->to;

                $foreignKeys[] = new ForeignKeyData(
                    columnName: $fkFrom,
                    referencedTable: $fkTable,
                    referencedColumn: $fkTo,
                );
            }

            $tables[] = new TableSchemaData(
                name: $tableName,
                columns: $columns,
                foreignKeys: $foreignKeys,
            );
        }

        return new DatabaseSchemaData(databaseName: $dbName, tables: $tables);
    }

    /**
     * MySQL/MariaDB report generated columns in EXTRA as "VIRTUAL GENERATED",
     * "STORED GENERATED" or (MariaDB) "PERSISTENT GENERATED". "DEFAULT_GENERATED"
     * marks an expression default (e.g. CURRENT_TIMESTAMP) and is a normal column.
     */
    private function isMysqlGenerated(string $extra): bool
    {
        return preg_match('/\b(VIRTUAL|STORED|PERSISTENT) GENERATED\b/i', $extra) === 1;
    }

    private function intOrNull(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    private function inspectSqlServer(string $connName, ConnectionData $connection): DatabaseSchemaData
    {
        $dbName = $connection->database ?? '';

        /** @var list<stdClass> $tableRows */
        $tableRows = DB::connection($connName)->select(
            "SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_TYPE = 'BASE TABLE' AND TABLE_CATALOG = DB_NAME() AND TABLE_SCHEMA = SCHEMA_NAME() ORDER BY TABLE_NAME"
        );

        $tables = [];

        foreach ($tableRows as $tableRow) {
            /** @var string $tableName */
            $tableName = $tableRow->TABLE_NAME;

            /** @var list<stdClass> $columnRows */
            $columnRows = DB::connection($connName)->select(
                "SELECT c.COLUMN_NAME, c.DATA_TYPE, c.IS_NULLABLE, c.COLUMN_DEFAULT,
                    c.CHARACTER_MAXIMUM_LENGTH, c.NUMERIC_PRECISION, c.NUMERIC_SCALE,
                    CASE WHEN pk.COLUMN_NAME IS NOT NULL THEN 1 ELSE 0 END as is_primary,
                    pk.ORDINAL_POSITION as pk_position,
                    COLUMNPROPERTY(OBJECT_ID(QUOTENAME(c.TABLE_SCHEMA) + '.' + QUOTENAME(c.TABLE_NAME)), c.COLUMN_NAME, 'IsComputed') as is_computed
                FROM INFORMATION_SCHEMA.COLUMNS c
                LEFT JOIN (
                    SELECT ku.COLUMN_NAME, ku.ORDINAL_POSITION FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS tc
                    JOIN INFORMATION_SCHEMA.KEY_COLUMN_USAGE ku ON tc.CONSTRAINT_SCHEMA = ku.CONSTRAINT_SCHEMA AND tc.CONSTRAINT_NAME = ku.CONSTRAINT_NAME
                    WHERE tc.TABLE_SCHEMA = SCHEMA_NAME() AND tc.TABLE_NAME = ? AND tc.CONSTRAINT_TYPE = 'PRIMARY KEY'
                ) pk ON pk.COLUMN_NAME = c.COLUMN_NAME
                WHERE c.TABLE_SCHEMA = SCHEMA_NAME() AND c.TABLE_NAME = ?
                ORDER BY c.ORDINAL_POSITION",
                [$tableName, $tableName]
            );

            $columns = [];

            foreach ($columnRows as $col) {
                /** @var string $colName */
                $colName = $col->COLUMN_NAME;
                /** @var string $dataType */
                $dataType = $col->DATA_TYPE;
                /** @var string $isNullable */
                $isNullable = $col->IS_NULLABLE;
                $rawColDefault = $col->COLUMN_DEFAULT ?? null;
                $colDefault = is_scalar($rawColDefault) ? (string) $rawColDefault : null;
                $isPrimary = (bool) $col->is_primary;

                // SQL Server reports CHARACTER_MAXIMUM_LENGTH = -1 for MAX types; treat as unbounded.
                $charLen = $this->intOrNull($col->CHARACTER_MAXIMUM_LENGTH ?? null);

                $columns[] = new ColumnSchemaData(
                    name: $colName,
                    type: $dataType,
                    nullable: $isNullable === 'YES',
                    default: $colDefault,
                    isPrimary: $isPrimary,
                    length: $charLen !== null && $charLen < 0 ? null : $charLen,
                    precision: $this->intOrNull($col->NUMERIC_PRECISION ?? null),
                    scale: $this->intOrNull($col->NUMERIC_SCALE ?? null),
                    generated: $this->intOrNull($col->is_computed ?? null) === 1,
                    primaryKeyPosition: $this->intOrNull($col->pk_position ?? null),
                );
            }

            // sys.foreign_key_columns pairs each column with its referenced column.
            // INFORMATION_SCHEMA.CONSTRAINT_COLUMN_USAGE lists a foreign key's own
            // columns, not the referenced ones, so it cannot be used here.
            /** @var list<stdClass> $fkRows */
            $fkRows = DB::connection($connName)->select(
                "SELECT COL_NAME(fkc.parent_object_id, fkc.parent_column_id) AS COLUMN_NAME,
                    OBJECT_NAME(fkc.referenced_object_id) AS REFERENCED_TABLE,
                    COL_NAME(fkc.referenced_object_id, fkc.referenced_column_id) AS REFERENCED_COLUMN
                FROM sys.foreign_key_columns fkc
                JOIN sys.foreign_keys fk ON fk.object_id = fkc.constraint_object_id
                WHERE fkc.parent_object_id = OBJECT_ID(QUOTENAME(SCHEMA_NAME()) + '.' + QUOTENAME(?))
                ORDER BY fk.name, fkc.constraint_column_id",
                [$tableName]
            );

            $foreignKeys = [];

            foreach ($fkRows as $fk) {
                /** @var string $fkColName */
                $fkColName = $fk->COLUMN_NAME;
                /** @var string $refTable */
                $refTable = $fk->REFERENCED_TABLE;
                /** @var string $refCol */
                $refCol = $fk->REFERENCED_COLUMN;

                $foreignKeys[] = new ForeignKeyData(
                    columnName: $fkColName,
                    referencedTable: $refTable,
                    referencedColumn: $refCol,
                );
            }

            $tables[] = new TableSchemaData(
                name: $tableName,
                columns: $columns,
                foreignKeys: $foreignKeys,
            );
        }

        return new DatabaseSchemaData(databaseName: $dbName, tables: $tables);
    }
}
