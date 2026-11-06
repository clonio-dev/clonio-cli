<?php

declare(strict_types=1);

use App\Data\Cloning\CloningConfigData;
use App\Data\Cloning\CloningOptionsData;
use App\Data\Cloning\KeyRemappingConfigData;
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
