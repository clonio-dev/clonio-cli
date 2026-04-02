<?php

declare(strict_types=1);

use App\Data\Cloning\KeyRemappingConfigData;
use App\Data\Cloning\KeyRemappingForeignKeyData;
use App\Data\Cloning\KeyRemappingTableData;
use App\Enums\KeyRemappingStrategy;
use App\Services\Cloning\KeyRemappingService;
use App\Services\Database\DatabaseConnectionService;

function makeKeyRemappingService(): KeyRemappingService
{
    $connector = Mockery::mock(DatabaseConnectionService::class);

    return new KeyRemappingService($connector);
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

it('getMappings returns empty array initially', function (): void {
    $service = makeKeyRemappingService();
    expect($service->getMappings())->toBeEmpty();
});

it('cleanup resets mappings to empty', function (): void {
    $service = makeKeyRemappingService();

    // Set mappings via reflection
    $ref = new ReflectionProperty(KeyRemappingService::class, 'mappings');
    $ref->setValue($service, ['users' => ['1' => 'new-uuid']]);

    expect($service->getMappings())->not->toBeEmpty();

    $service->cleanup();

    expect($service->getMappings())->toBeEmpty();
});

it('applyToRow remaps the primary key for a configured table', function (): void {
    $service = makeKeyRemappingService();

    $ref = new ReflectionProperty(KeyRemappingService::class, 'mappings');
    $ref->setValue($service, ['users' => ['42' => 'new-uuid-for-42']]);

    $config = new KeyRemappingConfigData([makeKeyRemappingTable('users', 'id')]);

    $row = ['id' => 42, 'name' => 'Alice'];
    $result = $service->applyToRow($row, 'users', $config);

    expect($result['id'])->toBe('new-uuid-for-42')
        ->and($result['name'])->toBe('Alice');
});

it('applyToRow keeps original PK value when no mapping exists', function (): void {
    $service = makeKeyRemappingService();

    $ref = new ReflectionProperty(KeyRemappingService::class, 'mappings');
    $ref->setValue($service, ['users' => []]);

    $config = new KeyRemappingConfigData([makeKeyRemappingTable('users', 'id')]);

    $row = ['id' => 99, 'name' => 'Bob'];
    $result = $service->applyToRow($row, 'users', $config);

    expect($result['id'])->toBe(99);
});

it('applyToRow remaps foreign key columns referencing another remapped table', function (): void {
    $service = makeKeyRemappingService();

    $ref = new ReflectionProperty(KeyRemappingService::class, 'mappings');
    $ref->setValue($service, [
        'users' => ['10' => 'uuid-10'],
    ]);

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
    $service = makeKeyRemappingService();

    $ref = new ReflectionProperty(KeyRemappingService::class, 'mappings');
    $ref->setValue($service, ['users' => ['1' => 'uuid-1']]);

    $fk = new KeyRemappingForeignKeyData(table: 'orders', column: 'user_id', selfReferential: false);
    $usersTable = makeKeyRemappingTable('users', 'id', [$fk]);
    $config = new KeyRemappingConfigData([$usersTable]);

    // Row doesn't have 'user_id'
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
