<?php

declare(strict_types=1);

use App\Data\Cloning\CloningConfigData;
use App\Data\Cloning\CloningOptionsData;
use App\Data\Cloning\KeyRemappingConfigData;
use App\Data\Cloning\TableCloningConfigData;
use App\Data\Cloning\TableRowConfigData;
use App\Data\Schema\DatabaseSchemaData;
use App\Data\Schema\TableSchemaData;
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
        ->and($resolved->keyRemapping)->toBe($remapping)
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
