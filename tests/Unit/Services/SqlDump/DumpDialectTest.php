<?php

declare(strict_types=1);

use App\Data\Schema\ColumnSchemaData;
use App\Data\Schema\TableSchemaData;
use App\Enums\DatabaseConnectionType;
use App\Services\SqlDump\DumpDialectFactory;

function dumpTable(): TableSchemaData
{
    return new TableSchemaData(
        name: 'users',
        columns: [
            new ColumnSchemaData(name: 'id', type: 'int', nullable: false, default: null, isPrimary: true),
            new ColumnSchemaData(name: 'email', type: 'varchar', nullable: false, default: null, isPrimary: false),
            new ColumnSchemaData(name: 'active', type: 'tinyint', nullable: true, default: null, isPrimary: false),
            new ColumnSchemaData(name: 'avatar', type: 'blob', nullable: true, default: null, isPrimary: false),
            new ColumnSchemaData(name: 'balance', type: 'decimal', nullable: true, default: null, isPrimary: false),
        ],
        foreignKeys: [],
    );
}

/** @return list<array<string, mixed>> */
function dumpRows(): array
{
    return [
        ['id' => 1, 'email' => "O'Brien", 'active' => 1, 'avatar' => "\x89PNG", 'balance' => '99.99'],
        ['id' => 2, 'email' => 'bob@example.com', 'active' => 0, 'avatar' => null, 'balance' => null],
    ];
}

it('MySQL: emits backtick DDL with default lengths and unsigned widening', function (): void {
    $dialect = (new DumpDialectFactory)->make(DatabaseConnectionType::Mysql);
    $unsignedTable = new TableSchemaData('t', [
        new ColumnSchemaData('id', 'int', false, null, true, unsigned: true),
    ], []);

    $ddl = $dialect->createTable($unsignedTable, true);

    expect($ddl)->toContain('CREATE TABLE IF NOT EXISTS `t`')
        ->and($ddl)->toContain('`id` INT UNSIGNED NOT NULL')
        ->and($ddl)->toContain('PRIMARY KEY (`id`)');
});

it('MySQL: insert uses 0x hex for binary, doubled quotes, NULL', function (): void {
    $dialect = (new DumpDialectFactory)->make(DatabaseConnectionType::Mysql);
    $sql = $dialect->insertBatch(dumpTable(), ['id', 'email', 'active', 'avatar', 'balance'], dumpRows());

    expect($sql)->toContain('INSERT INTO `users` (`id`, `email`, `active`, `avatar`, `balance`) VALUES')
        ->and($sql)->toContain("(1, 'O''Brien', 1, 0x89504e47, 99.99)")
        ->and($sql)->toContain("(2, 'bob@example.com', 0, NULL, NULL)");
});

it('PostgreSQL: boolean literals TRUE/FALSE and bytea E-string', function (): void {
    $dialect = (new DumpDialectFactory)->make(DatabaseConnectionType::PostgreSQL);
    $table = new TableSchemaData('flags', [
        new ColumnSchemaData('ok', 'boolean', true, null, false),
        new ColumnSchemaData('blob', 'bytea', true, null, false),
    ], []);

    $sql = $dialect->insertBatch($table, ['ok', 'blob'], [['ok' => 1, 'blob' => 'AB']]);

    expect($sql)->toContain('INSERT INTO "flags"')
        ->and($sql)->toContain("(TRUE, E'\\\\x4142')");
});

it('PostgreSQL: maps types and preamble/postamble toggle FK', function (): void {
    $dialect = (new DumpDialectFactory)->make(DatabaseConnectionType::PostgreSQL);

    expect($dialect->createTable(dumpTable(), false))->toContain('"email" VARCHAR(255) NOT NULL')
        ->and($dialect->createTable(dumpTable(), false))->toContain('"balance" DECIMAL(20,6)')
        ->and($dialect->preamble(true))->toContain("session_replication_role = 'replica'")
        ->and($dialect->postamble(true, []))->toContain("session_replication_role = 'origin'")
        ->and($dialect->preamble(false))->toBe('');
});

it('SQL Server: schema prefix, NVARCHAR, N-strings, OBJECT_ID guards', function (): void {
    $dialect = (new DumpDialectFactory)->make(DatabaseConnectionType::SqlServer, 'app');

    $ddl = $dialect->createTable(dumpTable(), true);
    $drop = $dialect->dropTable('users');
    $sql = $dialect->insertBatch(dumpTable(), ['id', 'email'], [['id' => 1, 'email' => 'x']]);

    expect($ddl)->toContain("IF OBJECT_ID(N'[app].[users]', N'U') IS NULL")
        ->and($ddl)->toContain('CREATE TABLE [app].[users]')
        ->and($ddl)->toContain('[email] NVARCHAR(255) NOT NULL')
        ->and($drop)->toContain("IF OBJECT_ID(N'[app].[users]', N'U') IS NOT NULL DROP TABLE [app].[users];")
        ->and($sql)->toContain("(1, N'x')");
});

it('SQLite: INSERT OR REPLACE, X hex, INTEGER/REAL/TEXT affinity', function (): void {
    $dialect = (new DumpDialectFactory)->make(DatabaseConnectionType::Sqlite);

    $ddl = $dialect->createTable(dumpTable(), true);
    $sql = $dialect->insertBatch(dumpTable(), ['id', 'email', 'avatar', 'balance'], [
        ['id' => 1, 'email' => 'x', 'avatar' => 'AB', 'balance' => '1.5'],
    ]);

    expect($ddl)->toContain('"id" INTEGER NOT NULL')
        ->and($ddl)->toContain('"email" TEXT NOT NULL')
        ->and($ddl)->toContain('"balance" REAL')
        ->and($sql)->toContain('INSERT OR REPLACE INTO "users"')
        ->and($sql)->toContain("(1, 'x', X'4142', 1.5)");
});

it('MariaDB: identical syntax to MySQL but distinct header label', function (): void {
    $maria = (new DumpDialectFactory)->make(DatabaseConnectionType::MariaDB);
    $at = new DateTimeImmutable('2026-05-24 10:00:00');

    expect($maria->fileHeader('db', $at))->toContain('Clonio for MariaDB')
        ->and($maria->createTable(dumpTable(), true))->toContain('CREATE TABLE IF NOT EXISTS `users`');
});

it('falls back to a safe type for unknown source types', function (): void {
    $table = new TableSchemaData('t', [new ColumnSchemaData('c', 'geometry', true, null, false)], []);

    expect((new DumpDialectFactory)->make(DatabaseConnectionType::Mysql)->createTable($table, false))->toContain('`c` TEXT')
        ->and((new DumpDialectFactory)->make(DatabaseConnectionType::SqlServer)->createTable($table, false))->toContain('[c] NVARCHAR(MAX)');
});

it('drop_unknown_tables drives CREATE keyword choice', function (): void {
    $dialect = (new DumpDialectFactory)->make(DatabaseConnectionType::Mysql);

    expect($dialect->createTable(dumpTable(), true))->toContain('CREATE TABLE IF NOT EXISTS')
        ->and($dialect->createTable(dumpTable(), false))->toContain('CREATE TABLE `users`')
        ->and($dialect->createTable(dumpTable(), false))->not->toContain('IF NOT EXISTS');
});

it('honours captured length and precision in DDL', function (): void {
    $table = new TableSchemaData('t', [
        new ColumnSchemaData('name', 'varchar', false, null, false, length: 500),
        new ColumnSchemaData('code', 'char', true, null, false, length: 64),
        new ColumnSchemaData('amount', 'decimal', true, null, false, precision: 10, scale: 2),
    ], []);

    $mysql = (new DumpDialectFactory)->make(DatabaseConnectionType::Mysql)->createTable($table, false);
    $pg = (new DumpDialectFactory)->make(DatabaseConnectionType::PostgreSQL)->createTable($table, false);
    $mssql = (new DumpDialectFactory)->make(DatabaseConnectionType::SqlServer)->createTable($table, false);

    expect($mysql)->toContain('`name` VARCHAR(500)')->toContain('`code` CHAR(64)')->toContain('`amount` DECIMAL(10,2)')
        ->and($pg)->toContain('"name" VARCHAR(500)')->toContain('"amount" DECIMAL(10,2)')
        ->and($mssql)->toContain('[name] NVARCHAR(500)')->toContain('[code] NCHAR(64)')->toContain('[amount] DECIMAL(10,2)');
});

it('falls back to large text types when a string column exceeds dialect limits', function (): void {
    $table = new TableSchemaData('t', [
        new ColumnSchemaData('blob_text', 'varchar', true, null, false, length: 50000),
    ], []);

    expect((new DumpDialectFactory)->make(DatabaseConnectionType::Mysql)->createTable($table, false))->toContain('`blob_text` LONGTEXT')
        ->and((new DumpDialectFactory)->make(DatabaseConnectionType::SqlServer)->createTable($table, false))->toContain('[blob_text] NVARCHAR(MAX)')
        ->and((new DumpDialectFactory)->make(DatabaseConnectionType::PostgreSQL)->createTable($table, false))->toContain('"blob_text" VARCHAR(50000)');
});

it('strips length suffixes from declared type strings (SQLite-style)', function (): void {
    // SQLite stores the full declared type, e.g. "varchar(36)".
    $table = new TableSchemaData('t', [
        new ColumnSchemaData('id', 'varchar(36)', false, null, true, length: 36),
    ], []);

    expect((new DumpDialectFactory)->make(DatabaseConnectionType::Mysql)->createTable($table, false))->toContain('`id` VARCHAR(36)')
        ->and((new DumpDialectFactory)->make(DatabaseConnectionType::Sqlite)->createTable($table, false))->toContain('"id" TEXT');
});

it('still uses defaults when no length metadata is present', function (): void {
    $table = new TableSchemaData('t', [
        new ColumnSchemaData('email', 'varchar', false, null, false),
        new ColumnSchemaData('amount', 'decimal', true, null, false),
    ], []);

    expect((new DumpDialectFactory)->make(DatabaseConnectionType::Mysql)->createTable($table, false))
        ->toContain('`email` VARCHAR(255)')->toContain('`amount` DECIMAL(20,6)');
});

it('returns an empty string for an insert batch with no rows', function (): void {
    $dialect = (new DumpDialectFactory)->make(DatabaseConnectionType::Mysql);

    expect($dialect->insertBatch(dumpTable(), ['id', 'email'], []))->toBe('');
});

it('quotes native PHP booleans per dialect', function (): void {
    $table = new TableSchemaData('t', [new ColumnSchemaData('flag', 'varchar', true, null, false)], []);

    $mysql = (new DumpDialectFactory)->make(DatabaseConnectionType::Mysql)->insertBatch($table, ['flag'], [['flag' => true], ['flag' => false]]);
    $pg = (new DumpDialectFactory)->make(DatabaseConnectionType::PostgreSQL)->insertBatch($table, ['flag'], [['flag' => true]]);

    expect($mysql)->toContain('(1)')->toContain('(0)')
        ->and($pg)->toContain('(TRUE)');
});

it('json-encodes array values that reach a string column', function (): void {
    $table = new TableSchemaData('t', [new ColumnSchemaData('meta', 'json', true, null, false)], []);

    $sql = (new DumpDialectFactory)->make(DatabaseConnectionType::Mysql)->insertBatch($table, ['meta'], [['meta' => ['a' => 1]]]);

    expect($sql)->toContain('\'{"a":1}\'');
});

it('treats a boolean-typed column value of "1"/"0" as a boolean literal', function (): void {
    $table = new TableSchemaData('t', [new ColumnSchemaData('ok', 'boolean', true, null, false)], []);
    $pg = (new DumpDialectFactory)->make(DatabaseConnectionType::PostgreSQL);

    expect($pg->insertBatch($table, ['ok'], [['ok' => '1']]))->toContain('(TRUE)')
        ->and($pg->insertBatch($table, ['ok'], [['ok' => '0']]))->toContain('(FALSE)');
});

it('rejects the dump pseudo-type as a dialect', function (): void {
    expect(fn () => (new DumpDialectFactory)->make(DatabaseConnectionType::Dump))
        ->toThrow(InvalidArgumentException::class);
});

it('emits a plain DROP TABLE IF EXISTS for non-SQL-Server dialects', function (): void {
    expect((new DumpDialectFactory)->make(DatabaseConnectionType::Mysql)->dropTable('users'))->toBe("DROP TABLE IF EXISTS `users`;\n")
        ->and((new DumpDialectFactory)->make(DatabaseConnectionType::PostgreSQL)->dropTable('users'))->toBe("DROP TABLE IF EXISTS \"users\";\n")
        ->and((new DumpDialectFactory)->make(DatabaseConnectionType::Sqlite)->dropTable('users'))->toBe("DROP TABLE IF EXISTS \"users\";\n");
});

it('SQL Server: hex-encodes binary values as 0x', function (): void {
    $table = new TableSchemaData('t', [new ColumnSchemaData('blob', 'varbinary', true, null, false)], []);
    $sql = (new DumpDialectFactory)->make(DatabaseConnectionType::SqlServer)->insertBatch($table, ['blob'], [['blob' => 'AB']]);

    expect($sql)->toContain('(0x4142)');
});

it('defaults the SQL Server schema prefix to dbo and emits no FK preamble', function (): void {
    $dialect = (new DumpDialectFactory)->make(DatabaseConnectionType::SqlServer); // no schema argument

    expect($dialect->createTable(dumpTable(), false))->toContain('CREATE TABLE [dbo].[users]')
        ->and($dialect->preamble(true))->toBe('')
        ->and($dialect->postamble(true, []))->toBe('');
});

/** Render a single nullable column and return the emitted DDL type token. */
function mappedType(DatabaseConnectionType $dialect, string $sourceType, bool $unsigned = false): string
{
    $col = new ColumnSchemaData('c', $sourceType, true, null, false, unsigned: $unsigned);
    $ddl = (new DumpDialectFactory)->make($dialect)->createTable(new TableSchemaData('t', [$col], []), false);

    // Column line looks like: `  <quoted c> <TYPE>,`  (nullable → no NOT NULL suffix)
    expect(preg_match('/^\s+\S+\s+(.+?),?$/m', $ddl, $m))->toBe(1);

    return trim($m[1]);
}

it('maps every source type for MySQL per the §8 table', function (string $source, bool $unsigned, string $expected): void {
    expect(mappedType(DatabaseConnectionType::Mysql, $source, $unsigned))->toBe($expected);
})->with([
    ['boolean', false, 'TINYINT(1)'],
    ['tinyint', false, 'TINYINT'],
    ['tinyint', true, 'TINYINT UNSIGNED'],
    ['smallint', false, 'SMALLINT'],
    ['smallint', true, 'SMALLINT UNSIGNED'],
    ['int', false, 'INT'],
    ['int', true, 'INT UNSIGNED'],
    ['bigint', false, 'BIGINT'],
    ['bigint', true, 'BIGINT UNSIGNED'],
    ['float', false, 'FLOAT'],
    ['double', false, 'DOUBLE'],
    ['decimal', false, 'DECIMAL(20,6)'],
    ['varchar', false, 'VARCHAR(255)'],
    ['char', false, 'CHAR(255)'],
    ['text', false, 'LONGTEXT'],
    ['date', false, 'DATE'],
    ['datetime', false, 'DATETIME'],
    ['timestamp', false, 'TIMESTAMP'],
    ['time', false, 'TIME'],
    ['json', false, 'JSON'],
    ['blob', false, 'LONGBLOB'],
    ['uuid', false, 'CHAR(36)'],
    ['enum', false, 'VARCHAR(255)'],
    ['geometry', false, 'TEXT'],
]);

it('maps every source type for PostgreSQL per the §8 table', function (string $source, bool $unsigned, string $expected): void {
    expect(mappedType(DatabaseConnectionType::PostgreSQL, $source, $unsigned))->toBe($expected);
})->with([
    ['boolean', false, 'BOOLEAN'],
    ['tinyint', false, 'SMALLINT'],
    ['smallint', false, 'SMALLINT'],
    ['int', false, 'INTEGER'],
    ['int', true, 'BIGINT'],
    ['bigint', false, 'BIGINT'],
    ['bigint', true, 'NUMERIC(20,0)'],
    ['float', false, 'REAL'],
    ['double', false, 'DOUBLE PRECISION'],
    ['decimal', false, 'DECIMAL(20,6)'],
    ['varchar', false, 'VARCHAR(255)'],
    ['char', false, 'CHAR(255)'],
    ['text', false, 'TEXT'],
    ['date', false, 'DATE'],
    ['datetime', false, 'TIMESTAMP'],
    ['timestamp', false, 'TIMESTAMPTZ'],
    ['time', false, 'TIME'],
    ['json', false, 'JSONB'],
    ['blob', false, 'BYTEA'],
    ['uuid', false, 'UUID'],
    ['enum', false, 'VARCHAR(255)'],
    ['geometry', false, 'TEXT'],
]);

it('maps every source type for SQL Server per the §8 table', function (string $source, bool $unsigned, string $expected): void {
    expect(mappedType(DatabaseConnectionType::SqlServer, $source, $unsigned))->toBe($expected);
})->with([
    ['boolean', false, 'BIT'],
    ['tinyint', false, 'TINYINT'],
    ['smallint', false, 'SMALLINT'],
    ['int', false, 'INT'],
    ['int', true, 'BIGINT'],
    ['bigint', false, 'BIGINT'],
    ['bigint', true, 'DECIMAL(20,0)'],
    ['float', false, 'REAL'],
    ['double', false, 'FLOAT'],
    ['decimal', false, 'DECIMAL(20,6)'],
    ['varchar', false, 'NVARCHAR(255)'],
    ['char', false, 'NCHAR(255)'],
    ['text', false, 'NVARCHAR(MAX)'],
    ['date', false, 'DATE'],
    ['datetime', false, 'DATETIME2'],
    ['timestamp', false, 'DATETIMEOFFSET'],
    ['time', false, 'TIME'],
    ['json', false, 'NVARCHAR(MAX)'],
    ['blob', false, 'VARBINARY(MAX)'],
    ['uuid', false, 'UNIQUEIDENTIFIER'],
    ['enum', false, 'NVARCHAR(255)'],
    ['geometry', false, 'NVARCHAR(MAX)'],
]);

it('maps every source type for SQLite by storage affinity', function (string $source, string $expected): void {
    expect(mappedType(DatabaseConnectionType::Sqlite, $source))->toBe($expected);
})->with([
    ['boolean', 'INTEGER'],
    ['tinyint', 'INTEGER'],
    ['smallint', 'INTEGER'],
    ['int', 'INTEGER'],
    ['bigint', 'INTEGER'],
    ['float', 'REAL'],
    ['double', 'REAL'],
    ['decimal', 'REAL'],
    ['varchar', 'TEXT'],
    ['char', 'TEXT'],
    ['text', 'TEXT'],
    ['datetime', 'TEXT'],
    ['json', 'TEXT'],
    ['uuid', 'TEXT'],
    ['blob', 'BLOB'],
    ['geometry', 'TEXT'],
]);
