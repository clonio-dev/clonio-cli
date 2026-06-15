<?php

declare(strict_types=1);

use App\Data\ConnectionData;
use App\Data\Schema\ColumnSchemaData;
use App\Data\Schema\TableSchemaData;
use App\Enums\DatabaseConnectionType;
use App\Services\Database\DatabaseConnectionService;
use App\Services\Schema\SchemaInspector;
use Illuminate\Support\Facades\DB;

/** Find a column by name in a table's column list. */
function findCol(TableSchemaData $table, string $name): ColumnSchemaData
{
    foreach ($table->columns as $col) {
        if ($col->name === $name) {
            return $col;
        }
    }

    throw new RuntimeException("column {$name} not found");
}

// ── SQLite: exercised against a real database file ──────────────────────────

it('inspects a real SQLite database — tables, columns, keys, lengths', function (): void {
    $dbPath = tempnam(sys_get_temp_dir(), 'clonio_insp_').'.sqlite';
    $pdo = new PDO('sqlite:'.$dbPath);
    $pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, ref VARCHAR(36) NOT NULL, balance DECIMAL(10,2) DEFAULT 0, note TEXT)');
    $pdo->exec('CREATE TABLE orders (id INTEGER PRIMARY KEY, user_id INTEGER NOT NULL, FOREIGN KEY (user_id) REFERENCES users(id))');
    $pdo = null;

    $conn = new ConnectionData('src', DatabaseConnectionType::Sqlite, null, null, $dbPath, null, null, '', false);

    $schema = (new SchemaInspector(new DatabaseConnectionService))->inspect($conn);

    @unlink($dbPath);

    expect(array_map(fn (TableSchemaData $t): string => $t->name, $schema->tables))->toBe(['orders', 'users']);

    $users = $schema->getTable('users');
    expect($users)->toBeInstanceOf(TableSchemaData::class)
        ->and(findCol($users, 'id')->isPrimary)->toBeTrue()
        ->and(findCol($users, 'ref')->type)->toBe('varchar(36)')
        ->and(findCol($users, 'ref')->length)->toBe(36)
        ->and(findCol($users, 'ref')->nullable)->toBeFalse()
        ->and(findCol($users, 'balance')->precision)->toBe(10)
        ->and(findCol($users, 'balance')->scale)->toBe(2)
        ->and(findCol($users, 'balance')->default)->toBe('0')
        ->and(findCol($users, 'note')->nullable)->toBeTrue();

    $orders = $schema->getTable('orders');
    expect($orders->foreignKeys)->toHaveCount(1)
        ->and($orders->foreignKeys[0]->columnName)->toBe('user_id')
        ->and($orders->foreignKeys[0]->referencedTable)->toBe('users')
        ->and($orders->foreignKeys[0]->referencedColumn)->toBe('id');
});

// ── MySQL: DB facade mocked with canned information_schema rows ──────────────

it('maps MySQL information_schema rows into schema data', function (): void {
    $connector = Mockery::mock(DatabaseConnectionService::class);
    $connector->shouldReceive('open')->andReturn('c');

    DB::shouldReceive('connection')->with('c')->andReturnSelf();
    DB::shouldReceive('select')->andReturn(
        [(object) ['TABLE_NAME' => 'users']],
        [
            (object) ['COLUMN_NAME' => 'id', 'DATA_TYPE' => 'int', 'COLUMN_TYPE' => 'int unsigned', 'IS_NULLABLE' => 'NO', 'COLUMN_DEFAULT' => null, 'COLUMN_KEY' => 'PRI'],
            (object) ['COLUMN_NAME' => 'name', 'DATA_TYPE' => 'varchar', 'COLUMN_TYPE' => 'varchar(120)', 'IS_NULLABLE' => 'YES', 'COLUMN_DEFAULT' => 'x', 'COLUMN_KEY' => ''],
        ],
        [(object) ['COLUMN_NAME' => 'org_id', 'REFERENCED_TABLE_NAME' => 'orgs', 'REFERENCED_COLUMN_NAME' => 'id']],
    );
    DB::shouldReceive('purge')->with('c');

    $conn = new ConnectionData('m', DatabaseConnectionType::Mysql, 'h', 3306, 'shop', null, 'root', '', false);
    $schema = (new SchemaInspector($connector))->inspect($conn);

    $users = $schema->getTable('users');
    expect($schema->databaseName)->toBe('shop')
        ->and(findCol($users, 'id')->isPrimary)->toBeTrue()
        ->and(findCol($users, 'id')->unsigned)->toBeTrue()
        ->and(findCol($users, 'name')->type)->toBe('varchar')
        ->and(findCol($users, 'name')->length)->toBe(120)
        ->and(findCol($users, 'name')->nullable)->toBeTrue()
        ->and($users->foreignKeys[0]->referencedTable)->toBe('orgs');
});

// ── PostgreSQL: DB facade mocked ────────────────────────────────────────────

it('maps PostgreSQL information_schema rows into schema data', function (): void {
    $connector = Mockery::mock(DatabaseConnectionService::class);
    $connector->shouldReceive('open')->andReturn('c');

    DB::shouldReceive('connection')->with('c')->andReturnSelf();
    DB::shouldReceive('select')->andReturn(
        [(object) ['tablename' => 'items']],
        [
            (object) ['column_name' => 'id', 'data_type' => 'integer', 'is_nullable' => 'NO', 'column_default' => "nextval('s')", 'character_maximum_length' => null, 'numeric_precision' => 32, 'numeric_scale' => 0, 'is_primary' => true],
            (object) ['column_name' => 'sku', 'data_type' => 'character varying', 'is_nullable' => 'YES', 'column_default' => null, 'character_maximum_length' => 64, 'numeric_precision' => null, 'numeric_scale' => null, 'is_primary' => false],
        ],
        [(object) ['column_name' => 'cat_id', 'referenced_table' => 'categories', 'referenced_column' => 'id']],
    );
    DB::shouldReceive('purge')->with('c');

    $conn = new ConnectionData('p', DatabaseConnectionType::PostgreSQL, 'h', 5432, 'cat', 'public', 'postgres', '', false);
    $schema = (new SchemaInspector($connector))->inspect($conn);

    $items = $schema->getTable('items');
    expect(findCol($items, 'id')->isPrimary)->toBeTrue()
        ->and(findCol($items, 'sku')->type)->toBe('character varying')
        ->and(findCol($items, 'sku')->length)->toBe(64)
        ->and($items->foreignKeys[0]->referencedTable)->toBe('categories');
});

// ── SQL Server: DB facade mocked (incl. MAX → null length) ──────────────────

it('maps SQL Server information_schema rows, treating MAX length as null', function (): void {
    $connector = Mockery::mock(DatabaseConnectionService::class);
    $connector->shouldReceive('open')->andReturn('c');

    DB::shouldReceive('connection')->with('c')->andReturnSelf();
    DB::shouldReceive('select')->andReturn(
        [(object) ['TABLE_NAME' => 'docs']],
        [
            (object) ['COLUMN_NAME' => 'id', 'DATA_TYPE' => 'int', 'IS_NULLABLE' => 'NO', 'COLUMN_DEFAULT' => null, 'CHARACTER_MAXIMUM_LENGTH' => null, 'NUMERIC_PRECISION' => 10, 'NUMERIC_SCALE' => 0, 'is_primary' => 1],
            (object) ['COLUMN_NAME' => 'title', 'DATA_TYPE' => 'nvarchar', 'IS_NULLABLE' => 'YES', 'COLUMN_DEFAULT' => null, 'CHARACTER_MAXIMUM_LENGTH' => 200, 'NUMERIC_PRECISION' => null, 'NUMERIC_SCALE' => null, 'is_primary' => 0],
            (object) ['COLUMN_NAME' => 'body', 'DATA_TYPE' => 'nvarchar', 'IS_NULLABLE' => 'YES', 'COLUMN_DEFAULT' => null, 'CHARACTER_MAXIMUM_LENGTH' => -1, 'NUMERIC_PRECISION' => null, 'NUMERIC_SCALE' => null, 'is_primary' => 0],
        ],
        [(object) ['COLUMN_NAME' => 'author_id', 'REFERENCED_TABLE' => 'authors', 'REFERENCED_COLUMN' => 'id']],
    );
    DB::shouldReceive('purge')->with('c');

    $conn = new ConnectionData('s', DatabaseConnectionType::SqlServer, 'h', 1433, 'cms', 'dbo', 'sa', '', false);
    $schema = (new SchemaInspector($connector))->inspect($conn);

    $docs = $schema->getTable('docs');
    expect(findCol($docs, 'id')->isPrimary)->toBeTrue()
        ->and(findCol($docs, 'title')->length)->toBe(200)
        ->and(findCol($docs, 'body')->length)->toBeNull() // -1 (MAX) → null
        ->and($docs->foreignKeys)->toHaveCount(1)
        ->and($docs->foreignKeys[0]->referencedTable)->toBe('authors');
});
