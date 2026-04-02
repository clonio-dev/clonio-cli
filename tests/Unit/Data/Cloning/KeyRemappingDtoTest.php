<?php

declare(strict_types=1);

use App\Data\Cloning\KeyRemappingConfigData;
use App\Data\Cloning\KeyRemappingForeignKeyData;
use App\Data\Cloning\KeyRemappingTableData;
use App\Enums\KeyRemappingStrategy;

function makeTableData(string $table = 'users', string $pk = 'id'): KeyRemappingTableData
{
    return new KeyRemappingTableData(
        table: $table,
        primaryKey: $pk,
        strategy: KeyRemappingStrategy::NewUuid,
        rangeMin: 1,
        rangeMax: 999999,
        foreignKeys: [],
    );
}

it('KeyRemappingForeignKeyData stores fields correctly', function (): void {
    $fk = new KeyRemappingForeignKeyData(
        table: 'orders',
        column: 'user_id',
        selfReferential: false,
    );

    expect($fk->table)->toBe('orders')
        ->and($fk->column)->toBe('user_id')
        ->and($fk->selfReferential)->toBeFalse();
});

it('KeyRemappingTableData stores fields correctly', function (): void {
    $fk = new KeyRemappingForeignKeyData('orders', 'user_id', false);
    $table = new KeyRemappingTableData(
        table: 'users',
        primaryKey: 'id',
        strategy: KeyRemappingStrategy::RandomInteger,
        rangeMin: 100,
        rangeMax: 999999,
        foreignKeys: [$fk],
    );

    expect($table->table)->toBe('users')
        ->and($table->primaryKey)->toBe('id')
        ->and($table->strategy)->toBe(KeyRemappingStrategy::RandomInteger)
        ->and($table->rangeMin)->toBe(100)
        ->and($table->rangeMax)->toBe(999999)
        ->and($table->foreignKeys)->toHaveCount(1);
});

it('KeyRemappingConfigData isActive returns false for empty tables', function (): void {
    $config = new KeyRemappingConfigData(tables: []);
    expect($config->isActive())->toBeFalse();
});

it('KeyRemappingConfigData isActive returns true when tables are present', function (): void {
    $config = new KeyRemappingConfigData(tables: [makeTableData()]);
    expect($config->isActive())->toBeTrue();
});

it('KeyRemappingConfigData getTable returns correct table by name', function (): void {
    $users = makeTableData('users');
    $orders = makeTableData('orders');
    $config = new KeyRemappingConfigData(tables: [$users, $orders]);

    expect($config->getTable('users'))->toBe($users)
        ->and($config->getTable('orders'))->toBe($orders)
        ->and($config->getTable('missing'))->toBeNull();
});
