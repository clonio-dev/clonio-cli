<?php

declare(strict_types=1);

use App\Data\Cloning\CloningConfigData;
use App\Data\Cloning\CloningOptionsData;
use App\Data\Cloning\ColumnCloningConfigData;
use App\Data\Cloning\KeyRemappingConfigData;
use App\Data\Cloning\KeyRemappingForeignKeyData;
use App\Data\Cloning\KeyRemappingTableData;
use App\Data\Cloning\TableCloningConfigData;
use App\Data\Cloning\TableRowConfigData;
use App\Data\Schema\ColumnSchemaData;
use App\Data\Schema\DatabaseSchemaData;
use App\Data\Schema\TableSchemaData;
use App\Enums\ClearMode;
use App\Enums\KeyRemappingStrategy;
use App\Services\Cloning\TableConfigResolver;
use Illuminate\Support\Facades\Log;

/**
 * @param  list<string>  $names
 * @param  array<string, list<string>>  $columns  column names per table
 */
function schemaWith(array $names, array $columns = []): DatabaseSchemaData
{
    return new DatabaseSchemaData(
        databaseName: 'test',
        tables: array_map(
            static fn (string $name): TableSchemaData => new TableSchemaData($name, array_map(
                static fn (string $column): ColumnSchemaData => new ColumnSchemaData($column, 'varchar', true, null, false),
                $columns[$name] ?? [],
            ), []),
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

it('falls back to a case-insensitive match when no table has the literal key exactly', function (): void {
    $config = configWith([tableEntry('Users')]);

    $schema = schemaWith(['users']);

    $resolved = (new TableConfigResolver)->resolve($config, $schema);

    expect($resolved->tables)->toHaveCount(1);
    expect($resolved->tables[0]->tableName)->toBe('users');
});

it('matches a literal key exactly before any case-insensitive match', function (): void {
    // PostgreSQL can hold both; the key names only the exact one.
    $config = configWith([tableEntry('users', 'first', 10)]);

    $schema = schemaWith(['Users', 'users']);

    $resolved = (new TableConfigResolver)->resolve($config, $schema);

    expect(array_map(static fn (TableCloningConfigData $t): string => $t->tableName, $resolved->tables))->toBe(['users']);
});

it('keeps an ambiguous case-insensitive literal key as not found', function (): void {
    $config = configWith([tableEntry('USERS')]);

    $schema = schemaWith(['Users', 'users']);

    $resolved = (new TableConfigResolver)->resolve($config, $schema);

    expect(array_map(static fn (TableCloningConfigData $t): string => $t->tableName, $resolved->tables))->toBe(['USERS']);
});

it('matches regex keys as written, case-sensitively unless they carry the i flag', function (string $key, array $expected): void {
    $config = configWith([tableEntry($key)]);

    $schema = schemaWith(['USERS', 'users']);

    $resolved = (new TableConfigResolver)->resolve($config, $schema);

    expect(array_map(static fn (TableCloningConfigData $t): string => $t->tableName, $resolved->tables))->toBe($expected);
})->with([
    'case-sensitive' => ['/^users$/', ['users']],
    'i flag' => ['/^users$/i', ['USERS', 'users']],
]);

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

it('keeps every skip entry once, literal or expanded from a regex', function (): void {
    $config = configWith([tableEntry('users')], skip: ['/^tmp_/', 'cache', 'tmp_a']);

    $schema = schemaWith(['tmp_a', 'tmp_b', 'users']);

    $resolved = (new TableConfigResolver)->resolve($config, $schema);

    expect($resolved->skipTables)->toBe(['tmp_a', 'tmp_b', 'cache']);
});

it('lets an exact literal key beat a later regex', function (): void {
    $config = configWith([
        tableEntry('users', 'first', 10),
        tableEntry('/^users$/i', 'full'),
    ]);

    $schema = schemaWith(['Users', 'users']);

    $resolved = (new TableConfigResolver)->resolve($config, $schema);

    // YAML order: users is mentioned first (the literal), Users only by the regex.
    expect(array_map(static fn (TableCloningConfigData $t): array => [$t->tableName, $t->rows->strategy], $resolved->tables))
        ->toBe([['users', 'first'], ['Users', 'full']]);
});

it('lets a literal key beat a regex whatever their order', function (array $order): void {
    $entries = ['regex' => tableEntry('/^logs_/', 'last', 1), 'literal' => tableEntry('logs_critical', 'full')];

    $resolved = (new TableConfigResolver)->resolve(
        configWith(array_map(static fn (string $key): TableCloningConfigData => $entries[$key], $order)),
        schemaWith(['logs_2025', 'logs_critical']),
    );

    expect($resolved->getTable('logs_critical')?->rows->strategy)->toBe('full')
        ->and($resolved->getTable('logs_2025')?->rows->strategy)->toBe('last');
})->with([
    'regex first' => [['regex', 'literal']],
    'literal first' => [['literal', 'regex']],
]);

it('lets an exact literal key beat a case-insensitive one whatever their order', function (array $order): void {
    $entries = [
        'exact' => new TableCloningConfigData('users', new TableRowConfigData('full', null, null), [columnRule('email', 'hash')]),
        'insensitive' => new TableCloningConfigData('USERS', new TableRowConfigData('first', 10, null), [columnRule('email', 'fake'), columnRule('iban', 'mask')]),
    ];

    $resolved = (new TableConfigResolver)->resolve(
        configWith(array_map(static fn (string $key): TableCloningConfigData => $entries[$key], $order)),
        schemaWith(['users']),
    );

    expect($resolved->getTable('users')?->rows->strategy)->toBe('full')
        ->and($resolved->getTable('users')?->columns)->toEqual([columnRule('email', 'hash'), columnRule('iban', 'mask')]);
})->with([
    'exact first' => [['exact', 'insensitive']],
    'insensitive first' => [['insensitive', 'exact']],
]);

it('lets the strictest of several matching regex keys win, whatever their order', function (array $first, array $second, string $strategy, ?int $limit): void {
    foreach ([[$first, $second], [$second, $first]] as [$a, $b]) {
        $resolved = (new TableConfigResolver)->resolve(
            configWith([tableEntry('/^logs_/', ...$a), tableEntry('/_2025$/', ...$b)]),
            schemaWith(['logs_2025']),
        );

        expect($resolved->getTable('logs_2025')?->rows)->toEqual(tableEntry('x', $strategy, $limit)->rows);
    }
})->with([
    'schema over full' => [['schema'], ['full'], 'schema', null],
    'a row limit over full' => [['last', 1], ['full'], 'last', 1],
    'schema over a row limit' => [['schema'], ['first', 1], 'schema', null],
    'skip over schema' => [['skip'], ['schema'], 'skip', null],
    'the smaller row limit, first over last' => [['first', 10], ['last', 100000], 'first', 10],
    'the smaller row limit, last over first' => [['last', 10], ['first', 100], 'last', 10],
    'any row limit over none' => [['first', 100000], ['last'], 'first', 100000],
]);

it('lets the later of equally strict regex keys win', function (): void {
    $resolved = (new TableConfigResolver)->resolve(
        configWith([tableEntry('/^logs_/', 'first', 10), tableEntry('/_2025$/', 'last', 10)]),
        schemaWith(['logs_2025', 'logs_2026']),
    );

    expect($resolved->getTable('logs_2025')?->rows->strategy)->toBe('last')
        ->and($resolved->getTable('logs_2026')?->rows->strategy)->toBe('first');
});

it('keeps a regex skip against any other regex, but not against a literal key', function (array $order): void {
    $entries = [
        'skip' => tableEntry('/^audit_/', 'skip'),
        'catch-all' => tableEntry('/.*/', 'first', 100),
        'literal' => tableEntry('audit_keep', 'full'),
    ];

    $resolved = (new TableConfigResolver)->resolve(
        configWith(array_map(static fn (string $key): TableCloningConfigData => $entries[$key], $order)),
        schemaWith(['audit_2024', 'audit_keep', 'orders']),
    );

    expect($resolved->getTable('audit_2024')?->rows->strategy)->toBe('skip')
        ->and($resolved->getTable('audit_keep')?->rows->strategy)->toBe('full')
        ->and($resolved->getTable('orders')?->rows->strategy)->toBe('first')
        ->and($resolved->skipTables)->toBe(['audit_2024']);
})->with([
    'catch-all last' => [['skip', 'catch-all', 'literal']],
    'catch-all first' => [['catch-all', 'skip', 'literal']],
    'literal first' => [['literal', 'skip', 'catch-all']],
]);

it('takes the rows of the last regex skip when several regex keys skip a table', function (): void {
    $resolved = (new TableConfigResolver)->resolve(
        configWith([tableEntry('/^audit_/', 'skip', 1), tableEntry('/_2024$/', 'skip', 2), tableEntry('/.*/', 'full')]),
        schemaWith(['audit_2024']),
    );

    expect($resolved->getTable('audit_2024')?->rows->limit)->toBe(2);
});

it('keeps a literal skip when a broader regex follows', function (): void {
    $resolved = (new TableConfigResolver)->resolve(
        configWith([tableEntry('audit_logs', 'skip'), tableEntry('/.*/', 'first', 100)]),
        schemaWith(['audit_logs', 'orders']),
    );

    expect($resolved->skipTables)->toBe(['audit_logs']);
});

function columnRule(string $column, string $strategy): ColumnCloningConfigData
{
    return new ColumnCloningConfigData($column, $strategy, null, [], null, null, null, null, null, null);
}

it('lets a later entry override an earlier skip strategy', function (): void {
    $config = configWith([
        tableEntry('/^logs_/', 'skip'),
        tableEntry('logs_critical', 'full'),
    ]);

    $resolved = (new TableConfigResolver)->resolve($config, schemaWith(['logs_2025', 'logs_critical']));

    expect($resolved->skipTables)->toBe(['logs_2025']);
});

it('lists a table that is both in the skip list and has strategy skip once', function (): void {
    $config = configWith([tableEntry('users', 'skip'), tableEntry('orders', 'skip')], skip: ['users']);

    $resolved = (new TableConfigResolver)->resolve($config, schemaWith(['users', 'orders']));

    expect($resolved->skipTables)->toBe(['users', 'orders']);
});

it('configures a table that several schemas share once', function (string $key): void {
    $resolved = (new TableConfigResolver)->resolve(
        configWith([tableEntry($key, 'first', 10), tableEntry('/^orders$/')], skip: ['Users']),
        schemaWith(['users', 'users', 'orders']),
    );

    expect(array_map(static fn (TableCloningConfigData $t): string => $t->tableName, $resolved->tables))->toBe(['users', 'orders'])
        ->and($resolved->skipTables)->toBe(['users']);
})->with(['literal' => 'users', 'regex' => '/^users$/', 'case-insensitive literal' => 'USERS']);

it('leaves a skip key that names no source table to be reported as not found', function (): void {
    $config = configWith([tableEntry('audti_logs', 'skip'), tableEntry('/.*/', 'full')]);

    $resolved = (new TableConfigResolver)->resolve($config, schemaWith(['audit_logs']));

    expect($resolved->skipTables)->toBe([])
        ->and(array_map(static fn (TableCloningConfigData $t): string => $t->tableName, $resolved->tables))->toBe(['audti_logs', 'audit_logs']);
});

it('skips a table under its source name only', function (): void {
    $config = configWith([tableEntry('Users', 'skip')], skip: ['Sessions']);

    $resolved = (new TableConfigResolver)->resolve($config, schemaWith(['users', 'sessions']));

    expect($resolved->skipTables)->toBe(['sessions', 'users']);
});

it('resolves key remapping tables and foreign keys like table keys', function (): void {
    $config = new CloningConfigData(
        version: '1',
        connectionName: 'source',
        options: new CloningOptionsData(1000, false, false, false, true, 'en_US'),
        tables: [tableEntry('Users'), tableEntry('orders')],
        keyRemapping: new KeyRemappingConfigData([
            new KeyRemappingTableData('Users', 'id', KeyRemappingStrategy::NewUuid, [
                new KeyRemappingForeignKeyData('Orders', 'user_id'),
                new KeyRemappingForeignKeyData('Users', 'parent_id', selfReferential: true),
            ]),
        ]),
    );

    $remapping = (new TableConfigResolver)->resolve($config, schemaWith(['users', 'orders']))->keyRemapping;

    expect($remapping?->tables)->toEqual([
        new KeyRemappingTableData('users', 'id', KeyRemappingStrategy::NewUuid, [
            new KeyRemappingForeignKeyData('orders', 'user_id'),
            new KeyRemappingForeignKeyData('users', 'parent_id', selfReferential: true),
        ]),
    ]);
});

it('keeps the rules of a literal entry when a broader regex follows', function (): void {
    $users = new TableCloningConfigData('users', new TableRowConfigData('full', null, null), [columnRule('email', 'fake')]);
    $catchAll = tableEntry('/^.*$/', 'first', 100);

    $resolved = (new TableConfigResolver)->resolve(configWith([$users, $catchAll]), schemaWith(['users', 'orders']));

    expect($resolved->getTable('users')?->columns)->toEqual([columnRule('email', 'fake')])
        ->and($resolved->getTable('users')?->rows)->toEqual($users->rows)
        ->and($resolved->getTable('orders')?->columns)->toBe([])
        ->and($resolved->getTable('orders')?->rows)->toEqual($catchAll->rows);
});

it('lets the column rules of a literal entry override those of a later regex', function (): void {
    $users = new TableCloningConfigData('users', new TableRowConfigData('full', null, null), [columnRule('email', 'hash')]);
    $catchAll = new TableCloningConfigData('/.*/', new TableRowConfigData('full', null, null), [columnRule('email', 'fake'), columnRule('iban', 'mask')]);

    $resolved = (new TableConfigResolver)->resolve(configWith([$users, $catchAll]), schemaWith(['users'], ['users' => ['email', 'iban']]));

    expect($resolved->getTable('users')?->columns)->toEqual([columnRule('email', 'hash'), columnRule('iban', 'mask')]);
});

it('merges column rules per column, a later entry overriding only the columns it names', function (): void {
    $catchAll = new TableCloningConfigData('/.*/', new TableRowConfigData('full', null, null), [
        columnRule('email', 'fake'),
        columnRule('iban', 'mask'),
        columnRule('phone', 'null'),
    ]);
    $users = new TableCloningConfigData('users', new TableRowConfigData('full', null, null), [
        columnRule('name', 'fake'),
        columnRule('email', 'hash'),
        columnRule('phone', 'keep'),
    ]);

    $resolved = (new TableConfigResolver)->resolve(configWith([$catchAll, $users]), schemaWith(['users'], ['users' => ['name', 'email', 'iban', 'phone']]));

    expect($resolved->getTable('users')?->columns)->toEqual([
        columnRule('email', 'hash'),
        columnRule('iban', 'mask'),
        columnRule('phone', 'keep'),
        columnRule('name', 'fake'),
    ]);
});

it('does not let a regex keep lift the column rule of another regex, whatever their order', function (array $order): void {
    $entries = [
        'crm' => new TableCloningConfigData('/^crm_/', new TableRowConfigData('full', null, null), [columnRule('email', 'fake'), columnRule('phone', 'keep')]),
        'catch-all' => new TableCloningConfigData('/.*/', new TableRowConfigData('full', null, null), [columnRule('email', 'keep'), columnRule('phone', 'mask'), columnRule('note', 'keep')]),
    ];

    $resolved = (new TableConfigResolver)->resolve(
        configWith(array_map(static fn (string $key): TableCloningConfigData => $entries[$key], $order)),
        schemaWith(['crm_contacts'], ['crm_contacts' => ['email', 'phone', 'note']]),
    );

    // A keep that tightens nothing still applies; an anonymizing rule replaces a keep.
    expect($resolved->getTable('crm_contacts')?->columns)->toEqualCanonicalizing([columnRule('email', 'fake'), columnRule('phone', 'mask'), columnRule('note', 'keep')]);
})->with([
    'catch-all last' => [['crm', 'catch-all']],
    'catch-all first' => [['catch-all', 'crm']],
]);

it('lets a literal keep lift the column rule of a regex', function (): void {
    $resolved = (new TableConfigResolver)->resolve(
        configWith([
            new TableCloningConfigData('crm_contacts', new TableRowConfigData('full', null, null), [columnRule('email', 'keep')]),
            new TableCloningConfigData('/^crm_/', new TableRowConfigData('full', null, null), [columnRule('email', 'fake')]),
        ]),
        schemaWith(['crm_contacts'], ['crm_contacts' => ['email']]),
    );

    expect($resolved->getTable('crm_contacts')?->columns)->toEqual([columnRule('email', 'keep')]);
});

it('lets the later of two anonymizing regex rules win', function (): void {
    $resolved = (new TableConfigResolver)->resolve(
        configWith([
            new TableCloningConfigData('/^crm_/', new TableRowConfigData('full', null, null), [columnRule('email', 'fake')]),
            new TableCloningConfigData('/.*/', new TableRowConfigData('full', null, null), [columnRule('email', 'hash')]),
        ]),
        schemaWith(['crm_contacts'], ['crm_contacts' => ['email']]),
    );

    expect($resolved->getTable('crm_contacts')?->columns)->toEqual([columnRule('email', 'hash')]);
});

it('applies the column rules of a regex key only to tables that have the column', function (): void {
    $catchAll = new TableCloningConfigData('/.*/', new TableRowConfigData('full', null, null), [columnRule('email', 'fake')]);
    $orders = new TableCloningConfigData('orders', new TableRowConfigData('full', null, null), [columnRule('legacy_note', 'null')]);

    $resolved = (new TableConfigResolver)->resolve(
        configWith([$catchAll, $orders]),
        schemaWith(['users', 'orders'], ['users' => ['id', 'email'], 'orders' => ['id']]),
    );

    // A literal key names its table on purpose, so its rules stay as written.
    expect($resolved->getTable('users')?->columns)->toEqual([columnRule('email', 'fake')])
        ->and($resolved->getTable('orders')?->columns)->toEqual([columnRule('legacy_note', 'null')]);
});

it('reports regex keys and skip entries that match no source table', function (): void {
    Log::spy();
    $unmatched = [];

    $resolved = (new TableConfigResolver)->resolve(
        configWith([tableEntry('/^applicaton_/'), tableEntry('ghost'), tableEntry('users')], skip: ['/^tmp_cahce/', 'Sesions', 'USERS']),
        schemaWith(['users']),
        function (string $reference, string $origin) use (&$unmatched): void {
            $unmatched[] = [$reference, $origin];
        },
    );

    // A literal table key that matches nothing stays a table, reported as not found by the run.
    expect($unmatched)->toBe([['/^applicaton_/', 'table key'], ['/^tmp_cahce/', 'skip entry'], ['Sesions', 'skip entry']])
        ->and(array_map(static fn (TableCloningConfigData $t): string => $t->tableName, $resolved->tables))->toBe(['ghost', 'users'])
        ->and($resolved->skipTables)->toBe(['Sesions', 'users']);
    Log::shouldHaveReceived('warning')->times(3);
    Log::shouldHaveReceived('warning')->with('table_reference_unmatched', ['reference' => '/^applicaton_/', 'origin' => 'table key']);
    Log::shouldHaveReceived('warning')->with('table_reference_unmatched', ['reference' => 'Sesions', 'origin' => 'skip entry']);
});

it('resolves table references like table keys and reports those that match nothing', function (): void {
    $unmatched = [];

    $names = (new TableConfigResolver)->resolveReferences(
        ['USERS', '/^logs_/', '/^tmp_/', 'ghost', 'logs_2025'],
        schemaWith(['users', 'logs_2025', 'logs_2026']),
        '--skip-tables',
        function (string $reference, string $origin) use (&$unmatched): void {
            $unmatched[] = [$reference, $origin];
        },
    );

    expect($names)->toBe(['users', 'logs_2025', 'logs_2026', 'ghost'])
        ->and($unmatched)->toBe([['/^tmp_/', '--skip-tables'], ['ghost', '--skip-tables']]);
});

it('keeps the tables in YAML order, not in schema order', function (): void {
    $config = configWith([
        tableEntry('users'),
        tableEntry('ghost'),
        tableEntry('/^logs_/'),
        tableEntry('audit'),
    ]);

    $resolved = (new TableConfigResolver)->resolve($config, schemaWith(['audit', 'logs_b', 'logs_a', 'users']));

    expect(array_map(static fn (TableCloningConfigData $t): string => $t->tableName, $resolved->tables))
        ->toBe(['users', 'ghost', 'logs_b', 'logs_a', 'audit']);
});

it('applies an exact literal key only to its own table when a case-insensitive regex matches both', function (): void {
    $config = configWith([
        tableEntry('/^users$/i', 'full'),
        tableEntry('users', 'first', 10),
    ]);

    $resolved = (new TableConfigResolver)->resolve($config, schemaWith(['Users', 'users']));

    expect(array_map(static fn (TableCloningConfigData $t): array => [$t->tableName, $t->rows->strategy], $resolved->tables))
        ->toBe([['Users', 'full'], ['users', 'first']]);
});

it('fails on a regex that errors while matching instead of treating it as no match', function (array $tables, array $skip): void {
    expect(fn (): CloningConfigData => (new TableConfigResolver)->resolve(configWith($tables, $skip), schemaWith(["\xff"])))
        ->toThrow(RuntimeException::class, "Table pattern '/^a/u' failed on table '\xff': Malformed UTF-8 characters, possibly incorrectly encoded");
})->with([
    'table key' => [[tableEntry('/^a/u')], []],
    'skip entry' => [[], ['/^a/u']],
]);
