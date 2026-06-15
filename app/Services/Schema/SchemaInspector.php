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
                'SELECT COLUMN_NAME, DATA_TYPE, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, COLUMN_KEY FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION',
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

                $columns[] = new ColumnSchemaData(
                    name: $colName,
                    type: $dataType,
                    nullable: $isNullable === 'YES',
                    default: $colDefault,
                    isPrimary: $colKey === 'PRI',
                    unsigned: str_contains(strtolower($columnType), 'unsigned'),
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

        /** @var list<stdClass> $tableRows */
        $tableRows = DB::connection($connName)->select(
            'SELECT tablename FROM pg_tables WHERE schemaname = current_schema() ORDER BY tablename'
        );

        $tables = [];

        foreach ($tableRows as $tableRow) {
            /** @var string $tableName */
            $tableName = $tableRow->tablename;

            /** @var list<stdClass> $columnRows */
            $columnRows = DB::connection($connName)->select(
                "SELECT column_name, data_type, is_nullable, column_default,
                    (SELECT COUNT(*) FROM information_schema.table_constraints tc
                     JOIN information_schema.key_column_usage kcu ON tc.constraint_name = kcu.constraint_name
                     WHERE tc.table_name = c.table_name AND kcu.column_name = c.column_name
                     AND tc.constraint_type = 'PRIMARY KEY' AND tc.table_schema = current_schema()) > 0 as is_primary
                FROM information_schema.columns c
                WHERE table_schema = current_schema() AND table_name = ?
                ORDER BY ordinal_position",
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
                );
            }

            /** @var list<stdClass> $fkRows */
            $fkRows = DB::connection($connName)->select(
                "SELECT kcu.column_name, ccu.table_name AS referenced_table, ccu.column_name AS referenced_column
                FROM information_schema.table_constraints AS tc
                JOIN information_schema.key_column_usage AS kcu ON tc.constraint_name = kcu.constraint_name
                JOIN information_schema.constraint_column_usage AS ccu ON ccu.constraint_name = tc.constraint_name
                WHERE tc.constraint_type = 'FOREIGN KEY' AND tc.table_name = ?
                AND tc.table_schema = current_schema()",
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

            $colRows = $pdo->query('PRAGMA table_info('.$tableName.')');

            if ($colRows === false) {
                throw new RuntimeException(sprintf('Failed to get column info for table %s', $tableName));
            }

            /** @var list<stdClass> $colData */
            $colData = $colRows->fetchAll(PDO::FETCH_OBJ);

            $columns = [];

            foreach ($colData as $col) {
                /** @var string $colName */
                $colName = $col->name;
                /** @var string $colType */
                $colType = $col->type;
                $notNull = (bool) $col->notnull;
                $rawDfltValue = $col->dflt_value ?? null;
                $dfltValue = is_scalar($rawDfltValue) ? (string) $rawDfltValue : null;
                $pk = (bool) $col->pk;

                $columns[] = new ColumnSchemaData(
                    name: $colName,
                    type: strtolower($colType),
                    nullable: ! $notNull,
                    default: $dfltValue,
                    isPrimary: $pk,
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

    private function inspectSqlServer(string $connName, ConnectionData $connection): DatabaseSchemaData
    {
        $dbName = $connection->database ?? '';

        /** @var list<stdClass> $tableRows */
        $tableRows = DB::connection($connName)->select(
            "SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_TYPE = 'BASE TABLE' AND TABLE_CATALOG = DB_NAME() ORDER BY TABLE_NAME"
        );

        $tables = [];

        foreach ($tableRows as $tableRow) {
            /** @var string $tableName */
            $tableName = $tableRow->TABLE_NAME;

            /** @var list<stdClass> $columnRows */
            $columnRows = DB::connection($connName)->select(
                "SELECT c.COLUMN_NAME, c.DATA_TYPE, c.IS_NULLABLE, c.COLUMN_DEFAULT,
                    CASE WHEN pk.COLUMN_NAME IS NOT NULL THEN 1 ELSE 0 END as is_primary
                FROM INFORMATION_SCHEMA.COLUMNS c
                LEFT JOIN (
                    SELECT ku.COLUMN_NAME FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS tc
                    JOIN INFORMATION_SCHEMA.KEY_COLUMN_USAGE ku ON tc.CONSTRAINT_NAME = ku.CONSTRAINT_NAME
                    WHERE tc.TABLE_NAME = ? AND tc.CONSTRAINT_TYPE = 'PRIMARY KEY'
                ) pk ON pk.COLUMN_NAME = c.COLUMN_NAME
                WHERE c.TABLE_NAME = ?
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

                $columns[] = new ColumnSchemaData(
                    name: $colName,
                    type: $dataType,
                    nullable: $isNullable === 'YES',
                    default: $colDefault,
                    isPrimary: $isPrimary,
                );
            }

            /** @var list<stdClass> $fkRows */
            $fkRows = DB::connection($connName)->select(
                "SELECT kcu.COLUMN_NAME, ccu.TABLE_NAME as REFERENCED_TABLE, ccu.COLUMN_NAME as REFERENCED_COLUMN
                FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS tc
                JOIN INFORMATION_SCHEMA.KEY_COLUMN_USAGE kcu ON tc.CONSTRAINT_NAME = kcu.CONSTRAINT_NAME
                JOIN INFORMATION_SCHEMA.CONSTRAINT_COLUMN_USAGE ccu ON ccu.CONSTRAINT_NAME = tc.CONSTRAINT_NAME
                WHERE tc.TABLE_NAME = ? AND tc.CONSTRAINT_TYPE = 'FOREIGN KEY'",
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
