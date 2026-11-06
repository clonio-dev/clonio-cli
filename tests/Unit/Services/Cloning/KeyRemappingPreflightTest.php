<?php

declare(strict_types=1);

use App\Data\Cloning\CloningConfigData;
use App\Data\Cloning\CloningOptionsData;
use App\Data\Cloning\KeyRemappingConfigData;
use App\Data\Cloning\KeyRemappingForeignKeyData;
use App\Data\Cloning\KeyRemappingTableData;
use App\Data\Cloning\TableCloningConfigData;
use App\Data\Cloning\TableRowConfigData;
use App\Data\Schema\ColumnSchemaData;
use App\Data\Schema\DatabaseSchemaData;
use App\Data\Schema\TableSchemaData;
use App\Enums\KeyRemappingStrategy;
use App\Services\Cloning\KeyRemappingPreflight;

// Issue #254: a key remapping that cannot work for the source schema is a
// validation error, found before any mapping is generated.

function krpConfig(array $remaps, string $rowStrategy = 'full', string ...$tables): CloningConfigData
{
    return new CloningConfigData(
        '1',
        'prod',
        new CloningOptionsData(100, false, false, false, true, 'en_US'),
        array_map(static fn (string $t): TableCloningConfigData => new TableCloningConfigData($t, new TableRowConfigData($rowStrategy, null, null), []), $tables === [] ? ['users'] : array_values($tables)),
        $remaps === [] ? null : new KeyRemappingConfigData($remaps),
    );
}

function krpSchema(string $idType): DatabaseSchemaData
{
    return new DatabaseSchemaData('db', [new TableSchemaData('users', [new ColumnSchemaData('id', $idType, false, null, true)], [])]);
}

function krpRemap(KeyRemappingStrategy $strategy, string $table = 'users', string $column = 'id'): KeyRemappingTableData
{
    return new KeyRemappingTableData($table, $column, $strategy, []);
}

it('accepts remappings that fit the source column', function (KeyRemappingStrategy $strategy, string $type): void {
    expect(new KeyRemappingPreflight()->problems(krpConfig([krpRemap($strategy)]), krpSchema($type)))->toBe([]);
})->with([
    'integer key' => [KeyRemappingStrategy::RandomInteger, 'bigint'],
    'uuid on text key' => [KeyRemappingStrategy::NewUuid, 'varchar'],
    'uuid on integer key' => [KeyRemappingStrategy::NewUuid, 'int'],
]);

it('accepts a config without key remapping', function (): void {
    expect(new KeyRemappingPreflight()->problems(krpConfig([]), krpSchema('varchar')))->toBe([]);
});

it('reports random_integer on a non-integer column', function (): void {
    expect(new KeyRemappingPreflight()->problems(krpConfig([krpRemap(KeyRemappingStrategy::RandomInteger)]), krpSchema('varchar')))->toBe([
        'Cannot remap users.id: random_integer needs an integer column, but "id" is varchar. Use new_uuid for UUID or text keys.',
    ]);
});

it('reports a missing table and a missing column', function (): void {
    $config = krpConfig([
        krpRemap(KeyRemappingStrategy::NewUuid, 'users', 'uuid'),
        krpRemap(KeyRemappingStrategy::NewUuid, 'ghosts'),
    ], 'full', 'users', 'ghosts');

    expect(new KeyRemappingPreflight()->problems($config, krpSchema('int')))->toBe([
        'Cannot remap users.uuid: column "uuid" does not exist in the source table.',
        'Cannot remap ghosts.id: table "ghosts" does not exist in the source.',
    ]);
});

it('skips schema-only tables and tables without a remapping entry', function (): void {
    // Schema-only tables get no key mappings; "orders" has no remapping entry.
    $schemaOnly = krpConfig([krpRemap(KeyRemappingStrategy::RandomInteger)], 'schema');
    $unrelated = krpConfig([krpRemap(KeyRemappingStrategy::RandomInteger, 'ghosts')], 'full', 'orders');

    expect(new KeyRemappingPreflight()->problems($schemaOnly, krpSchema('varchar')))->toBe([])
        ->and(new KeyRemappingPreflight()->problems($unrelated, krpSchema('varchar')))->toBe([]);
});

it('keeps checking after a table without a remapping entry', function (): void {
    $config = krpConfig([krpRemap(KeyRemappingStrategy::RandomInteger)], 'full', 'orders', 'users');

    expect(new KeyRemappingPreflight()->problems($config, krpSchema('varchar')))->toHaveCount(1);
});

// Issue #161: polymorphic foreign keys.

function krpPolySchema(): DatabaseSchemaData
{
    $id = new ColumnSchemaData('id', 'bigint', false, null, true);

    return new DatabaseSchemaData('db', [
        new TableSchemaData('users', [$id], []),
        new TableSchemaData('companies', [$id], []),
        new TableSchemaData('addresses', [$id, new ColumnSchemaData('addressable_type', 'varchar', false, null, false), new ColumnSchemaData('addressable_id', 'bigint', false, null, false)], []),
    ]);
}

function krpPolyRemap(string $table, ?string $typeValue, string $typeColumn = 'addressable_type'): KeyRemappingTableData
{
    return new KeyRemappingTableData($table, 'id', KeyRemappingStrategy::RandomInteger, [
        new KeyRemappingForeignKeyData('addresses', 'addressable_id', typeColumn: $typeValue === null ? null : $typeColumn, typeValue: $typeValue),
    ]);
}

it('accepts a polymorphic column with one type value per remapped table', function (): void {
    $config = krpConfig([krpPolyRemap('users', 'user'), krpPolyRemap('companies', 'company')], 'full', 'users', 'companies');

    expect(new KeyRemappingPreflight()->problems($config, krpPolySchema()))->toBe([]);
});

it('reports a foreign key column listed under several tables without a type', function (?string $companyType): void {
    $config = krpConfig([krpPolyRemap('users', null), krpPolyRemap('companies', $companyType)], 'full', 'users', 'companies');

    expect(new KeyRemappingPreflight()->problems($config, krpPolySchema()))->toBe([
        'Cannot remap addresses.addressable_id: it is listed under users, companies. Set type_column and type_value on each entry for a polymorphic relation.',
    ]);
})->with(['no type on either' => [null], 'type on one' => ['company']]);

it('reports the same type value listed twice', function (): void {
    $config = krpConfig([krpPolyRemap('users', 'user'), krpPolyRemap('companies', 'user')], 'full', 'users', 'companies');

    expect(new KeyRemappingPreflight()->problems($config, krpPolySchema()))->toBe([
        'Cannot remap addresses.addressable_id: the same type_value is listed more than once (under users, companies).',
    ]);
});

it('names a remapped table once when it lists the same column twice', function (): void {
    $fk = new KeyRemappingForeignKeyData('addresses', 'addressable_id');
    $config = krpConfig([new KeyRemappingTableData('users', 'id', KeyRemappingStrategy::RandomInteger, [$fk, $fk])], 'full', 'users');

    expect(new KeyRemappingPreflight()->problems($config, krpPolySchema()))->toBe([
        'Cannot remap addresses.addressable_id: it is listed under users. Set type_column and type_value on each entry for a polymorphic relation.',
    ]);
});

it('ignores foreign keys of tables that are not remapped', function (): void {
    // "companies" is schema-only here, so only users rewrites addressable_id.
    $config = krpConfig([krpPolyRemap('users', null), krpPolyRemap('companies', null)], 'full', 'users');

    expect(new KeyRemappingPreflight()->problems($config, krpPolySchema()))->toBe([]);
});

it('reports a type column missing from the source table', function (): void {
    $config = krpConfig([krpPolyRemap('users', 'user', 'owner_type'), krpPolyRemap('companies', 'company')], 'full', 'users', 'companies');

    expect(new KeyRemappingPreflight()->problems($config, krpPolySchema()))->toBe([
        'Cannot remap addresses.addressable_id for users: type column "owner_type" does not exist in the source table.',
    ]);
});

it('skips the type column check when the foreign key table is not in the source', function (): void {
    $config = krpConfig([new KeyRemappingTableData('users', 'id', KeyRemappingStrategy::RandomInteger, [
        new KeyRemappingForeignKeyData('ghosts', 'owner_id', typeColumn: 'owner_type', typeValue: 'user'),
    ])], 'full', 'users');

    expect(new KeyRemappingPreflight()->problems($config, krpPolySchema()))->toBe([]);
});

it('checks the type column of every polymorphic foreign key of a table', function (): void {
    $config = krpConfig([new KeyRemappingTableData('users', 'id', KeyRemappingStrategy::RandomInteger, [
        new KeyRemappingForeignKeyData('addresses', 'created_by'),
        new KeyRemappingForeignKeyData('ghosts', 'owner_id', typeColumn: 'owner_type', typeValue: 'user'),
        new KeyRemappingForeignKeyData('addresses', 'addressable_id', typeColumn: 'owner_type', typeValue: 'user'),
    ])], 'full', 'users');

    expect(new KeyRemappingPreflight()->problems($config, krpPolySchema()))->toBe([
        'Cannot remap addresses.addressable_id for users: type column "owner_type" does not exist in the source table.',
    ]);
});

it('checks every foreign key column for conflicts', function (): void {
    $fk = static fn (string $table, string $column, ?string $type = null): KeyRemappingForeignKeyData => new KeyRemappingForeignKeyData($table, $column, typeColumn: $type === null ? null : 'addressable_type', typeValue: $type);
    $config = krpConfig([
        new KeyRemappingTableData('users', 'id', KeyRemappingStrategy::RandomInteger, [$fk('orders', 'user_id'), $fk('notes', 'owner_id')]),
        new KeyRemappingTableData('companies', 'id', KeyRemappingStrategy::RandomInteger, [$fk('notes', 'owner_id'), $fk('addresses', 'addressable_id', 'x')]),
        new KeyRemappingTableData('teams', 'id', KeyRemappingStrategy::RandomInteger, [$fk('addresses', 'addressable_id', 'x')]),
    ], 'full', 'users', 'companies', 'teams');
    $id = new ColumnSchemaData('id', 'bigint', false, null, true);
    $schema = new DatabaseSchemaData('db', [new TableSchemaData('users', [$id], []), new TableSchemaData('companies', [$id], []), new TableSchemaData('teams', [$id], [])]);

    expect(new KeyRemappingPreflight()->problems($config, $schema))->toBe([
        'Cannot remap notes.owner_id: it is listed under users, companies. Set type_column and type_value on each entry for a polymorphic relation.',
        'Cannot remap addresses.addressable_id: the same type_value is listed more than once (under companies, teams).',
    ]);
});
