<?php

declare(strict_types=1);

use App\Data\Cloning\CloningConfigData;
use App\Data\Cloning\CloningOptionsData;
use App\Data\Cloning\TableCloningConfigData;
use App\Data\Cloning\TableRowConfigData;
use App\Data\Schema\DatabaseSchemaData;
use App\Data\Schema\TableSchemaData;
use App\Enums\ClearMode;
use App\Services\Cloning\TableConfigResolver;

/**
 * @param  list<string>  $names
 */
function schemaWith(array $names): DatabaseSchemaData
{
    return new DatabaseSchemaData(
        databaseName: 'test',
        tables: array_map(
            static fn (string $name): TableSchemaData => new TableSchemaData($name, [], []),
            $names,
        ),
    );
}

/**
 * @param  list<TableCloningConfigData>  $tables
 * @param  list<string>  $skip
 */
function configWith(array $tables, array $skip = []): CloningConfigData
{
    return new CloningConfigData(
        version: '1',
        connectionName: 'source',
        options: new CloningOptionsData(1000, false, false, false, true, 'en_US'),
        tables: $tables,
        keyRemapping: null,
        skipTables: $skip,
    );
}

function tableEntry(string $name, string $strategy = 'full', ?int $limit = null): TableCloningConfigData
{
    return new TableCloningConfigData(
        tableName: $name,
        rows: new TableRowConfigData(strategy: $strategy, limit: $limit, sortBy: null, clear: ClearMode::None),
        columns: [],
    );
}

it('expands a regex key to every matching source table', function (): void {
    $config = configWith([
        tableEntry('/^application_logs_archive_\d{2}_\d{4}$/', 'last', 1),
    ]);

    $schema = schemaWith([
        'application_logs_archive_02_2026',
        'application_logs_archive_03_2026',
        'application_logs_archive_04_2026',
        'users',
    ]);

    $resolved = (new TableConfigResolver)->resolve($config, $schema);

    $names = array_map(fn (TableCloningConfigData $t): string => $t->tableName, $resolved->tables);

    expect($names)->toBe([
        'application_logs_archive_02_2026',
        'application_logs_archive_03_2026',
        'application_logs_archive_04_2026',
    ]);

    foreach ($resolved->tables as $table) {
        expect($table->rows->strategy)->toBe('last');
        expect($table->rows->limit)->toBe(1);
    }
});

it('lets the last matching entry win over an earlier regex', function (): void {
    $config = configWith([
        tableEntry('/^app_logs_.*/', 'last', 1),
        tableEntry('app_logs_critical', 'full'),
    ]);

    $schema = schemaWith(['app_logs_2026', 'app_logs_critical']);

    $resolved = (new TableConfigResolver)->resolve($config, $schema);

    $byName = [];
    foreach ($resolved->tables as $table) {
        $byName[$table->tableName] = $table;
    }

    expect($byName['app_logs_2026']->rows->strategy)->toBe('last');
    expect($byName['app_logs_critical']->rows->strategy)->toBe('full');
});

it('preserves a literal entry absent from the source for NotFound reporting', function (): void {
    $config = configWith([
        tableEntry('users'),
        tableEntry('legacy_orders'),
    ]);

    $schema = schemaWith(['users']);

    $resolved = (new TableConfigResolver)->resolve($config, $schema);

    $names = array_map(fn (TableCloningConfigData $t): string => $t->tableName, $resolved->tables);

    expect($names)->toContain('users');
    expect($names)->toContain('legacy_orders');
});

it('drops a regex key that matches nothing', function (): void {
    $config = configWith([
        tableEntry('/^no_such_.*/', 'last', 1),
        tableEntry('users'),
    ]);

    $schema = schemaWith(['users']);

    $resolved = (new TableConfigResolver)->resolve($config, $schema);

    $names = array_map(fn (TableCloningConfigData $t): string => $t->tableName, $resolved->tables);

    expect($names)->toBe(['users']);
});

it('ignores source tables that match no config entry', function (): void {
    $config = configWith([tableEntry('users')]);

    $schema = schemaWith(['users', 'sessions', 'cache']);

    $resolved = (new TableConfigResolver)->resolve($config, $schema);

    $names = array_map(fn (TableCloningConfigData $t): string => $t->tableName, $resolved->tables);

    expect($names)->toBe(['users']);
});

it('expands a regex skip:strategy rule into concrete skipTables', function (): void {
    $config = configWith([
        tableEntry('/^tmp_.*/', 'skip'),
        tableEntry('users'),
    ]);

    $schema = schemaWith(['tmp_a', 'tmp_b', 'users']);

    $resolved = (new TableConfigResolver)->resolve($config, $schema);

    expect($resolved->skipTables)->toContain('tmp_a');
    expect($resolved->skipTables)->toContain('tmp_b');
});

it('expands a regex entry in the top-level skip list', function (): void {
    $config = configWith([tableEntry('users')], ['/^tmp_.*/']);

    $schema = schemaWith(['tmp_a', 'tmp_b', 'users']);

    $resolved = (new TableConfigResolver)->resolve($config, $schema);

    expect($resolved->skipTables)->toContain('tmp_a');
    expect($resolved->skipTables)->toContain('tmp_b');
});

it('matches literal keys case-insensitively', function (): void {
    $config = configWith([tableEntry('Users')]);

    $schema = schemaWith(['users']);

    $resolved = (new TableConfigResolver)->resolve($config, $schema);

    expect($resolved->tables)->toHaveCount(1);
    expect($resolved->tables[0]->tableName)->toBe('users');
});

it('leaves an all-literal config behaviourally unchanged', function (): void {
    $config = configWith([
        tableEntry('users', 'full'),
        tableEntry('audit_logs', 'first', 100),
    ]);

    $schema = schemaWith(['users', 'audit_logs']);

    $resolved = (new TableConfigResolver)->resolve($config, $schema);

    $byName = [];
    foreach ($resolved->tables as $table) {
        $byName[$table->tableName] = $table;
    }

    expect($byName)->toHaveKeys(['users', 'audit_logs']);
    expect($byName['users']->rows->strategy)->toBe('full');
    expect($byName['audit_logs']->rows->strategy)->toBe('first');
    expect($byName['audit_logs']->rows->limit)->toBe(100);
});
