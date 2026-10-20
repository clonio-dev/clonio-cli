<?php

declare(strict_types=1);

use App\Data\ConnectionData;
use App\Data\Schema\ColumnSchemaData;
use App\Data\Schema\TableSchemaData;
use App\Enums\DatabaseConnectionType;
use App\Services\Database\DatabaseConnectionService;
use App\Services\Schema\SchemaInspector;
use Illuminate\Support\Facades\DB;

/**
 * Wires the DB facade so every `select()` returns the next canned result set and records
 * the bindings it was called with. The connection must be opened as "c" and purged once.
 *
 * @param  list<list<object>>  $results
 * @param  list<array<int, mixed>>  $bindings  receives the bindings of every select() call
 */
function g6x_fakeSelects(array $results, array &$bindings): SchemaInspector
{
    $connector = Mockery::mock(DatabaseConnectionService::class);
    $connector->shouldReceive('open')->once()->andReturn('c');

    DB::shouldReceive('connection')->with('c')->andReturnSelf();
    DB::shouldReceive('select')->andReturnUsing(function (string $sql, array $b = []) use (&$results, &$bindings): array {
        $bindings[] = $b;

        return array_shift($results) ?? [];
    });
    DB::shouldReceive('purge')->once()->with('c');

    return new SchemaInspector($connector);
}

function g6x_conn(DatabaseConnectionType $type, ?string $database): ConnectionData
{
    return new ConnectionData('x', $type, 'h', 1, $database, null, 'u', '', false);
}

function g6x_col(?TableSchemaData $table, string $name): ColumnSchemaData
{
    foreach ($table?->columns ?? [] as $col) {
        if ($col->name === $name) {
            return $col;
        }
    }

    throw new RuntimeException("column {$name} not found");
}

// ── Connection lifecycle ─────────────────────────────────────────────────────

it('purges the opened connection even when inspection fails', function (): void {
    $connector = Mockery::mock(DatabaseConnectionService::class);
    $connector->shouldReceive('open')->once()->andReturn('c');

    DB::shouldReceive('connection')->with('c')->andReturnSelf();
    DB::shouldReceive('select')->andThrow(new RuntimeException('boom'));
    DB::shouldReceive('purge')->once()->with('c');

    expect(fn (): mixed => (new SchemaInspector($connector))->inspect(g6x_conn(DatabaseConnectionType::Mysql, 'shop')))
        ->toThrow(RuntimeException::class, 'boom');
});

// ── MySQL ────────────────────────────────────────────────────────────────────

it('maps MySQL rows with exact metadata and per-table bindings', function (): void {
    $bindings = [];
    $inspector = g6x_fakeSelects([
        [(object) ['TABLE_NAME' => 'users']],
        [
            (object) ['COLUMN_NAME' => 'id', 'DATA_TYPE' => 'int', 'COLUMN_TYPE' => 'INT UNSIGNED', 'IS_NULLABLE' => 'NO', 'COLUMN_DEFAULT' => null, 'COLUMN_KEY' => 'PRI', 'EXTRA' => 'auto_increment'],
            (object) ['COLUMN_NAME' => 'name', 'DATA_TYPE' => 'varchar', 'COLUMN_TYPE' => 'varchar(120)', 'IS_NULLABLE' => 'YES', 'COLUMN_DEFAULT' => 'anon', 'COLUMN_KEY' => ''],
            (object) ['COLUMN_NAME' => 'age', 'DATA_TYPE' => 'int', 'COLUMN_TYPE' => 'int', 'IS_NULLABLE' => 'NO', 'COLUMN_DEFAULT' => 18, 'COLUMN_KEY' => 'MUL'],
            (object) ['COLUMN_NAME' => 'price', 'DATA_TYPE' => 'decimal', 'COLUMN_TYPE' => 'decimal(10,2)', 'IS_NULLABLE' => 'YES', 'COLUMN_DEFAULT' => null, 'COLUMN_KEY' => ''],
        ],
        [(object) ['COLUMN_NAME' => 'org_id', 'REFERENCED_TABLE_NAME' => 'orgs', 'REFERENCED_COLUMN_NAME' => 'id']],
    ], $bindings);

    $schema = $inspector->inspect(g6x_conn(DatabaseConnectionType::Mysql, 'shop'));
    $users = $schema->getTable('users');

    expect($bindings)->toBe([[], ['users'], ['users']])
        ->and($schema->databaseName)->toBe('shop')
        ->and(g6x_col($users, 'id'))->toEqual(new ColumnSchemaData('id', 'int', false, null, true, unsigned: true))
        ->and(g6x_col($users, 'name'))->toEqual(new ColumnSchemaData('name', 'varchar', true, 'anon', false, length: 120))
        ->and(g6x_col($users, 'age'))->toEqual(new ColumnSchemaData('age', 'int', false, '18', false))
        ->and(g6x_col($users, 'price'))->toEqual(new ColumnSchemaData('price', 'decimal', true, null, false, precision: 10, scale: 2))
        ->and($users?->foreignKeys[0]->columnName)->toBe('org_id')
        ->and($users?->foreignKeys[0]->referencedColumn)->toBe('id');
});

it('falls back to an empty database name for MySQL', function (): void {
    $bindings = [];
    $schema = g6x_fakeSelects([[]], $bindings)->inspect(g6x_conn(DatabaseConnectionType::MariaDB, null));

    expect($schema->databaseName)->toBe('')
        ->and($schema->tables)->toBe([]);
});

// ── PostgreSQL ───────────────────────────────────────────────────────────────

it('maps PostgreSQL rows with exact metadata and per-table bindings', function (): void {
    $bindings = [];
    $inspector = g6x_fakeSelects([
        [(object) ['tablename' => 'items']],
        [
            (object) ['column_name' => 'id', 'data_type' => 'integer', 'is_nullable' => 'NO', 'column_default' => "nextval('s')", 'character_maximum_length' => null, 'numeric_precision' => '32', 'numeric_scale' => '0', 'is_primary' => 1],
            (object) ['column_name' => 'sku', 'data_type' => 'character varying', 'is_nullable' => 'YES', 'column_default' => null, 'character_maximum_length' => '64', 'numeric_precision' => null, 'numeric_scale' => null, 'is_primary' => 0],
            (object) ['column_name' => 'qty', 'data_type' => 'numeric', 'is_nullable' => 'NO', 'column_default' => 5, 'character_maximum_length' => null, 'numeric_precision' => 12, 'numeric_scale' => 4, 'is_primary' => 0],
        ],
        [(object) ['column_name' => 'cat_id', 'referenced_table' => 'categories', 'referenced_column' => 'id']],
    ], $bindings);

    $schema = $inspector->inspect(g6x_conn(DatabaseConnectionType::PostgreSQL, 'cat'));
    $items = $schema->getTable('items');

    expect($bindings)->toBe([[], ['items'], ['items']])
        ->and($schema->databaseName)->toBe('cat')
        ->and(g6x_col($items, 'id'))->toEqual(new ColumnSchemaData('id', 'integer', false, "nextval('s')", true, precision: 32, scale: 0))
        ->and(g6x_col($items, 'sku'))->toEqual(new ColumnSchemaData('sku', 'character varying', true, null, false, length: 64))
        ->and(g6x_col($items, 'qty'))->toEqual(new ColumnSchemaData('qty', 'numeric', false, '5', false, precision: 12, scale: 4))
        ->and($items?->foreignKeys[0]->columnName)->toBe('cat_id')
        ->and($items?->foreignKeys[0]->referencedColumn)->toBe('id');
});

it('falls back to an empty database name for PostgreSQL', function (): void {
    $bindings = [];
    $schema = g6x_fakeSelects([[]], $bindings)->inspect(g6x_conn(DatabaseConnectionType::PostgreSQL, null));

    expect($schema->databaseName)->toBe('');
});

// ── SQL Server ───────────────────────────────────────────────────────────────

it('maps SQL Server rows with exact metadata and per-table bindings', function (): void {
    $bindings = [];
    $inspector = g6x_fakeSelects([
        [(object) ['TABLE_NAME' => 'docs']],
        [
            (object) ['COLUMN_NAME' => 'id', 'DATA_TYPE' => 'int', 'IS_NULLABLE' => 'NO', 'COLUMN_DEFAULT' => null, 'CHARACTER_MAXIMUM_LENGTH' => null, 'NUMERIC_PRECISION' => '10', 'NUMERIC_SCALE' => '0', 'is_primary' => 1],
            (object) ['COLUMN_NAME' => 'title', 'DATA_TYPE' => 'nvarchar', 'IS_NULLABLE' => 'YES', 'COLUMN_DEFAULT' => "('x')", 'CHARACTER_MAXIMUM_LENGTH' => 0, 'NUMERIC_PRECISION' => null, 'NUMERIC_SCALE' => null, 'is_primary' => 0],
            (object) ['COLUMN_NAME' => 'amount', 'DATA_TYPE' => 'decimal', 'IS_NULLABLE' => 'NO', 'COLUMN_DEFAULT' => 3, 'CHARACTER_MAXIMUM_LENGTH' => null, 'NUMERIC_PRECISION' => 18, 'NUMERIC_SCALE' => 6, 'is_primary' => 0],
        ],
        [(object) ['COLUMN_NAME' => 'author_id', 'REFERENCED_TABLE' => 'authors', 'REFERENCED_COLUMN' => 'id']],
    ], $bindings);

    $schema = $inspector->inspect(g6x_conn(DatabaseConnectionType::SqlServer, 'cms'));
    $docs = $schema->getTable('docs');

    expect($bindings)->toBe([[], ['docs', 'docs'], ['docs']])
        ->and($schema->databaseName)->toBe('cms')
        ->and(g6x_col($docs, 'id'))->toEqual(new ColumnSchemaData('id', 'int', false, null, true, precision: 10, scale: 0))
        ->and(g6x_col($docs, 'title'))->toEqual(new ColumnSchemaData('title', 'nvarchar', true, "('x')", false, length: 0))
        ->and(g6x_col($docs, 'title')->length)->toBe(0) // 0 is a real length; only negative (MAX) maps to null
        ->and(g6x_col($docs, 'amount'))->toEqual(new ColumnSchemaData('amount', 'decimal', false, '3', false, precision: 18, scale: 6))
        ->and($docs?->foreignKeys[0]->columnName)->toBe('author_id')
        ->and($docs?->foreignKeys[0]->referencedColumn)->toBe('id');
});

it('falls back to an empty database name for SQL Server', function (): void {
    $bindings = [];
    $schema = g6x_fakeSelects([[]], $bindings)->inspect(g6x_conn(DatabaseConnectionType::SqlServer, null));

    expect($schema->databaseName)->toBe('');
});

// ── SQLite (PDO mocked to control raw PRAGMA values) ─────────────────────────

/**
 * @param  list<object>|false  $columns
 * @param  list<object>|false  $foreignKeys
 */
function g6x_sqliteInspector(array|false $columns, array|false $foreignKeys = []): SchemaInspector
{
    $connector = Mockery::mock(DatabaseConnectionService::class);
    $connector->shouldReceive('open')->once()->andReturn('c');

    $statement = function (array|false $rows): PDOStatement|false {
        if ($rows === false) {
            return false;
        }

        $stmt = Mockery::mock(PDOStatement::class);
        $stmt->shouldReceive('fetchAll')->with(PDO::FETCH_OBJ)->andReturn($rows);

        return $stmt;
    };

    $pdo = Mockery::mock(PDO::class);
    $pdo->shouldReceive('query')->with('PRAGMA table_xinfo(t)')->andReturn($statement($columns));
    $pdo->shouldReceive('query')->with('PRAGMA foreign_key_list(t)')->andReturn($statement($foreignKeys));

    DB::shouldReceive('connection')->with('c')->andReturnSelf();
    DB::shouldReceive('getPdo')->andReturn($pdo);
    DB::shouldReceive('select')->andReturn([(object) ['name' => 't']]);
    DB::shouldReceive('purge')->once()->with('c');

    return new SchemaInspector($connector);
}

it('maps SQLite PRAGMA values, skipping hidden virtual-table columns without stopping', function (): void {
    $inspector = g6x_sqliteInspector([
        (object) ['name' => 'id', 'type' => 'INTEGER', 'notnull' => 1, 'dflt_value' => null, 'pk' => 1, 'hidden' => '0'],
        (object) ['name' => 'skipme', 'type' => 'TEXT', 'notnull' => 0, 'dflt_value' => null, 'pk' => 0, 'hidden' => '1'],
        (object) ['name' => 'qty', 'type' => 'INT', 'notnull' => 0, 'dflt_value' => 7, 'pk' => 0],
        (object) ['name' => 'virt', 'type' => 'TEXT', 'notnull' => 0, 'dflt_value' => null, 'pk' => 0, 'hidden' => '2'],
        (object) ['name' => 'stored', 'type' => 'TEXT', 'notnull' => 0, 'dflt_value' => null, 'pk' => 0, 'hidden' => '3'],
    ], [(object) ['from' => 'qty', 'table' => 'other', 'to' => 'id']]);

    $schema = $inspector->inspect(g6x_conn(DatabaseConnectionType::Sqlite, '/data/app.sqlite'));
    $t = $schema->getTable('t');

    expect($schema->databaseName)->toBe('/data/app.sqlite')
        ->and(array_map(fn (ColumnSchemaData $c): string => $c->name, $t?->columns ?? []))->toBe(['id', 'qty', 'virt', 'stored'])
        ->and(g6x_col($t, 'id'))->toEqual(new ColumnSchemaData('id', 'integer', false, null, true))
        ->and(g6x_col($t, 'qty'))->toEqual(new ColumnSchemaData('qty', 'int', true, '7', false))
        ->and(g6x_col($t, 'virt')->generated)->toBeTrue()
        ->and(g6x_col($t, 'stored')->generated)->toBeTrue()
        ->and($t?->foreignKeys[0]->columnName)->toBe('qty')
        ->and($t?->foreignKeys[0]->referencedTable)->toBe('other')
        ->and($t?->foreignKeys[0]->referencedColumn)->toBe('id');
});

it('throws when SQLite column info cannot be read', function (): void {
    expect(fn (): mixed => g6x_sqliteInspector(false)->inspect(g6x_conn(DatabaseConnectionType::Sqlite, 'db')))
        ->toThrow(RuntimeException::class, 'Failed to get column info for table t');
});

it('throws when SQLite foreign key info cannot be read', function (): void {
    expect(fn (): mixed => g6x_sqliteInspector([], false)->inspect(g6x_conn(DatabaseConnectionType::Sqlite, 'db')))
        ->toThrow(RuntimeException::class, 'Failed to get foreign key info for table t');
});

it('refuses to inspect dump connections', function (): void {
    $connector = Mockery::mock(DatabaseConnectionService::class);
    $connector->shouldReceive('open')->once()->andReturn('c');
    DB::shouldReceive('purge')->once()->with('c');

    expect(fn (): mixed => (new SchemaInspector($connector))->inspect(g6x_conn(DatabaseConnectionType::Dump, null)))
        ->toThrow(RuntimeException::class, 'Dump connections have no schema to inspect.');
});
