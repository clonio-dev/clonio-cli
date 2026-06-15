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

it('rejects the dump pseudo-type as a dialect', function (): void {
    expect(fn () => (new DumpDialectFactory)->make(DatabaseConnectionType::Dump))
        ->toThrow(InvalidArgumentException::class);
});
