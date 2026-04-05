<?php

declare(strict_types=1);

use App\Data\Schema\ColumnSchemaData;
use App\Data\Schema\DatabaseSchemaData;
use App\Data\Schema\TableSchemaData;
use App\Services\Schema\SchemaDiffService;

function diffSchemaWith(string $dbName, array $tables): DatabaseSchemaData
{
    return new DatabaseSchemaData(databaseName: $dbName, tables: $tables);
}

function diffTable(string $name, array $columns, array $foreignKeys = []): TableSchemaData
{
    return new TableSchemaData(name: $name, columns: $columns, foreignKeys: $foreignKeys);
}

function diffColumn(string $name, string $type = 'int', bool $nullable = false): ColumnSchemaData
{
    return new ColumnSchemaData(name: $name, type: $type, nullable: $nullable, default: null, isPrimary: false);
}

it('reports identical schemas as having no differences', function (): void {
    $schema = diffSchemaWith('db', [
        diffTable('users', [
            diffColumn('id', 'int'),
            diffColumn('email', 'varchar'),
        ]),
    ]);

    $diff = (new SchemaDiffService)->diff($schema, $schema);

    expect($diff->isIdentical())->toBeTrue()
        ->and($diff->missingTables)->toBe([])
        ->and($diff->extraTables)->toBe([])
        ->and($diff->modifiedTables)->toBe([]);
});

it('detects tables present in source but missing in target', function (): void {
    $source = diffSchemaWith('db', [
        diffTable('users', [diffColumn('id')]),
        diffTable('orders', [diffColumn('id')]),
    ]);

    $target = diffSchemaWith('db', [
        diffTable('users', [diffColumn('id')]),
    ]);

    $diff = (new SchemaDiffService)->diff($source, $target);

    expect($diff->missingTables)->toBe(['orders'])
        ->and($diff->extraTables)->toBe([])
        ->and($diff->modifiedTables)->toBe([]);
});

it('detects tables present in target but not in source', function (): void {
    $source = diffSchemaWith('db', [
        diffTable('users', [diffColumn('id')]),
    ]);

    $target = diffSchemaWith('db', [
        diffTable('users', [diffColumn('id')]),
        diffTable('legacy_data', [diffColumn('id')]),
    ]);

    $diff = (new SchemaDiffService)->diff($source, $target);

    expect($diff->extraTables)->toBe(['legacy_data'])
        ->and($diff->missingTables)->toBe([]);
});

it('detects missing columns in a shared table', function (): void {
    $source = diffSchemaWith('db', [
        diffTable('users', [
            diffColumn('id'),
            diffColumn('email', 'varchar'),
            diffColumn('phone', 'varchar'),
        ]),
    ]);

    $target = diffSchemaWith('db', [
        diffTable('users', [
            diffColumn('id'),
            diffColumn('email', 'varchar'),
        ]),
    ]);

    $diff = (new SchemaDiffService)->diff($source, $target);

    expect($diff->modifiedTables)->toHaveCount(1)
        ->and($diff->modifiedTables[0]->tableName)->toBe('users')
        ->and($diff->modifiedTables[0]->missingColumns)->toBe(['phone'])
        ->and($diff->modifiedTables[0]->extraColumns)->toBe([]);
});

it('detects extra columns on the target table', function (): void {
    $source = diffSchemaWith('db', [
        diffTable('users', [diffColumn('id')]),
    ]);

    $target = diffSchemaWith('db', [
        diffTable('users', [diffColumn('id'), diffColumn('legacy_col', 'varchar')]),
    ]);

    $diff = (new SchemaDiffService)->diff($source, $target);

    expect($diff->modifiedTables)->toHaveCount(1)
        ->and($diff->modifiedTables[0]->extraColumns)->toBe(['legacy_col'])
        ->and($diff->modifiedTables[0]->missingColumns)->toBe([]);
});

it('detects columns with changed types', function (): void {
    $source = diffSchemaWith('db', [
        diffTable('users', [diffColumn('id', 'int')]),
    ]);

    $target = diffSchemaWith('db', [
        diffTable('users', [diffColumn('id', 'bigint')]),
    ]);

    $diff = (new SchemaDiffService)->diff($source, $target);

    expect($diff->modifiedTables)->toHaveCount(1)
        ->and($diff->modifiedTables[0]->modifiedColumns)->toHaveCount(1)
        ->and($diff->modifiedTables[0]->modifiedColumns[0]->name)->toBe('id')
        ->and($diff->modifiedTables[0]->modifiedColumns[0]->sourceType)->toBe('int')
        ->and($diff->modifiedTables[0]->modifiedColumns[0]->targetType)->toBe('bigint');
});

it('detects columns with changed nullability', function (): void {
    $source = diffSchemaWith('db', [
        diffTable('users', [diffColumn('email', 'varchar', false)]),
    ]);

    $target = diffSchemaWith('db', [
        diffTable('users', [diffColumn('email', 'varchar', true)]),
    ]);

    $diff = (new SchemaDiffService)->diff($source, $target);

    expect($diff->modifiedTables)->toHaveCount(1)
        ->and($diff->modifiedTables[0]->modifiedColumns[0]->sourceNullable)->toBeFalse()
        ->and($diff->modifiedTables[0]->modifiedColumns[0]->targetNullable)->toBeTrue();
});

it('ignores type case differences (varchar vs VARCHAR)', function (): void {
    $source = diffSchemaWith('db', [
        diffTable('users', [diffColumn('email', 'varchar')]),
    ]);

    $target = diffSchemaWith('db', [
        diffTable('users', [diffColumn('email', 'VARCHAR')]),
    ]);

    $diff = (new SchemaDiffService)->diff($source, $target);

    expect($diff->isIdentical())->toBeTrue();
});

it('hasDifferences returns true when tables are missing', function (): void {
    $source = diffSchemaWith('db', [diffTable('users', [diffColumn('id')])]);
    $target = diffSchemaWith('db', []);

    $diff = (new SchemaDiffService)->diff($source, $target);

    expect($diff->hasDifferences())->toBeTrue()
        ->and($diff->isIdentical())->toBeFalse();
});

it('reports table diff hasDifferences only when columns differ', function (): void {
    $source = diffSchemaWith('db', [diffTable('users', [diffColumn('id')])]);
    $target = diffSchemaWith('db', [diffTable('users', [diffColumn('id')])]);

    $diff = (new SchemaDiffService)->diff($source, $target);

    expect($diff->modifiedTables)->toBe([]);
});
