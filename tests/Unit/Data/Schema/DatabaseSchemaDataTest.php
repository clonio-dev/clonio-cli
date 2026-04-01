<?php

declare(strict_types=1);

use App\Data\Schema\ColumnSchemaData;
use App\Data\Schema\DatabaseSchemaData;
use App\Data\Schema\ForeignKeyData;
use App\Data\Schema\TableSchemaData;

function makeTestTable(string $name): TableSchemaData
{
    return new TableSchemaData(
        name: $name,
        columns: [
            new ColumnSchemaData(name: 'id', type: 'int', nullable: false, default: null, isPrimary: true),
        ],
        foreignKeys: [],
    );
}

it('getTable returns the correct table by name', function (): void {
    $schema = new DatabaseSchemaData(
        databaseName: 'mydb',
        tables: [makeTestTable('users'), makeTestTable('orders')],
    );

    $table = $schema->getTable('users');
    expect($table)->not->toBeNull();
    expect($table?->name)->toBe('users');
});

it('getTable returns null for non-existent table', function (): void {
    $schema = new DatabaseSchemaData(
        databaseName: 'mydb',
        tables: [makeTestTable('users')],
    );

    expect($schema->getTable('nonexistent'))->toBeNull();
});

it('hasTable returns true when table exists', function (): void {
    $schema = new DatabaseSchemaData(
        databaseName: 'mydb',
        tables: [makeTestTable('users')],
    );

    expect($schema->hasTable('users'))->toBeTrue();
});

it('hasTable returns false when table does not exist', function (): void {
    $schema = new DatabaseSchemaData(
        databaseName: 'mydb',
        tables: [makeTestTable('users')],
    );

    expect($schema->hasTable('orders'))->toBeFalse();
});

it('ForeignKeyData stores all fields correctly', function (): void {
    $fk = new ForeignKeyData(
        columnName: 'user_id',
        referencedTable: 'users',
        referencedColumn: 'id',
    );

    expect($fk->columnName)->toBe('user_id');
    expect($fk->referencedTable)->toBe('users');
    expect($fk->referencedColumn)->toBe('id');
});
