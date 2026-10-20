<?php

declare(strict_types=1);

use App\Data\Schema\ColumnSchemaData;
use App\Data\Schema\TableSchemaData;
use App\Enums\DatabaseConnectionType;
use App\Services\SqlDump\Contracts\SqlDumpDialect;
use App\Services\SqlDump\DumpDialectFactory;

function g6s_dialect(DatabaseConnectionType $type): SqlDumpDialect
{
    return (new DumpDialectFactory)->make($type);
}

/** Single nullable column table `t` with column `c`. */
function g6s_singleColumnTable(string $type, ?int $length = null, ?int $precision = null, ?int $scale = null): TableSchemaData
{
    return new TableSchemaData('t', [
        new ColumnSchemaData('c', $type, true, null, false, length: $length, precision: $precision, scale: $scale),
    ], []);
}

it('renders the complete CREATE TABLE statement with only primary columns in the key', function (): void {
    $table = new TableSchemaData('users', [
        new ColumnSchemaData('id', 'int', false, null, true),
        new ColumnSchemaData('email', 'varchar', false, null, false),
        new ColumnSchemaData('note', 'text', true, null, false),
    ], []);

    expect(g6s_dialect(DatabaseConnectionType::Mysql)->createTable($table, false))
        ->toBe("CREATE TABLE `users` (\n  `id` INT NOT NULL,\n  `email` VARCHAR(255) NOT NULL,\n  `note` LONGTEXT,\n  PRIMARY KEY (`id`)\n);\n");
});

it('renders a composite primary key in column order', function (): void {
    $table = new TableSchemaData('pivot', [
        new ColumnSchemaData('a', 'int', false, null, true),
        new ColumnSchemaData('label', 'varchar', true, null, false),
        new ColumnSchemaData('b', 'int', false, null, true),
    ], []);

    expect(g6s_dialect(DatabaseConnectionType::PostgreSQL)->createTable($table, true))
        ->toBe("CREATE TABLE IF NOT EXISTS \"pivot\" (\n  \"a\" INTEGER NOT NULL,\n  \"label\" VARCHAR(255),\n  \"b\" INTEGER NOT NULL,\n  PRIMARY KEY (\"a\", \"b\")\n);\n");
});

it('omits the PRIMARY KEY clause when no column is primary', function (): void {
    expect(g6s_dialect(DatabaseConnectionType::Mysql)->createTable(g6s_singleColumnTable('int'), false))
        ->toBe("CREATE TABLE `t` (\n  `c` INT\n);\n");
});

it('normalizes type aliases to their canonical DDL type', function (string $source, string $expected): void {
    expect(g6s_dialect(DatabaseConnectionType::Mysql)->createTable(g6s_singleColumnTable($source), false))
        ->toBe("CREATE TABLE `t` (\n  `c` {$expected}\n);\n");
})->with([
    ['bool', 'TINYINT(1)'],
    ['bit', 'TINYINT(1)'],
    ['int2', 'SMALLINT'],
    ['mediumint', 'INT'],
    ['integer', 'INT'],
    ['int4', 'INT'],
    ['int8', 'BIGINT'],
    ['real', 'FLOAT'],
    ['float4', 'FLOAT'],
    ['double precision', 'DOUBLE'],
    ['float8', 'DOUBLE'],
    ['numeric', 'DECIMAL(20,6)'],
    ['money', 'DECIMAL(20,6)'],
    ['smallmoney', 'DECIMAL(20,6)'],
    ['bpchar', 'CHAR(255)'],
    ['nchar', 'CHAR(255)'],
    ['character', 'CHAR(255)'],
    ['nvarchar', 'VARCHAR(255)'],
    ['character varying', 'VARCHAR(255)'],
    ['varchar2', 'VARCHAR(255)'],
    ['string', 'VARCHAR(255)'],
    ['longtext', 'LONGTEXT'],
    ['mediumtext', 'LONGTEXT'],
    ['tinytext', 'LONGTEXT'],
    ['ntext', 'LONGTEXT'],
    ['clob', 'LONGTEXT'],
    ['datetime2', 'DATETIME'],
    ['smalldatetime', 'DATETIME'],
    ['timestamp without time zone', 'TIMESTAMP'],
    ['timestamp with time zone', 'TIMESTAMP'],
    ['timestamptz', 'TIMESTAMP'],
    ['datetimeoffset', 'TIMESTAMP'],
    ['time without time zone', 'TIME'],
    ['jsonb', 'JSON'],
    ['longblob', 'LONGBLOB'],
    ['mediumblob', 'LONGBLOB'],
    ['tinyblob', 'LONGBLOB'],
    ['bytea', 'LONGBLOB'],
    ['binary', 'LONGBLOB'],
    ['varbinary', 'LONGBLOB'],
    ['image', 'LONGBLOB'],
    ['uniqueidentifier', 'CHAR(36)'],
    'uppercase type' => ['BIGINT', 'BIGINT'],
    'padded type' => ['  int  ', 'INT'],
    'uppercase with length' => ['VARCHAR(40)', 'VARCHAR(255)'],
]);

it('falls back to default lengths for zero length/precision and honours the smallest positive values', function (?int $length, ?int $precision, ?int $scale, string $type, string $expected): void {
    expect(g6s_dialect(DatabaseConnectionType::Mysql)->createTable(g6s_singleColumnTable($type, $length, $precision, $scale), false))
        ->toBe("CREATE TABLE `t` (\n  `c` {$expected}\n);\n");
})->with([
    'varchar length 0' => [0, null, null, 'varchar', 'VARCHAR(255)'],
    'varchar length 1' => [1, null, null, 'varchar', 'VARCHAR(1)'],
    'decimal precision 0' => [null, 0, 2, 'decimal', 'DECIMAL(20,6)'],
    'decimal precision 1' => [null, 1, null, 'decimal', 'DECIMAL(1,0)'],
    'decimal scale defaults to 0' => [null, 12, null, 'decimal', 'DECIMAL(12,0)'],
    'char at 255 stays CHAR' => [255, null, null, 'char', 'CHAR(255)'],
    'char above 255 becomes VARCHAR' => [256, null, null, 'char', 'VARCHAR(256)'],
    'varchar at MySQL limit' => [16383, null, null, 'varchar', 'VARCHAR(16383)'],
    'varchar above MySQL limit' => [16384, null, null, 'varchar', 'LONGTEXT'],
]);

it('caps SQL Server string columns at 4000 characters', function (int $length, string $type, string $expected): void {
    expect(g6s_dialect(DatabaseConnectionType::SqlServer)->createTable(g6s_singleColumnTable($type, $length), false))
        ->toBe("CREATE TABLE [dbo].[t] (\n  [c] {$expected}\n);\n");
})->with([
    [4000, 'varchar', 'NVARCHAR(4000)'],
    [4001, 'varchar', 'NVARCHAR(MAX)'],
    [4000, 'char', 'NCHAR(4000)'],
    [4001, 'char', 'NVARCHAR(MAX)'],
]);

it('renders SQL Server CREATE TABLE without an OBJECT_ID guard when not idempotent', function (): void {
    expect(g6s_dialect(DatabaseConnectionType::SqlServer)->createTable(g6s_singleColumnTable('int'), false))
        ->toBe("CREATE TABLE [dbo].[t] (\n  [c] INT\n);\n")
        ->and(g6s_dialect(DatabaseConnectionType::SqlServer)->createTable(g6s_singleColumnTable('int'), true))
        ->toBe("IF OBJECT_ID(N'[dbo].[t]', N'U') IS NULL\nCREATE TABLE [dbo].[t] (\n  [c] INT\n);\n");
});

it('escapes the closing quote character inside identifiers', function (): void {
    expect(g6s_dialect(DatabaseConnectionType::Mysql)->dropTable('a`b'))->toBe("DROP TABLE IF EXISTS `a``b`;\n")
        ->and(g6s_dialect(DatabaseConnectionType::PostgreSQL)->dropTable('a"b'))->toBe("DROP TABLE IF EXISTS \"a\"\"b\";\n")
        ->and(g6s_dialect(DatabaseConnectionType::Sqlite)->dropTable('a"b'))->toBe("DROP TABLE IF EXISTS \"a\"\"b\";\n")
        ->and(g6s_dialect(DatabaseConnectionType::SqlServer)->quoteIdentifier('a]b'))->toBe('[a]]b]');
});

it('escapes single quotes in SQL Server N-strings', function (): void {
    $sql = g6s_dialect(DatabaseConnectionType::SqlServer)->insertBatch(g6s_singleColumnTable('varchar'), ['c'], [['c' => "O'Brien"]]);

    expect($sql)->toBe("INSERT INTO [dbo].[t] ([c]) VALUES\n(N'O''Brien');\n");
});

it('renders exact FK preamble and postamble per dialect', function (DatabaseConnectionType $type, string $pre, string $post): void {
    $dialect = g6s_dialect($type);

    expect($dialect->preamble(true))->toBe($pre)
        ->and($dialect->postamble(true, []))->toBe($post)
        ->and($dialect->preamble(false))->toBe('')
        ->and($dialect->postamble(false, []))->toBe('');
})->with([
    'mysql' => [DatabaseConnectionType::Mysql, "SET FOREIGN_KEY_CHECKS = 0;\n\n", "\nSET FOREIGN_KEY_CHECKS = 1;\n"],
    'postgres' => [DatabaseConnectionType::PostgreSQL, "SET session_replication_role = 'replica';\n\n", "\nSET session_replication_role = 'origin';\n"],
    'sqlite' => [DatabaseConnectionType::Sqlite, "PRAGMA foreign_keys = OFF;\n\n", "\nPRAGMA foreign_keys = ON;\n"],
]);

it('renders native booleans as 1/0 for MySQL in row order', function (): void {
    $sql = g6s_dialect(DatabaseConnectionType::Mysql)->insertBatch(g6s_singleColumnTable('varchar'), ['c'], [['c' => true], ['c' => false]]);

    expect($sql)->toBe("INSERT INTO `t` (`c`) VALUES\n(1),\n(0);\n");
});

it('interprets textual values of boolean columns', function (string $value, string $expected): void {
    $sql = g6s_dialect(DatabaseConnectionType::PostgreSQL)->insertBatch(g6s_singleColumnTable('boolean'), ['c'], [['c' => $value]]);

    expect($sql)->toBe("INSERT INTO \"t\" (\"c\") VALUES\n({$expected});\n");
})->with([
    ['yes', 'TRUE'],
    ['true', 'TRUE'],
    ['off', 'FALSE'],
    ['garbage', 'FALSE'],
]);

it('emits numeric values unquoted for every numeric column type', function (string $type): void {
    $sql = g6s_dialect(DatabaseConnectionType::Mysql)->insertBatch(g6s_singleColumnTable($type), ['c'], [['c' => '42']]);

    expect($sql)->toBe("INSERT INTO `t` (`c`) VALUES\n(42);\n");
})->with(['tinyint', 'smallint', 'int', 'bigint', 'float', 'double', 'decimal']);

it('quotes numeric-looking strings in non-numeric columns', function (): void {
    $sql = g6s_dialect(DatabaseConnectionType::Mysql)->insertBatch(g6s_singleColumnTable('varchar'), ['c'], [['c' => '00123'], ['c' => 7]]);

    expect($sql)->toBe("INSERT INTO `t` (`c`) VALUES\n('00123'),\n('7');\n");
});

it('quotes values of columns without schema metadata as strings', function (): void {
    $sql = g6s_dialect(DatabaseConnectionType::Mysql)->insertBatch(g6s_singleColumnTable('int'), ['c', 'ghost'], [['c' => 1, 'ghost' => '7']]);

    expect($sql)->toBe("INSERT INTO `t` (`c`, `ghost`) VALUES\n(1, '7');\n");
});

it('quotes JSON-encoded arrays even in numeric columns', function (): void {
    $sql = g6s_dialect(DatabaseConnectionType::Mysql)->insertBatch(g6s_singleColumnTable('int'), ['c'], [['c' => [1]]]);

    expect($sql)->toBe("INSERT INTO `t` (`c`) VALUES\n('[1]');\n");
});

it('renders an empty string literal when a value cannot be JSON-encoded', function (): void {
    $sql = g6s_dialect(DatabaseConnectionType::Mysql)->insertBatch(g6s_singleColumnTable('varchar'), ['c'], [['c' => ["\xB1"]]]);

    expect($sql)->toBe("INSERT INTO `t` (`c`) VALUES\n('');\n");
});
