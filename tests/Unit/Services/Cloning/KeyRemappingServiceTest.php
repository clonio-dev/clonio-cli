<?php

declare(strict_types=1);

use App\Data\Cloning\KeyRemappingConfigData;
use App\Data\Cloning\KeyRemappingForeignKeyData;
use App\Data\Cloning\KeyRemappingTableData;
use App\Data\Schema\ColumnSchemaData;
use App\Enums\KeyRemappingStrategy;
use App\Exceptions\KeyRemappingExhaustedException;
use App\Services\Cloning\InMemoryKeyRemappingStore;
use App\Services\Cloning\KeyRemappingService;
use App\Services\Database\DatabaseConnectionService;

function makeKeyRemappingService(?InMemoryKeyRemappingStore $store = null): KeyRemappingService
{
    $connector = Mockery::mock(DatabaseConnectionService::class);

    return $store !== null
        ? new KeyRemappingService($connector, $store)
        : new KeyRemappingService($connector);
}

function makeKeyRemappingTable(string $table, string $pk = 'id', array $fks = []): KeyRemappingTableData
{
    return new KeyRemappingTableData(
        table: $table,
        primaryKey: $pk,
        strategy: KeyRemappingStrategy::NewUuid,
        foreignKeys: $fks,
    );
}

function makePkCol(string $type, bool $unsigned = false): ColumnSchemaData
{
    return new ColumnSchemaData(
        name: 'id',
        type: $type,
        nullable: false,
        default: null,
        isPrimary: true,
        unsigned: $unsigned,
    );
}

it('applyToRow does not modify row when store has no mappings', function (): void {
    $service = makeKeyRemappingService();
    $config = new KeyRemappingConfigData([makeKeyRemappingTable('users', 'id')]);

    $row = ['id' => 1, 'name' => 'Alice'];

    expect($service->applyToRow($row, 'users', $config))->toBe($row);
});

it('cleanup resets store mappings', function (): void {
    $store = new InMemoryKeyRemappingStore;
    $store->storeTable('users', ['1' => 'new-uuid']);

    $service = makeKeyRemappingService($store);
    $service->cleanup();

    expect($store->lookup('users', '1'))->toBeNull();
});

it('applyToRow remaps the primary key for a configured table', function (): void {
    $store = new InMemoryKeyRemappingStore;
    $store->storeTable('users', ['42' => 'new-uuid-for-42']);

    $service = makeKeyRemappingService($store);
    $config = new KeyRemappingConfigData([makeKeyRemappingTable('users', 'id')]);

    $row = ['id' => 42, 'name' => 'Alice'];
    $result = $service->applyToRow($row, 'users', $config);

    expect($result['id'])->toBe('new-uuid-for-42')
        ->and($result['name'])->toBe('Alice');
});

it('applyToRow keeps original PK value when no mapping exists', function (): void {
    $store = new InMemoryKeyRemappingStore;
    $store->storeTable('users', []);

    $service = makeKeyRemappingService($store);
    $config = new KeyRemappingConfigData([makeKeyRemappingTable('users', 'id')]);

    $row = ['id' => 99, 'name' => 'Bob'];
    $result = $service->applyToRow($row, 'users', $config);

    expect($result['id'])->toBe(99);
});

it('applyToRow remaps foreign key columns referencing another remapped table', function (): void {
    $store = new InMemoryKeyRemappingStore;
    $store->storeTable('users', ['10' => 'uuid-10']);

    $service = makeKeyRemappingService($store);

    $fk = new KeyRemappingForeignKeyData(table: 'orders', column: 'user_id', selfReferential: false);
    $usersTable = new KeyRemappingTableData(
        table: 'users',
        primaryKey: 'id',
        strategy: KeyRemappingStrategy::NewUuid,
        foreignKeys: [$fk],
    );
    $config = new KeyRemappingConfigData([$usersTable]);

    $row = ['id' => 5, 'user_id' => 10, 'amount' => 99.99];
    $result = $service->applyToRow($row, 'orders', $config);

    expect($result['user_id'])->toBe('uuid-10')
        ->and($result['id'])->toBe(5);
});

it('applyToRow skips FK column that does not exist on the row', function (): void {
    $store = new InMemoryKeyRemappingStore;
    $store->storeTable('users', ['1' => 'uuid-1']);

    $service = makeKeyRemappingService($store);

    $fk = new KeyRemappingForeignKeyData(table: 'orders', column: 'user_id', selfReferential: false);
    $usersTable = makeKeyRemappingTable('users', 'id', [$fk]);
    $config = new KeyRemappingConfigData([$usersTable]);

    $row = ['id' => 5, 'name' => 'test'];
    $result = $service->applyToRow($row, 'orders', $config);

    expect($result)->toBe(['id' => 5, 'name' => 'test']);
});

it('applyToRow does not modify row for unconfigured table', function (): void {
    $service = makeKeyRemappingService();

    $config = new KeyRemappingConfigData([makeKeyRemappingTable('users')]);

    $row = ['id' => 1, 'name' => 'Alice'];
    $result = $service->applyToRow($row, 'unconfigured_table', $config);

    expect($result)->toBe($row);
});

it('allocateIntegerIds returns ascending ids starting at MAX(id)+1', function (): void {
    $service = makeKeyRemappingService();
    $col = makePkCol('tinyint', true); // ceiling 255

    $sourceIds = ['10', '20', '30', '40', '50'];
    $existingMax = 200;

    $result = $service->allocateIntegerIds('users', $col, $sourceIds, $existingMax, []);

    expect(array_values($result))->toBe(['201', '202', '203', '204', '205'])
        ->and(array_keys($result))->toBe([10, 20, 30, 40, 50]);
});

it('allocateIntegerIds falls back to gap-fill when upper region exhausted', function (): void {
    $service = makeKeyRemappingService();
    $col = makePkCol('tinyint', true); // ceiling 255

    $sourceIds = ['1', '2', '3', '4', '5'];
    $existingMax = 253;
    $existingIds = [1, 2, 250, 251, 252, 253]; // gaps in [1,253] = [3..249] (lots of room)

    $result = $service->allocateIntegerIds('users', $col, $sourceIds, $existingMax, $existingIds);

    // upper slots: 255 - 254 + 1 = 2 → IDs 254, 255
    // remaining 3: first 3 gaps (skipping existing 1,2) = 3, 4, 5
    expect(array_values($result))->toBe(['254', '255', '3', '4', '5']);
});

it('allocateIntegerIds throws when type ceiling cannot host row count', function (): void {
    $service = makeKeyRemappingService();
    $col = makePkCol('tinyint', true); // ceiling 255

    $sourceIds = array_map(static fn (int $i): string => (string) $i, range(1, 300));
    $existingMax = 0;

    $service->allocateIntegerIds('big_table', $col, $sourceIds, $existingMax, []);
})->throws(KeyRemappingExhaustedException::class);

it('allocateIntegerIds starts at 1 when source table is empty (existingMax=0)', function (): void {
    $service = makeKeyRemappingService();
    $col = makePkCol('int');

    $result = $service->allocateIntegerIds('users', $col, ['7'], 0, []);

    expect($result)->toBe([7 => '1']);
});

it('allocateIntegerIds for signed int never returns negative ids', function (): void {
    $service = makeKeyRemappingService();
    $col = makePkCol('int'); // signed ceiling 2147483647

    $result = $service->allocateIntegerIds('users', $col, ['1', '2'], 0, []);

    expect(array_values($result))->toBe(['1', '2']);
});

it('allocateIntegerIds preserves source order when assigning new ids', function (): void {
    $service = makeKeyRemappingService();
    $col = makePkCol('int', true);

    $sourceIds = ['100', '99', '500', '7'];

    $result = $service->allocateIntegerIds('t', $col, $sourceIds, 1000, []);

    expect($result)->toBe([
        100 => '1001',
        99 => '1002',
        500 => '1003',
        7 => '1004',
    ]);
});

it('allocateIntegerIds returns empty mapping for empty source', function (): void {
    $service = makeKeyRemappingService();
    $col = makePkCol('int');

    $result = $service->allocateIntegerIds('users', $col, [], 0, []);

    expect($result)->toBe([]);
});
