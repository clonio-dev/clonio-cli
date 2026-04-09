<?php

declare(strict_types=1);

use App\Data\Cloning\KeyRemappingConfigData;
use App\Data\Cloning\KeyRemappingForeignKeyData;
use App\Data\Cloning\KeyRemappingTableData;
use App\Enums\KeyRemappingStrategy;
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
        rangeMin: 1,
        rangeMax: 999999,
        foreignKeys: $fks,
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
        rangeMin: 1,
        rangeMax: 999999,
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
