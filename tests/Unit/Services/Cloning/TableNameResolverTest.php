<?php

declare(strict_types=1);

use App\Data\Cloning\CloningConfigData;
use App\Data\Cloning\CloningOptionsData;
use App\Data\Cloning\KeyRemappingConfigData;
use App\Data\Cloning\KeyRemappingForeignKeyData;
use App\Data\Cloning\KeyRemappingTableData;
use App\Data\Cloning\TableCloningConfigData;
use App\Data\Cloning\TableRowConfigData;
use App\Data\Schema\DatabaseSchemaData;
use App\Data\Schema\TableSchemaData;
use App\Enums\KeyRemappingStrategy;
use App\Services\Cloning\TableNameResolver;

// Issue #241: table names from the YAML and the flags resolve like the
// database resolves them: exact match first, then a unique case-insensitive one.

function tnrResolver(string ...$tables): TableNameResolver
{
    return new TableNameResolver(new DatabaseSchemaData('db', array_map(
        static fn (string $name): TableSchemaData => new TableSchemaData($name, [], []),
        array_values($tables),
    )));
}

function tnrTable(string $name, string $strategy = 'full'): TableCloningConfigData
{
    return new TableCloningConfigData($name, new TableRowConfigData($strategy, null, null), []);
}

it('keeps an exact table name', function (): void {
    expect(tnrResolver('users', 'Users')->resolve('Users'))->toBe('Users')
        ->and(tnrResolver('users', 'Users')->resolve('users'))->toBe('users');
});

it('resolves a name that differs only in case to the one matching table', function (): void {
    expect(tnrResolver('users', 'orders')->resolve('USERS'))->toBe('users');
});

it('keeps a name that matches no table as written', function (): void {
    expect(tnrResolver('users')->resolve('Ghosts'))->toBe('Ghosts');
});

it('rejects a name that matches several tables case-insensitively', function (): void {
    tnrResolver('users', 'Users')->resolve('USERS');
})->throws(InvalidArgumentException::class, 'Table name "USERS" is ambiguous: it matches users, Users. Use the exact name.');

it('resolves and dedupes a list of names', function (): void {
    expect(tnrResolver('users', 'orders')->resolveAll(['Users', 'users', 'ORDERS', 'ghosts']))
        ->toBe(['users', 'orders', 'ghosts']);
});

it('resolves tables keys and skip entries of a config, keeping everything else', function (): void {
    $options = new CloningOptionsData(100, false, false, false, true, 'en_US');
    $remapping = new KeyRemappingConfigData([]);
    $exact = tnrTable('orders');
    $config = new CloningConfigData('1', 'prod', $options, [tnrTable('USERS', 'first'), $exact], $remapping, ['Audit_Logs', 'audit_logs']);

    $resolved = tnrResolver('users', 'orders', 'audit_logs')->resolveConfig($config);

    expect($resolved->version)->toBe('1')
        ->and($resolved->connectionName)->toBe('prod')
        ->and($resolved->options)->toBe($options)
        ->and($resolved->keyRemapping)->toEqual($remapping)
        ->and($resolved->tables)->toHaveCount(2)
        ->and($resolved->tables[0]->tableName)->toBe('users')
        ->and($resolved->tables[0]->rows)->toBe($config->tables[0]->rows)
        ->and($resolved->tables[0]->columns)->toBe($config->tables[0]->columns)
        ->and($resolved->tables[1])->toBe($exact)
        ->and($resolved->skipTables)->toBe(['audit_logs']);
});

it('rejects two tables keys that resolve to the same table', function (): void {
    $config = new CloningConfigData('1', 'prod', new CloningOptionsData(100, false, false, false, true, 'en_US'), [tnrTable('users'), tnrTable('Users')]);

    tnrResolver('users')->resolveConfig($config);
})->throws(InvalidArgumentException::class, 'Two tables: entries refer to table "users". Keep one entry.');

// Issue #246: key_remapping references resolve like tables: keys, against the
// resolved config, so the remapping finds its table under the resolved name.

function tnrRemapConfig(array $tables, array $remaps): CloningConfigData
{
    return new CloningConfigData('1', 'prod', new CloningOptionsData(100, false, false, false, true, 'en_US'), $tables, $remaps === [] ? null : new KeyRemappingConfigData($remaps));
}

function tnrRemap(string $table, array $foreignKeys = []): KeyRemappingTableData
{
    return new KeyRemappingTableData($table, 'id', KeyRemappingStrategy::NewUuid, $foreignKeys);
}

it('resolves key_remapping tables and foreign key tables', function (): void {
    $config = tnrRemapConfig([tnrTable('USERS'), tnrTable('orders')], [
        tnrRemap('Users', [new KeyRemappingForeignKeyData('ORDERS', 'user_id', true), new KeyRemappingForeignKeyData('ghosts', 'user_id')]),
    ]);

    $remapping = tnrResolver('users', 'orders')->resolveConfig($config)->keyRemapping;

    expect($remapping)->toEqual(new KeyRemappingConfigData([
        new KeyRemappingTableData('users', 'id', KeyRemappingStrategy::NewUuid, [
            new KeyRemappingForeignKeyData('orders', 'user_id', true),
            new KeyRemappingForeignKeyData('ghosts', 'user_id'),
        ]),
    ]))->and($remapping?->getTable('users'))->not->toBeNull();
});

it('keeps a config without key_remapping without one', function (): void {
    expect(tnrResolver('users')->resolveConfig(tnrRemapConfig([tnrTable('users')], []))->keyRemapping)->toBeNull();
});

it('keeps several remapping entries written the same way for one table', function (): void {
    // Inline remapping makes one entry per remapping column of a table.
    $config = tnrRemapConfig([tnrTable('users')], [tnrRemap('users'), tnrRemap('users')]);

    expect(tnrResolver('users')->resolveConfig($config)->keyRemapping?->tables)->toHaveCount(2);
});

it('rejects two key_remapping entries that differ only in case', function (): void {
    $config = tnrRemapConfig([tnrTable('users')], [tnrRemap('users'), tnrRemap('Users')]);

    tnrResolver('users')->resolveConfig($config);
})->throws(InvalidArgumentException::class, 'Two key_remapping entries refer to table "users". Keep one entry.');

it('rejects a key_remapping entry that resolves to a table without tables: entry', function (): void {
    // Both casings exist in the source, so "users" stays "users" while only "Users" is configured.
    $config = tnrRemapConfig([tnrTable('Users')], [tnrRemap('users')]);

    tnrResolver('Users', 'users')->resolveConfig($config);
})->throws(InvalidArgumentException::class, 'key_remapping refers to table "users", which has no tables: entry.');

it('rejects an ambiguous key_remapping foreign key table', function (): void {
    $config = tnrRemapConfig([tnrTable('users')], [tnrRemap('users', [new KeyRemappingForeignKeyData('orders', 'user_id')])]);

    tnrResolver('users', 'Orders', 'ORDERS')->resolveConfig($config);
})->throws(InvalidArgumentException::class, 'Table name "orders" is ambiguous');
