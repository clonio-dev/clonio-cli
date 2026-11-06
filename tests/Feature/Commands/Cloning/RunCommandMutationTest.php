<?php

declare(strict_types=1);

use App\Commands\Cloning\RunCommand;
use App\Data\Cloning\KeyRemappingConfigData;
use App\Data\Cloning\RunResultData;
use App\Data\Cloning\StatsLoopData;
use App\Data\Cloning\StatsTableTransferData;
use App\Data\Cloning\TableRunPhase;
use App\Data\Cloning\TableRunResultData;
use App\Data\Cloning\TableRunStatus;
use App\Data\ConnectionData;
use App\Data\Schema\ColumnSchemaData;
use App\Data\Schema\DatabaseSchemaData;
use App\Data\Schema\TableSchemaData;
use App\Enums\DatabaseConnectionType;
use App\Exceptions\KeyRemappingExhaustedException;
use App\Services\Cloning\CloningRunOrchestrator;
use App\Services\Cloning\KeyRemappingService;
use App\Services\Cloning\NullCloningRunObserver;
use App\Services\Cloning\SkippedRow;
use App\Services\Config\ConfigService;
use App\Services\Database\DatabaseConnectionService;
use App\Services\Output\Run\DotRunObserver;
use App\Services\Output\Run\ProgressRunObserver;
use App\Services\Output\Run\StepRunObserver;
use App\Services\Schema\SchemaInspector;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Monolog\Handler\StreamHandler;
use Monolog\Level;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Output\StreamOutput;
use Tests\Feature\Commands\Support\FilePermissionOverride;

require_once __DIR__.'/../Support/CommandFunctionOverrides.php';

// ─── Helpers (prefixed g1_ to stay unique across the suite) ──────────────────

function g1_conn(string $name, DatabaseConnectionType $type = DatabaseConnectionType::Mysql, bool $production = false, ?DatabaseConnectionType $dialect = null): ConnectionData
{
    return new ConnectionData(
        name: $name,
        type: $type,
        host: $type === DatabaseConnectionType::Dump ? null : 'db.example.io',
        port: $type === DatabaseConnectionType::Dump ? null : 3306,
        database: $type === DatabaseConnectionType::Dump ? null : 'appdb',
        schema: null,
        username: $type === DatabaseConnectionType::Dump ? null : 'root',
        password: 'secret',
        isProduction: $production,
        dialect: $dialect,
    );
}

/**
 * @param  list<TableSchemaData>  $tables
 */
function g1_schema(array $tables = []): DatabaseSchemaData
{
    return new DatabaseSchemaData(databaseName: 'appdb', tables: $tables);
}

/**
 * @param  array<string, array{0: string, 1?: bool}>  $columns  name => [type, nullable]
 */
function g1_table(string $name, array $columns = ['id' => ['int']]): TableSchemaData
{
    $cols = [];
    foreach ($columns as $col => $def) {
        $cols[] = new ColumnSchemaData(name: $col, type: $def[0], nullable: $def[1] ?? false, default: null, isPrimary: $col === 'id');
    }

    return new TableSchemaData(name: $name, columns: $cols, foreignKeys: []);
}

function g1_yaml(string $tables = "  users:\n    rows:\n      strategy: full\n", string $extraOptions = ''): string
{
    return "version: \"1\"\nconnection: production-db\noptions:\n  chunk_size: 1000\n  enforce_column_types: false\n  drop_unknown_tables: false\n  disable_foreign_key_checks: true\n  faker_locale: en_US\n".$extraOptions."tables:\n".$tables;
}

/**
 * @param  list<TableRunResultData>  $tables
 */
function g1_result(array $tables = [], bool $success = true, int $rows = 0, float $duration = 0.0): RunResultData
{
    return new RunResultData(
        success: $success,
        tables: $tables,
        totalRows: $rows,
        skippedRows: 0,
        durationSeconds: $duration,
        failureReason: null,
    );
}

function g1_tr(string $name, TableRunStatus $status = TableRunStatus::Transferred, array $notes = []): TableRunResultData
{
    return new TableRunResultData($name, $status, 0, 0, 0.0, null, $notes);
}

/**
 * Bind service mocks for a cloning:run invocation.
 *
 * @param  array<string, ConnectionData>|null  $connections
 * @param  (Closure(ConnectionData): DatabaseSchemaData)|null  $inspect
 * @param  (Closure(array<int, mixed>): RunResultData)|null  $run  receives the positional orchestrator args
 * @param  array<string, mixed>  $load
 */
function g1_bind(?array $connections = null, ?Closure $inspect = null, ?Closure $run = null, array $load = []): void
{
    $connections ??= [
        'production-db' => g1_conn('production-db', production: true),
        'staging' => g1_conn('staging'),
    ];

    $config = Mockery::mock(ConfigService::class);
    $config->shouldReceive('getConnection')->andReturnUsing(fn (string $name): ?ConnectionData => $connections[$name] ?? null);
    $config->shouldReceive('getConnections')->andReturn($connections);
    $config->shouldReceive('load')->andReturn($load);
    app()->instance(ConfigService::class, $config);

    $connector = Mockery::mock(DatabaseConnectionService::class);
    $connector->shouldReceive('open')->andReturn('test_conn');
    app()->instance(DatabaseConnectionService::class, $connector);

    $inspector = Mockery::mock(SchemaInspector::class);
    $inspector->shouldReceive('inspect')->andReturnUsing($inspect ?? fn (ConnectionData $c): DatabaseSchemaData => g1_schema([g1_table('users')]));
    app()->instance(SchemaInspector::class, $inspector);

    $orchestrator = Mockery::mock(CloningRunOrchestrator::class);
    $orchestrator->shouldReceive('run')->andReturnUsing(fn (...$args): RunResultData => $run instanceof Closure ? $run($args) : g1_result());
    app()->instance(CloningRunOrchestrator::class, $orchestrator);
}

/**
 * @param  array<string, mixed>  $params
 * @return array{0: int, 1: string}
 */
function g1_call(array $params): array
{
    $code = test()->withoutMockingConsoleOutput()->artisan('cloning:run', $params);

    return [$code, Artisan::output()];
}

beforeEach(function (): void {
    Storage::fake('local');
});

function g1_summary(int $transferred = 0, int $total = 0, int $rows = 0, string $duration = '0s'): string
{
    return sprintf("\n  Tables: %d/%d  Rows: %d  Duration: %s\n\n", $transferred, $total, $rows, $duration);
}

function g1_remap_yaml(string $extraOptions = '', string $extraTables = ''): string
{
    return g1_yaml(
        "  users:\n    rows:\n      strategy: full\n    columns:\n      id:\n        strategy: remapping\n        arguments:\n          - use: random_integer\n          - foreign_keys: []\n".$extraTables,
        $extraOptions,
    );
}

/**
 * Bind a KeyRemappingService mock; returns nothing, captures generateMappings args.
 *
 * @param  array<string, int>  $counts
 */
function g1_bind_remap(array $counts = ['users' => 1234], ?array &$captured = null): void
{
    $remapping = Mockery::mock(KeyRemappingService::class);
    $remapping->shouldReceive('generateMappings')->andReturnUsing(function (...$args) use (&$captured, $counts): array {
        $captured = $args;

        return $counts;
    });
    $remapping->shouldReceive('cleanup')->once()->andReturnNull();
    app()->instance(KeyRemappingService::class, $remapping);
}

// ─── Phase 1: verbosity, file argument, validation ───────────────────────────

it('maps verbosity flags to the stderr log level', function (array $flags, string $level): void {
    config(['logging.channels.stderr.level' => 'unset']);
    Storage::disk('local')->put('t.yaml', g1_yaml());
    g1_bind();

    [$code] = g1_call(['file' => 't.yaml', '--target' => 'staging', ...$flags]);

    expect($code)->toBe(0)
        ->and(config('logging.channels.stderr.level'))->toBe($level);
})->with([
    'normal' => [[], 'warning'],
    'verbose' => [['-v' => true], 'info'],
    'very verbose' => [['-vv' => true], 'debug'],
    'ci' => [['--ci' => true], 'error'],
    'ci + verbose' => [['--ci' => true, '-v' => true], 'info'],
]);

it('casts a non-boolean --ci value to a boolean', function (): void {
    Storage::disk('local')->put('t.yaml', g1_yaml());
    g1_bind();

    [$code, $out] = g1_call(['file' => 't.yaml', '--target' => 'staging', '--ci' => 1]);

    expect($code)->toBe(0)->and($out)->toBe('');
});

it('casts a non-string file argument to a string path', function (): void {
    Storage::disk('local')->put('123', g1_yaml());
    g1_bind();

    [$code, $out] = g1_call(['file' => 123, '--target' => 'staging', '--ci' => true]);

    expect($code)->toBe(0)->and($out)->toBe('');
});

it('prints every validator error on its own line when the YAML parses but is invalid', function (): void {
    Storage::disk('local')->put('t.yaml', str_replace(
        ['version: "1"', 'connection: production-db'],
        ['version: "2"', 'connection: Bad_Name'],
        g1_yaml(),
    ));
    g1_bind();

    [$code, $out] = g1_call(['file' => 't.yaml', '--target' => 'staging']);

    expect($code)->toBe(4)
        ->and($out)->toBe("Field 'version' must be \"1\"\nField 'connection' must match pattern ^[a-z0-9_-]+$\n");
});

// ─── --skip-tables / --only-tables / --audit-channel parsing ─────────────────

it('accepts an empty --skip-tables together with --only-tables', function (): void {
    Storage::disk('local')->put('t.yaml', g1_yaml());
    $args = null;
    g1_bind(run: function (array $a) use (&$args) {
        $args = $a;

        return g1_result();
    });

    [$code] = g1_call(['file' => 't.yaml', '--target' => 'staging', '--ci' => true, '--skip-tables' => '', '--only-tables' => ' users , ,orders']);

    expect($code)->toBe(0)
        ->and($args[5]->skipTables)->toBe([])
        ->and($args[5]->onlyTables)->toBe(['users', 'orders']);
});

it('accepts an empty --only-tables together with --skip-tables', function (): void {
    Storage::disk('local')->put('t.yaml', g1_yaml());
    $args = null;
    g1_bind(run: function (array $a) use (&$args) {
        $args = $a;

        return g1_result();
    });

    [$code] = g1_call(['file' => 't.yaml', '--target' => 'staging', '--ci' => true, '--only-tables' => '', '--skip-tables' => ' users , ,orders']);

    expect($code)->toBe(0)
        ->and($args[5]->skipTables)->toBe(['users', 'orders'])
        ->and($args[5]->onlyTables)->toBe([]);
});

it('rejects --skip-tables combined with --only-tables before running', function (): void {
    Storage::disk('local')->put('t.yaml', g1_yaml());
    $called = false;
    g1_bind(run: function (array $a) use (&$called) {
        $called = true;

        return g1_result();
    });

    [$code, $out] = g1_call(['file' => 't.yaml', '--target' => 'staging', '--skip-tables' => 'a', '--only-tables' => 'b']);

    expect($code)->toBe(4)
        ->and($out)->toBe("--skip-tables and --only-tables are mutually exclusive.\n")
        ->and($called)->toBeFalse();
});

it('casts non-string table option values to strings', function (string $option, string $property): void {
    Storage::disk('local')->put('t.yaml', g1_yaml("  users:\n    rows:\n      strategy: full\n  \"7\":\n    rows:\n      strategy: full\n"));
    $args = null;
    g1_bind(run: function (array $a) use (&$args) {
        $args = $a;

        return g1_result();
    });

    [$code] = g1_call(['file' => 't.yaml', '--target' => 'staging', '--ci' => true, $option => 7]);

    expect($code)->toBe(0)->and($args[5]->{$property})->toBe(['7']);
})->with([
    'skip' => ['--skip-tables', 'skipTables'],
    'only' => ['--only-tables', 'onlyTables'],
]);

it('warns about each filter name that matches no configured table and keeps running', function (string $flag, string $value): void {
    Storage::disk('local')->put('t.yaml', g1_yaml("  users:\n    rows:\n      strategy: full\n")."skip:\n  - not_configured\n");
    $ran = false;
    g1_bind(run: function () use (&$ran): RunResultData {
        $ran = true;

        return g1_result();
    });
    Log::spy();

    [$code, $out] = g1_call(['file' => 't.yaml', '--target' => 'staging', '--ci' => true, $flag => $value]);

    // Names resolve before the check, so USERS matches users; YAML skip: entries are not checked.
    expect($code)->toBe(0)->and($ran)->toBeTrue()->and($out)->toBe(
        sprintf("  Warning: %s name \"usrs\" matches no table in the config\n", $flag)
        .sprintf("  Warning: %s name \"ordrs\" matches no table in the config\n", $flag)
    );
    Log::shouldHaveReceived('warning')->with('table_filter_unmatched', ['flag' => $flag, 'table' => 'usrs'])->once();
    Log::shouldHaveReceived('warning')->with('table_filter_unmatched', ['flag' => $flag, 'table' => 'ordrs'])->once();
})->with([
    'only' => ['--only-tables', 'USERS,usrs,ordrs'],
    'skip' => ['--skip-tables', 'usrs,USERS,ordrs'],
]);

it('rejects --only-tables when no name matches a configured table', function (): void {
    Storage::disk('local')->put('t.yaml', g1_yaml());
    g1_bind(run: fn (): never => throw new RuntimeException('must not run'));

    [$code, $out] = g1_call(['file' => 't.yaml', '--target' => 'staging', '--ci' => true, '--only-tables' => 'usrs']);

    expect($code)->toBe(4)
        ->and($out)->toContain('  Warning: --only-tables name "usrs" matches no table in the config')
        ->and($out)->toContain('--only-tables matches no table in the config; nothing would be cloned.');
});

it('runs when --skip-tables matches no configured table at all', function (): void {
    Storage::disk('local')->put('t.yaml', g1_yaml());
    g1_bind();

    [$code] = g1_call(['file' => 't.yaml', '--target' => 'staging', '--ci' => true, '--skip-tables' => 'usrs']);

    expect($code)->toBe(0);
});

it('merges YAML skip entries with --skip-tables without duplicates', function (): void {
    Storage::disk('local')->put('t.yaml', g1_yaml()."skip:\n  - orders\n  - logs\n");
    $args = null;
    g1_bind(run: function (array $a) use (&$args) {
        $args = $a;

        return g1_result();
    });

    [$code] = g1_call(['file' => 't.yaml', '--target' => 'staging', '--ci' => true, '--skip-tables' => 'orders']);

    expect($code)->toBe(0)->and($args[5]->skipTables)->toBe(['orders', 'logs']);
});

it('rejects a name option whose value holds no name', function (string $option, string $value): void {
    Storage::disk('local')->put('t.yaml', g1_yaml());
    $called = false;
    g1_bind(run: function (array $a) use (&$called) {
        $called = true;

        return g1_result();
    });

    [$code, $out] = g1_call(['file' => 't.yaml', '--target' => 'staging', '--ci' => true, $option => $value]);

    expect($code)->toBe(4)
        ->and($out)->toBe($option." needs at least one name.\n")
        ->and($called)->toBeFalse();
})->with([
    'skip blank' => ['--skip-tables', ' , '],
    'skip comma' => ['--skip-tables', ','],
    'only blank' => ['--only-tables', ' , '],
    'only comma' => ['--only-tables', ','],
    'audit blank' => ['--audit-channel', ' , '],
    'audit comma' => ['--audit-channel', ','],
]);

it('keeps a table named 0', function (string $option, string $property): void {
    Storage::disk('local')->put('t.yaml', g1_yaml());
    $args = null;
    g1_bind(run: function (array $a) use (&$args) {
        $args = $a;

        return g1_result();
    });

    [$code] = g1_call(['file' => 't.yaml', '--target' => 'staging', '--ci' => true, $option => '0, users']);

    expect($code)->toBe(0)->and($args[5]->{$property})->toBe(['0', 'users']);
})->with([
    'skip' => ['--skip-tables', 'skipTables'],
    'only' => ['--only-tables', 'onlyTables'],
]);

// Issue #241: table names resolve like the database resolves them.
it('resolves flag names that differ only in case to the source table', function (string $option, string $property): void {
    Storage::disk('local')->put('t.yaml', g1_yaml());
    $args = null;
    g1_bind(
        inspect: fn (ConnectionData $c): DatabaseSchemaData => g1_schema([g1_table('users'), g1_table('Orders')]),
        run: function (array $a) use (&$args) {
            $args = $a;

            return g1_result();
        },
    );

    [$code] = g1_call(['file' => 't.yaml', '--target' => 'staging', '--ci' => true, $option => 'USERS,orders,ghosts']);

    expect($code)->toBe(0)->and($args[5]->{$property})->toBe(['users', 'Orders', 'ghosts']);
})->with([
    'skip' => ['--skip-tables', 'skipTables'],
    'only' => ['--only-tables', 'onlyTables'],
]);

it('resolves tables keys and skip entries that differ only in case to the source table', function (): void {
    Storage::disk('local')->put('t.yaml', g1_yaml("  Users:\n    rows:\n      strategy: full\n")."skip:\n  - AUDIT_LOGS\n");
    $args = null;
    g1_bind(
        inspect: fn (ConnectionData $c): DatabaseSchemaData => g1_schema([g1_table('users'), g1_table('audit_logs')]),
        run: function (array $a) use (&$args) {
            $args = $a;

            return g1_result();
        },
    );

    [$code] = g1_call(['file' => 't.yaml', '--target' => 'staging', '--ci' => true]);

    expect($code)->toBe(0)
        ->and($args[0]->tables[0]->tableName)->toBe('users')
        ->and($args[0]->skipTables)->toBe(['audit_logs'])
        ->and($args[5]->skipTables)->toBe(['audit_logs']);
});

// Issue #246: key_remapping references resolve like tables: keys.
it('resolves key_remapping tables that differ only in case to the source table', function (string $yaml): void {
    Storage::disk('local')->put('t.yaml', $yaml);
    $captured = null;
    g1_bind_remap(captured: $captured);
    $args = null;
    g1_bind(run: function (array $a) use (&$args) {
        $args = $a;

        return g1_result();
    });

    [$code] = g1_call(['file' => 't.yaml', '--target' => 'staging', '--ci' => true]);

    expect($code)->toBe(0)
        ->and($captured[0]->getTable('users'))->not->toBeNull()
        ->and($args[0]->keyRemapping->getTable('users'))->not->toBeNull();
})->with([
    'inline remapping column' => [g1_yaml("  Users:\n    rows:\n      strategy: full\n    columns:\n      id:\n        strategy: remapping\n        arguments:\n          - use: random_integer\n          - foreign_keys: []\n")],
    'key_remapping section' => [g1_yaml("  users:\n    rows:\n      strategy: full\n")."key_remapping:\n  tables:\n    - table: Users\n      primary_key: id\n      strategy: new_uuid\n"],
]);

// Issue #254: a remapping that cannot work is a validation error, not a crash.
it('rejects a key remapping that does not fit the source column before generating mappings', function (array $params): void {
    Storage::disk('local')->put('t.yaml', g1_remap_yaml());
    $remapping = Mockery::mock(KeyRemappingService::class);
    $remapping->shouldNotReceive('generateMappings');
    app()->instance(KeyRemappingService::class, $remapping);
    g1_bind(
        inspect: fn (ConnectionData $c): DatabaseSchemaData => g1_schema([g1_table('users', ['id' => ['varchar']])]),
        run: fn (array $a): never => throw new RuntimeException('must not run'),
    );

    [$code, $out] = g1_call(['file' => 't.yaml', '--target' => 'staging', '--ci' => true, ...$params]);

    expect($code)->toBe(4)->and($out)->toContain(
        'Cannot remap users.id: random_integer needs an integer column, but "id" is varchar. Use new_uuid for UUID or text keys.',
    );
})->with([
    'run' => [[]],
    'dry run' => [['--dry-run' => true]],
]);

it('does not check key remapping in a dry run when remapping is disabled', function (): void {
    Storage::disk('local')->put('t.yaml', g1_remap_yaml("  remap_keys: false\n"));
    g1_bind(inspect: fn (ConnectionData $c): DatabaseSchemaData => g1_schema([g1_table('users', ['id' => ['varchar']])]));
    g1_db([[G1_MYSQL_ESTIMATE, ['users'], (object) ['c' => 3]]], 3);

    [$code, $out] = g1_call(['file' => 't.yaml', '--target' => 'staging', '--dry-run' => true]);

    expect($code)->toBe(0)->and($out)->not->toContain('Cannot remap');
});

it('rejects key_remapping entries that differ only in case', function (): void {
    Storage::disk('local')->put('t.yaml', g1_yaml("  users:\n    rows:\n      strategy: full\n")
        ."key_remapping:\n  tables:\n    - table: users\n      primary_key: id\n      strategy: new_uuid\n    - table: Users\n      primary_key: id\n      strategy: random_integer\n");
    g1_bind(run: fn (array $a): never => throw new RuntimeException('must not run'));

    [$code, $out] = g1_call(['file' => 't.yaml', '--target' => 'staging', '--ci' => true]);

    expect($code)->toBe(4)->and($out)->toContain('Two key_remapping entries refer to table "users". Keep one entry.');
});

it('rejects a table name that matches several source tables case-insensitively', function (array $params, string $yaml): void {
    Storage::disk('local')->put('t.yaml', $yaml);
    g1_bind(
        inspect: fn (ConnectionData $c): DatabaseSchemaData => g1_schema([g1_table('users'), g1_table('Users'), g1_table('orders')]),
        run: fn (array $a): never => throw new RuntimeException('must not run'),
    );

    [$code, $out] = g1_call(['file' => 't.yaml', '--target' => 'staging', '--ci' => true, ...$params]);

    expect($code)->toBe(4)
        ->and($out)->toContain('Table name "USERS" is ambiguous: it matches users, Users. Use the exact name.');
})->with([
    '--skip-tables' => [['--skip-tables' => 'USERS'], g1_yaml("  orders:\n    rows:\n      strategy: full\n")],
    '--only-tables' => [['--only-tables' => 'USERS'], g1_yaml("  orders:\n    rows:\n      strategy: full\n")],
    'tables key' => [[], g1_yaml("  USERS:\n    rows:\n      strategy: full\n")],
    'dry-run tables key' => [['--dry-run' => true], g1_yaml("  USERS:\n    rows:\n      strategy: full\n")],
]);

it('counts a dry-run table whose key differs only in case from the source table', function (): void {
    Storage::disk('local')->put('t.yaml', g1_yaml("  Users:\n    rows:\n      strategy: full\n"));
    g1_bind(inspect: fn (ConnectionData $c): DatabaseSchemaData => g1_schema([g1_table('users')]));
    g1_db([[G1_MYSQL_ESTIMATE, ['users'], (object) ['c' => 12]]], 3);

    [$code, $out] = g1_call(['file' => 't.yaml', '--target' => 'staging', '--dry-run' => true]);

    expect($code)->toBe(0)->and($out)->toContain(g1_row('users', '~12', 'full', '—'));
});

// Issue #164 / PR #168: tables: keys and skip: entries written as /pattern/flags.
function g1_pattern_schema(): DatabaseSchemaData
{
    return g1_schema([g1_table('users'), g1_table('logs_2024', ['id' => ['int'], 'ip' => ['varchar']]), g1_table('logs_2025')]);
}

it('expands pattern tables: keys and skip: entries to the matching source tables', function (): void {
    Storage::disk('local')->put('t.yaml', g1_yaml("  users:\n    rows:\n      strategy: full\n  '/^logs_/':\n    rows:\n      strategy: first\n      limit: 5\n    columns:\n      ip:\n        strategy: \"null\"\n")."skip:\n  - '/2025$/'\n");
    $args = null;
    g1_bind(inspect: fn (ConnectionData $c): DatabaseSchemaData => g1_pattern_schema(), run: function (array $a) use (&$args) {
        $args = $a;

        return g1_result();
    });
    Log::spy();

    [$code, $out] = g1_call(['file' => 't.yaml', '--target' => 'staging', '--ci' => true, '--skip-tables' => 'users']);

    expect($code)->toBe(0)
        ->and($out)->toBe('')
        ->and(array_map(fn ($t): string => $t->tableName, $args[0]->tables))->toBe(['users', 'logs_2024', 'logs_2025'])
        ->and($args[0]->tables[1]->rows->limit)->toBe(5)
        ->and(count($args[0]->tables[1]->columns))->toBe(1)
        ->and($args[0]->tables[2]->columns)->toBe([])
        ->and($args[5]->skipTables)->toBe(['users', 'logs_2025']);
    Log::shouldNotHaveReceived('warning', ['table_pattern_unmatched', Mockery::any()]);
});

it('warns about each pattern that matches no table and keeps running', function (array $params): void {
    Storage::disk('local')->put('t.yaml', g1_yaml("  users:\n    rows:\n      strategy: schema\n  '/^ghosts_/':\n    rows:\n      strategy: full\n")."skip:\n  - '/^logs_/'\n");
    g1_bind(inspect: fn (ConnectionData $c): DatabaseSchemaData => g1_pattern_schema());
    Log::spy();

    [$code, $out] = g1_call(['file' => 't.yaml', '--target' => 'staging', '--ci' => true, ...$params]);

    expect($code)->toBe(0)->and($out)->toStartWith(
        "  Warning: table pattern \"/^ghosts_/\" matches no table\n"
        ."  Warning: table pattern \"/^logs_/\" matches no table\n"
    );
    Log::shouldHaveReceived('warning')->with('table_pattern_unmatched', ['pattern' => '/^ghosts_/'])->once();
    Log::shouldHaveReceived('warning')->with('table_pattern_unmatched', ['pattern' => '/^logs_/'])->once();
})->with([
    'run' => [[]],
    'dry run' => [['--dry-run' => true]],
]);

it('rejects a table that matches more than one tables: entry', function (array $params): void {
    Storage::disk('local')->put('t.yaml', g1_yaml("  '/^logs_/':\n    rows:\n      strategy: full\n  logs_2024:\n    rows:\n      strategy: schema\n"));
    g1_bind(inspect: fn (ConnectionData $c): DatabaseSchemaData => g1_pattern_schema(), run: fn (array $a): never => throw new RuntimeException('must not run'));

    [$code, $out] = g1_call(['file' => 't.yaml', '--target' => 'staging', '--ci' => true, ...$params]);

    expect($code)->toBe(4)->and($out)->toBe(
        "Table \"logs_2024\" matches more than one tables: entry: \"/^logs_/\" and \"logs_2024\". Each table may match only one entry.\n"
    );
})->with([
    'run' => [[]],
    'dry run' => [['--dry-run' => true]],
]);

it('lists the tables a pattern key matches in a dry run', function (): void {
    Storage::disk('local')->put('t.yaml', g1_yaml("  '/^logs_/':\n    rows:\n      strategy: schema\n"));
    g1_bind(inspect: fn (ConnectionData $c): DatabaseSchemaData => g1_pattern_schema());

    [$code, $out] = g1_call(['file' => 't.yaml', '--target' => 'staging', '--dry-run' => true]);

    expect($code)->toBe(0)
        ->and($out)->toContain(g1_row('logs_2024', '0', 'schema', '—'))
        ->and($out)->toContain(g1_row('logs_2025', '0', 'schema', '—'))
        ->and($out)->not->toContain('/^logs_/');
});

it('rejects a pattern in a table name flag', function (string $option): void {
    Storage::disk('local')->put('t.yaml', g1_yaml());
    g1_bind(run: fn (array $a): never => throw new RuntimeException('must not run'));

    // The comma splits the pattern, so the flag names the part that starts with a slash.
    [$code, $out] = g1_call(['file' => 't.yaml', '--target' => 'staging', '--ci' => true, $option => 'users,/^logs_(a,b)/']);

    expect($code)->toBe(4)->and($out)->toBe(
        $option.' takes table names, not patterns ("/^logs_(a"). Write table patterns in cloning.yaml.'."\n"
    );
})->with(['--skip-tables', '--only-tables']);

// ─── Option overrides ─────────────────────────────────────────────────────────

it('applies a single CLI override on top of the YAML options', function (string $flag, bool $yamlValue, string $field): void {
    $v = $yamlValue ? 'true' : 'false';
    $yaml = "version: \"1\"\nconnection: production-db\noptions:\n  chunk_size: 500\n  enforce_column_types: {$v}\n  drop_unknown_tables: {$v}\n  drop_extra_columns: {$v}\n  disable_foreign_key_checks: {$v}\n  faker_locale: de_DE\n  remap_keys: {$v}\n  recreate_tables: {$v}\ntables:\n  users:\n    rows:\n      strategy: full\n";
    Storage::disk('local')->put('t.yaml', $yaml);
    $args = null;
    g1_bind(run: function (array $a) use (&$args) {
        $args = $a;

        return g1_result();
    });

    [$code] = g1_call(['file' => 't.yaml', '--target' => 'staging', '--ci' => true, $flag => true]);

    $expected = [
        'chunkSize' => 500,
        'enforceColumnTypes' => $yamlValue,
        'dropUnknownTables' => $yamlValue,
        'dropExtraColumns' => $yamlValue,
        'disableForeignKeyChecks' => $yamlValue,
        'fakerLocale' => 'de_DE',
        'remapKeys' => $yamlValue,
        'recreateTables' => $yamlValue,
    ];
    $expected[$field] = ! $yamlValue;

    expect($code)->toBe(0)
        ->and(get_object_vars($args[0]->options))->toBe($expected)
        ->and($args[0]->connectionName)->toBe('production-db')
        ->and($args[0]->tables[0]->tableName)->toBe('users');
})->with([
    ['--enforce-column-types', false, 'enforceColumnTypes'],
    ['--no-enforce-column-types', true, 'enforceColumnTypes'],
    ['--drop-unknown-tables', false, 'dropUnknownTables'],
    ['--no-drop-unknown-tables', true, 'dropUnknownTables'],
    ['--drop-extra-columns', false, 'dropExtraColumns'],
    ['--no-drop-extra-columns', true, 'dropExtraColumns'],
    ['--recreate-tables', false, 'recreateTables'],
    ['--no-recreate-tables', true, 'recreateTables'],
    ['--disable-fk-checks', false, 'disableForeignKeyChecks'],
    ['--no-disable-fk-checks', true, 'disableForeignKeyChecks'],
    ['--remap-keys', false, 'remapKeys'],
    ['--no-remap-keys', true, 'remapKeys'],
    ['--skip-remapping-keys', true, 'remapKeys'],
]);

it('keeps YAML options when no override flag is given', function (bool $yamlValue): void {
    $v = $yamlValue ? 'true' : 'false';
    $yaml = "version: \"1\"\nconnection: production-db\noptions:\n  chunk_size: 500\n  enforce_column_types: {$v}\n  drop_unknown_tables: {$v}\n  drop_extra_columns: {$v}\n  disable_foreign_key_checks: {$v}\n  faker_locale: de_DE\n  remap_keys: {$v}\n  recreate_tables: {$v}\ntables:\n  users:\n    rows:\n      strategy: full\n";
    Storage::disk('local')->put('t.yaml', $yaml);
    $args = null;
    g1_bind(run: function (array $a) use (&$args) {
        $args = $a;

        return g1_result();
    });

    [$code] = g1_call(['file' => 't.yaml', '--target' => 'staging', '--ci' => true]);

    expect($code)->toBe(0)
        ->and(get_object_vars($args[0]->options))->toBe([
            'chunkSize' => 500,
            'enforceColumnTypes' => $yamlValue,
            'dropUnknownTables' => $yamlValue,
            'dropExtraColumns' => $yamlValue,
            'disableForeignKeyChecks' => $yamlValue,
            'fakerLocale' => 'de_DE',
            'remapKeys' => $yamlValue,
            'recreateTables' => $yamlValue,
        ]);
})->with([true, false]);

// ─── Target resolution ────────────────────────────────────────────────────────

it('treats an empty --target as missing in CI mode', function (): void {
    Storage::disk('local')->put('t.yaml', g1_yaml());
    g1_bind();

    [$code, $out] = g1_call(['file' => 't.yaml', '--target' => '', '--ci' => true]);

    expect($code)->toBe(4)->and($out)->toBe("Target required in CI mode (use --target)\n");
});

it('defaults to the first non-production connection when not interactive', function (): void {
    Storage::disk('local')->put('t.yaml', g1_yaml());
    $args = null;
    g1_bind(
        connections: [
            'production-db' => g1_conn('production-db', production: true),
            'qa' => g1_conn('qa', production: true),
            'staging' => g1_conn('staging'),
            'dev' => g1_conn('dev'),
        ],
        run: function (array $a) use (&$args) {
            $args = $a;

            return g1_result();
        },
    );

    [$code] = g1_call(['file' => 't.yaml', '--no-interaction' => true]);

    expect($code)->toBe(0)->and($args[2]->name)->toBe('staging');
});

it('uses the interactively chosen target connection', function (): void {
    Storage::disk('local')->put('t.yaml', g1_yaml());
    $args = null;
    g1_bind(
        connections: [
            'production-db' => g1_conn('production-db', production: true),
            'staging' => g1_conn('staging'),
            'dev' => g1_conn('dev'),
        ],
        run: function (array $a) use (&$args) {
            $args = $a;

            return g1_result();
        },
    );

    $this->artisan('cloning:run', ['file' => 't.yaml', '--ci' => false])
        ->expectsChoice('Select a target connection', 'dev', ['staging', 'dev'])
        ->assertExitCode(0);

    expect($args[2]->name)->toBe('dev');
});

// ─── Dump target checks ───────────────────────────────────────────────────────

it('lists every valid dialect when a dump connection has none', function (): void {
    Storage::disk('local')->put('t.yaml', g1_yaml());
    g1_bind(connections: [
        'production-db' => g1_conn('production-db', production: true),
        'dump' => g1_conn('dump', DatabaseConnectionType::Dump),
    ]);

    [$code, $out] = g1_call(['file' => 't.yaml', '--target' => 'dump']);

    expect($code)->toBe(4)
        ->and($out)->toBe("Dump connection has no valid dialect. Valid dialects: mysql, mariadb, pgsql, sqlsrv, sqlite.\n");
});

it('rejects a dump target when the working directory is not writable', function (): void {
    Storage::disk('local')->put('t.yaml', g1_yaml());
    g1_bind(connections: [
        'production-db' => g1_conn('production-db', production: true),
        'dump' => g1_conn('dump', DatabaseConnectionType::Dump, dialect: DatabaseConnectionType::Mysql),
    ]);
    $previous = (string) getcwd();
    $dir = sys_get_temp_dir().'/g1_ro_'.uniqid();
    mkdir($dir);

    try {
        chdir($dir);
        $cwd = (string) getcwd();
        FilePermissionOverride::denyWrite($cwd);
        [$code, $out] = g1_call(['file' => 't.yaml', '--target' => 'dump']);
    } finally {
        FilePermissionOverride::reset();
        chdir($previous);
        rmdir($dir);
    }

    expect($code)->toBe(5)->and($out)->toBe('Working directory is not writable: '.$cwd."\n");
});

it('reports an unknown working directory when it was removed', function (): void {
    Storage::disk('local')->put('t.yaml', g1_yaml());
    g1_bind(connections: [
        'production-db' => g1_conn('production-db', production: true),
        'dump' => g1_conn('dump', DatabaseConnectionType::Dump, dialect: DatabaseConnectionType::Mysql),
    ]);
    $previous = (string) getcwd();
    $dir = sys_get_temp_dir().'/g1_gone_'.uniqid();
    mkdir($dir);

    try {
        chdir($dir);
        rmdir($dir);
        [$code, $out] = g1_call(['file' => 't.yaml', '--target' => 'dump']);
    } finally {
        chdir($previous);
    }

    expect(getcwd())->toBe($previous)
        ->and($code)->toBe(5)
        ->and($out)->toBe("Working directory is not writable: (unknown)\n");
});

it('connects only to the source for a dump target and reports finalise failures', function (): void {
    Storage::disk('local')->put('t.yaml', g1_yaml());
    g1_bind(connections: [
        'production-db' => g1_conn('production-db', production: true),
        'dump' => g1_conn('dump', DatabaseConnectionType::Dump, dialect: DatabaseConnectionType::Mysql),
    ]);

    [$code, $out] = g1_call(['file' => 't.yaml', '--target' => 'dump', '-v' => true]);

    expect($code)->toBe(1)->and($out)->toBe(
        "  Validating YAML ...\n  Validating YAML ✓\n"
        ."  Connecting to production-db ...\n  Connecting to production-db ✓\n"
        ."  Resolving table order ...\n  Resolving table order ✓\n"
        ."Failed to finalise dump archive: SqlDumpService::finish() called before begin().\n"
    );
});

// ─── Connections & logging ────────────────────────────────────────────────────

it('purges both opened connections after the connection check', function (): void {
    Storage::disk('local')->put('t.yaml', g1_yaml());
    g1_bind();
    $connector = Mockery::mock(DatabaseConnectionService::class);
    $connector->shouldReceive('open')->twice()->andReturn('g1_src', 'g1_tgt');
    app()->instance(DatabaseConnectionService::class, $connector);
    DB::shouldReceive('purge')->once()->with('g1_src');
    DB::shouldReceive('purge')->once()->with('g1_tgt');

    [$code] = g1_call(['file' => 't.yaml', '--target' => 'staging', '--ci' => true]);

    expect($code)->toBe(0);
});

it('logs run start and finish with source, target and totals', function (): void {
    Storage::disk('local')->put('t.yaml', g1_yaml());
    g1_bind(run: fn (): RunResultData => g1_result([g1_tr('users')], rows: 3));
    Log::spy();

    [$code] = g1_call(['file' => 't.yaml', '--target' => 'staging', '--ci' => true]);

    expect($code)->toBe(0);
    Log::shouldHaveReceived('info')->with('run_started', ['source' => 'production-db', 'target' => 'staging'])->once();
    Log::shouldHaveReceived('info')->with('run_finished', ['success' => true, 'rows' => 3])->once();
});

// ─── Key remapping ────────────────────────────────────────────────────────────

it('prints no remapping notice while remapping is active', function (): void {
    Storage::disk('local')->put('t.yaml', g1_remap_yaml());
    g1_bind();
    g1_bind_remap();
    Log::spy();

    [$code, $out] = g1_call(['file' => 't.yaml', '--target' => 'staging']);

    expect($code)->toBe(0)->and($out)->toBe(g1_summary());
    Log::shouldHaveReceived('info')->with('key_mapping_cleanup_completed', [])->once();
});

it('explains why key remapping is disabled', function (array $flags, string $yamlOption, string $reason): void {
    Storage::disk('local')->put('t.yaml', g1_remap_yaml($yamlOption));
    g1_bind();
    $remapping = Mockery::mock(KeyRemappingService::class);
    $remapping->shouldNotReceive('generateMappings');
    app()->instance(KeyRemappingService::class, $remapping);

    [$code, $out] = g1_call(['file' => 't.yaml', '--target' => 'staging', ...$flags]);

    expect($code)->toBe(0)->and($out)->toBe(
        "  Key remapping disabled ({$reason}) — primary/foreign key values are copied unchanged.\n".g1_summary()
    );
})->with([
    '--no-remap-keys' => [['--no-remap-keys' => 1], '', '--no-remap-keys'],
    '--skip-remapping-keys' => [['--skip-remapping-keys' => 1], '', '--skip-remapping-keys'],
    'yaml option' => [[], "  remap_keys: false\n", 'options.remap_keys: false'],
]);

it('prints no remapping notice in CI mode', function (): void {
    Storage::disk('local')->put('t.yaml', g1_remap_yaml());
    g1_bind();

    [$code, $out] = g1_call(['file' => 't.yaml', '--target' => 'staging', '--ci' => true, '--no-remap-keys' => true]);

    expect($code)->toBe(0)->and($out)->toBe('');
});

it('lifts the memory limit only when --no-memory-limit is given', function (): void {
    $original = (string) ini_get('memory_limit');
    Storage::disk('local')->put('t.yaml', g1_remap_yaml());

    try {
        ini_set('memory_limit', '1G');
        g1_bind();
        g1_bind_remap();
        g1_call(['file' => 't.yaml', '--target' => 'staging', '--ci' => true]);
        $without = ini_get('memory_limit');

        g1_bind();
        g1_bind_remap();
        g1_call(['file' => 't.yaml', '--target' => 'staging', '--ci' => true, '--no-memory-limit' => true]);
        $with = ini_get('memory_limit');
    } finally {
        ini_set('memory_limit', $original);
    }

    expect($without)->toBe('1G')->and($with)->toBe('-1');
});

it('generates key mappings only for tables that copy rows', function (): void {
    Storage::disk('local')->put('t.yaml', g1_remap_yaml(extraTables: "  logs:\n    rows:\n      strategy: schema\n  orders:\n    rows:\n      strategy: full\n"));
    g1_bind();
    $captured = null;
    g1_bind_remap(captured: $captured);

    [$code] = g1_call(['file' => 't.yaml', '--target' => 'staging', '--ci' => true]);

    expect($code)->toBe(0)
        ->and($captured[0])->toBeInstanceOf(KeyRemappingConfigData::class)
        ->and($captured[1]->name)->toBe('production-db')
        ->and($captured[3])->toBe(['users', 'orders']);
});

it('lists generated key mapping counts in verbose mode', function (): void {
    Storage::disk('local')->put('t.yaml', g1_remap_yaml());
    g1_bind();
    g1_bind_remap(['users' => 1234, 'orders' => 5]);

    [$code, $out] = g1_call(['file' => 't.yaml', '--target' => 'staging', '-v' => true]);

    expect($code)->toBe(0)->and($out)->toBe(
        "  Validating YAML ...\n  Validating YAML ✓\n"
        ."  Connecting to production-db and staging ...\n  Connecting to production-db and staging ✓\n"
        ."  Resolving table order ...\n  Resolving table order ✓\n"
        ."  Generating key mappings ...\n  Generating key mappings ✓\n"
        ."  ✓  Key mapping: users (1,234 rows)\n"
        ."  ✓  Key mapping: orders (5 rows)\n"
        ."  Comparing schema ...\n  Comparing schema (target matches source) ✓\n"
        .g1_summary()
    );
});

// ─── Orchestrator arguments ───────────────────────────────────────────────────

it('passes boolean flags and the dot observer in normal mode', function (array $flags, bool $expected): void {
    Storage::disk('local')->put('t.yaml', g1_yaml());
    $args = null;
    $inspections = 0;
    g1_bind(
        inspect: function () use (&$inspections): DatabaseSchemaData {
            $inspections++;

            return g1_schema([g1_table('users')]);
        },
        run: function (array $a) use (&$args) {
            $args = $a;

            return g1_result();
        },
    );

    [$code, $out] = g1_call(['file' => 't.yaml', '--target' => 'staging', ...$flags]);

    expect($code)->toBe(0)
        ->and($out)->toBe(g1_summary())
        ->and($inspections)->toBe(1)
        ->and($args[3]->databaseName)->toBe('appdb')
        ->and($args[5]->skipSchema)->toBe($expected)
        ->and($args[5]->breakOnFailure)->toBe($expected)
        ->and($args[4])->toBeInstanceOf(DotRunObserver::class)
        ->and($args[5]->dumpSink)->toBeNull();
})->with([
    'defaults' => [[], false],
    'non-bool values' => [['--skip-schema' => 1, '--break-on-failure' => 1], true],
]);

it('summarises schema differences in verbose mode', function (): void {
    Storage::disk('local')->put('t.yaml', g1_yaml());
    g1_bind(inspect: fn (ConnectionData $c): DatabaseSchemaData => $c->name === 'production-db'
        ? g1_schema([g1_table('users'), g1_table('orders'), g1_table('logs')])
        : g1_schema([g1_table('users', ['id' => ['bigint']]), g1_table('audit')]));

    [$code, $out] = g1_call(['file' => 't.yaml', '--target' => 'staging', '-v' => true]);

    expect($code)->toBe(0)->and($out)->toBe(
        "  Validating YAML ...\n  Validating YAML ✓\n"
        ."  Connecting to production-db and staging ...\n  Connecting to production-db and staging ✓\n"
        ."  Resolving table order ...\n  Resolving table order ✓\n"
        ."  Comparing schema ...\n  Comparing schema (differs: 2 missing, 1 modified, 1 extra on target) ✓\n"
        .g1_summary()
    );
});

it('reports a matching schema in verbose mode', function (): void {
    Storage::disk('local')->put('t.yaml', g1_yaml());
    g1_bind();

    [$code, $out] = g1_call(['file' => 't.yaml', '--target' => 'staging', '-v' => true]);

    expect($code)->toBe(0)->and($out)->toBe(
        "  Validating YAML ...\n  Validating YAML ✓\n"
        ."  Connecting to production-db and staging ...\n  Connecting to production-db and staging ✓\n"
        ."  Resolving table order ...\n  Resolving table order ✓\n"
        ."  Comparing schema ...\n  Comparing schema (target matches source) ✓\n"
        .g1_summary()
    );
});

// ─── Progress rendering ───────────────────────────────────────────────────────

it('wraps progress dots after seventy tables', function (int $tables, string $dots): void {
    Storage::disk('local')->put('t.yaml', g1_yaml());
    g1_bind(run: function (array $a) use ($tables): RunResultData {
        for ($i = 0; $i < $tables; $i++) {
            $a[4]->tableFinished('t'.$i, TableRunStatus::Transferred, 1, 0, []);
        }
        $a[4]->finished();

        return g1_result();
    });

    [$code, $out] = g1_call(['file' => 't.yaml', '--target' => 'staging']);

    expect($code)->toBe(0)->and($out)->toBe($dots.g1_summary());
})->with([
    'one' => [1, ".\n"],
    'sixty-nine' => [69, str_repeat('.', 69)."\n"],
    'seventy' => [70, str_repeat('.', 70)."\n"],
    'seventy-one' => [71, str_repeat('.', 70)."\n.\n"],
]);

it('renders verbose per-table progress with skip groups', function (): void {
    Storage::disk('local')->put('t.yaml', g1_yaml());
    g1_bind(run: function (array $a): RunResultData {
        $a[4]->tableStarted('users');
        $a[4]->tableFinished('users', TableRunStatus::Transferred, 1234, 0, []);
        $a[4]->tableStarted('orders');
        $a[4]->tableFinished('orders', TableRunStatus::Transferred, 1234, 1, [new SkippedRow('orders', 0, 0, null, 'dup')]);
        $a[4]->tableStarted('items');
        $rows = [new SkippedRow('items', 0, 0, null, 'A')];
        foreach (range('B', 'K') as $msg) {
            $rows[] = new SkippedRow('items', 0, 0, null, $msg);
            $rows[] = new SkippedRow('items', 0, 1, null, $msg);
        }
        $a[4]->tableFinished('items', TableRunStatus::Failed, 0, 0, $rows);
        $a[4]->tableStarted('bad');
        $a[4]->tableFinished('bad', TableRunStatus::Failed, 0, 0, []);
        $a[4]->tableFinished('ghost', TableRunStatus::NotFound, 0, 0, []);
        $a[4]->tableFinished('broken', TableRunStatus::SkippedBySchemaFailure, 0, 0, []);
        $a[4]->finished();

        return g1_result([g1_tr('ghost', TableRunStatus::NotFound), g1_tr('broken', TableRunStatus::SkippedBySchemaFailure)]);
    });

    [$code, $out] = g1_call(['file' => 't.yaml', '--target' => 'staging', '-v' => true]);

    $groups = '';
    foreach (range('B', 'K') as $msg) {
        $groups .= "     └ 2× {$msg}\n";
    }

    expect($code)->toBe(0)->and($out)->toBe(
        "  Validating YAML ...\n  Validating YAML ✓\n"
        ."  Connecting to production-db and staging ...\n  Connecting to production-db and staging ✓\n"
        ."  Resolving table order ...\n  Resolving table order ✓\n"
        ."  Comparing schema ...\n  Comparing schema (target matches source) ✓\n"
        ."    users ...\n    users (1,234 rows) ✓\n"
        ."    orders ...\n    orders (1,234 rows, 1 skipped) ✓\n"
        ."     └ 1× dup\n"
        ."    items ...\n    items ✗\n"
        .$groups
        ."     └ … and 1 more error types\n"
        ."    bad ...\n    bad ✗\n"
        ."  ?  ghost  — not found in source, skipped\n"
        ."  S  broken  — schema replication failed, skipped\n"
        ."\n  Warning: 1 table not found in source (ghost)\n"
        ."\n  Error: 1 table skipped due to schema replication failure (broken)\n"
        .g1_summary(0, 2)
    );
});

it('renders normal-mode indicators and the run summary', function (): void {
    Storage::disk('local')->put('t.yaml', g1_yaml());
    g1_bind(run: function (array $a): RunResultData {
        $a[4]->tableFinished('users', TableRunStatus::Transferred, 5, 0, []);
        $a[4]->tableFinished('orders', TableRunStatus::Transferred, 5, 1, []);
        $a[4]->tableFinished('items', TableRunStatus::Failed, 0, 0, []);
        $a[4]->tableFinished('ghost', TableRunStatus::NotFound, 0, 0, []);
        $a[4]->tableFinished('broken', TableRunStatus::SkippedBySchemaFailure, 0, 0, []);
        $a[4]->tableFinished('flagged', TableRunStatus::SkippedByFlag, 0, 0, []);
        $a[4]->finished();

        return g1_result([
            g1_tr('users', notes: ['n1', 'n2']),
            g1_tr('ghost', TableRunStatus::NotFound),
            g1_tr('broken', TableRunStatus::SkippedBySchemaFailure),
            g1_tr('orders', notes: ['n3']),
            g1_tr('items', TableRunStatus::Failed),
            g1_tr('flagged', TableRunStatus::SkippedByFlag),
            g1_tr('phantom', TableRunStatus::NotFound),
            g1_tr('cracked', TableRunStatus::SkippedBySchemaFailure),
        ], success: false, rows: 10, duration: 59.6);
    });

    [$code, $out] = g1_call(['file' => 't.yaml', '--target' => 'staging']);

    expect($code)->toBe(1)->and($out)->toBe(
        ".FE?S\n"
        ."\n  Warning: 2 tables not found in source (ghost, phantom)\n"
        ."\n  Error: 2 tables skipped due to schema replication failure (broken, cracked)\n"
        ."  Note: users: n1\n  Note: users: n2\n  Note: orders: n3\n"
        .g1_summary(2, 8, 10, '60s')
    );
});

it('reports each unfound or schema-failed table once', function (): void {
    Storage::disk('local')->put('t.yaml', g1_yaml());
    g1_bind(run: function (array $a): RunResultData {
        $a[4]->tableFinished('ghost', TableRunStatus::NotFound, 0, 0, []);
        $a[4]->tableFinished('broken', TableRunStatus::SkippedBySchemaFailure, 0, 0, []);
        $a[4]->finished();

        return g1_result([
            g1_tr('ghost', TableRunStatus::NotFound),
            g1_tr('broken', TableRunStatus::SkippedBySchemaFailure),
        ]);
    });

    [$code, $out] = g1_call(['file' => 't.yaml', '--target' => 'staging', '--allow-failure' => true]);

    expect($code)->toBe(0)->and($out)->toBe(
        "?S\n"
        ."\n  Warning: 1 table not found in source (ghost)\n"
        ."\n  Error: 1 table skipped due to schema replication failure (broken)\n"
        .g1_summary(0, 2)
    );
});

it('prints nothing for tables skipped by flag', function (): void {
    Storage::disk('local')->put('t.yaml', g1_yaml());
    g1_bind(run: function (array $a): RunResultData {
        $a[4]->tableFinished('flagged', TableRunStatus::SkippedByFlag, 0, 0, []);
        $a[4]->finished();

        return g1_result([g1_tr('flagged', TableRunStatus::SkippedByFlag)]);
    });

    [$code, $out] = g1_call(['file' => 't.yaml', '--target' => 'staging']);

    expect($code)->toBe(0)->and($out)->toBe(g1_summary(0, 1));
});

it('fails the run unless --allow-failure is given', function (): void {
    Storage::disk('local')->put('t.yaml', g1_yaml());
    g1_bind(run: fn (): RunResultData => g1_result(success: false));

    [$failed] = g1_call(['file' => 't.yaml', '--target' => 'staging', '--ci' => true]);
    [$allowed] = g1_call(['file' => 't.yaml', '--target' => 'staging', '--ci' => true, '--allow-failure' => true]);

    expect($failed)->toBe(1)->and($allowed)->toBe(0);
});

// ─── Audit ────────────────────────────────────────────────────────────────────

function g1_verbose_head(): string
{
    return "  Validating YAML ...\n  Validating YAML ✓\n"
        ."  Connecting to production-db and staging ...\n  Connecting to production-db and staging ✓\n"
        ."  Resolving table order ...\n  Resolving table order ✓\n"
        ."  Comparing schema ...\n  Comparing schema (target matches source) ✓\n";
}

/**
 * @return array<string, mixed>
 */
function g1_audit_config(): array
{
    return ['audit' => [
        'channels' => [
            'a' => ['type' => 'local', 'path' => 'aa/{year}/{month}/{day}/{source}-{target}-{timestamp}'],
            'b' => ['type' => 'local', 'path' => 'bb'],
            '5' => ['type' => 'local', 'path' => 'five'],
            'untyped' => ['name' => 'x'],
            'numeric' => ['type' => 5],
            'scalar' => 'nope',
        ],
        'use' => ['a'],
    ]];
}

it('writes signed audit artefacts and the process log through the configured channel', function (): void {
    Storage::disk('local')->put('t.yaml', g1_yaml());
    g1_bind(load: g1_audit_config());

    [$code, $out] = g1_call(['file' => 't.yaml', '--target' => 'staging', '-v' => true]);

    $files = collect(Storage::disk('local')->allFiles())->reject(fn (string $f): bool => $f === 't.yaml')->sort()->values()->all();
    $html = collect($files)->first(fn (string $f): bool => str_ends_with($f, '_audit.html'));
    preg_match('/production-db_staging_((\d{4})-(\d{2})-(\d{2})T\d{2}-\d{2}-\d{2}Z)_audit\.html$/', (string) $html, $m);
    [, $ts, $y, $mo, $d] = $m;
    $dir = "aa/{$y}/{$mo}/{$d}/production-db-staging-{$ts}";
    $base = "{$dir}/production-db_staging_{$ts}";

    expect($code)->toBe(0)
        ->and($out)->toBe(
            g1_verbose_head()
            ."  Generating audit log ...\n  Generating audit log ✓\n"
            ."  Delivering via local, local, local ...\n  Delivering via local, local, local ✓\n"
            .g1_summary()
        )
        ->and($files)->toBe([
            "{$base}_audit.html",
            "{$base}_audit.pdf",
            "{$base}_audit.sig",
            "{$base}_process.jsonl",
        ])
        ->and(Storage::disk('local')->get("{$base}_audit.sig"))->toMatch('/^sha256:[0-9a-f]{64}$/')
        ->and(Storage::disk('local')->get("{$base}_audit.html"))->toContain('<html')
        ->and(Storage::disk('local')->get("{$base}_audit.pdf"))->toStartWith('%PDF');
});

it('delivers only to the trimmed --audit-channel names', function (): void {
    Storage::disk('local')->put('t.yaml', g1_yaml());
    g1_bind(load: g1_audit_config());
    Log::spy();

    [$code, $out] = g1_call(['file' => 't.yaml', '--target' => 'staging', '--ci' => true, '--audit-channel' => ' b , ,5']);

    $dirs = collect(Storage::disk('local')->allFiles())->map(fn (string $f): string => dirname($f))->unique()->sort()->values()->all();

    expect($code)->toBe(0)->and($out)->toBe('')->and($dirs)->toBe(['.', 'bb', 'five']);
    Log::shouldNotHaveReceived('warning');
});

it('casts a non-string --audit-channel value to a string', function (): void {
    Storage::disk('local')->put('t.yaml', g1_yaml());
    g1_bind(load: g1_audit_config());

    [$code] = g1_call(['file' => 't.yaml', '--target' => 'staging', '--ci' => true, '--audit-channel' => 5]);

    $dirs = collect(Storage::disk('local')->allFiles())->map(fn (string $f): string => dirname($f))->unique()->sort()->values()->all();

    expect($code)->toBe(0)->and($dirs)->toBe(['.', 'five']);
});

it('uses a generic delivery label when the audit config lists no channels', function (array $load, array $flags): void {
    Storage::disk('local')->put('t.yaml', g1_yaml());
    g1_bind(load: $load);

    [$code, $out] = g1_call(['file' => 't.yaml', '--target' => 'staging', '-v' => true, ...$flags]);

    expect($code)->toBe(0)->and($out)->toBe(
        g1_verbose_head()
        ."  Generating audit log ...\n  Generating audit log ✓\n"
        ."  Delivering audit log ...\n  Delivering audit log ✓\n"
        .g1_summary()
    );
})->with([
    'no channels key' => [['audit' => ['use' => []]], []],
    'channels not a list' => [['audit' => ['channels' => 'x']], []],
    'cli channel only' => [[], ['--audit-channel' => 'a']],
]);

it('skips the audit phase without config or channels', function (): void {
    Storage::disk('local')->put('t.yaml', g1_yaml());
    g1_bind(load: ['audit' => 'disabled']);

    [$code, $out] = g1_call(['file' => 't.yaml', '--target' => 'staging', '-v' => true]);

    expect($code)->toBe(0)->and($out)->toBe(g1_verbose_head().g1_summary());
});

// ─── Real SQLite dump ─────────────────────────────────────────────────────────

it('reports the dump archive size and encryption', function (array $flags, string $password, string $tail): void {
    $source = sys_get_temp_dir().'/g1_src_'.uniqid().'.db';
    $pdo = new PDO('sqlite:'.$source);
    $pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, email TEXT NOT NULL)');
    $pdo->exec("INSERT INTO users (id, email) VALUES (1, 'a@example.com')");
    $pdo = null;
    Storage::disk('local')->put('clonio.json', (string) json_encode(['connections' => [
        'production-db' => ['type' => 'sqlite', 'database' => $source, 'is_production' => true],
        'dump' => ['type' => 'dump', 'dialect' => 'sqlite', 'is_production' => false, 'password' => $password],
    ]]));
    Storage::disk('local')->put('t.yaml', g1_yaml());
    Log::spy();

    try {
        [$code, $out] = g1_call(['file' => 't.yaml', '--target' => 'dump', ...$flags]);
    } finally {
        @unlink($source);
    }

    $zip = collect(Storage::disk('local')->allFiles())->first(fn (string $f): bool => str_ends_with($f, '.zip'));
    $bytes = Storage::disk('local')->size((string) $zip);
    $size = $bytes < 1024 ? $bytes.' B' : sprintf('%.1f KB', $bytes / 1024);

    expect($code)->toBe(0)
        ->and($out)->toEndWith(sprintf('  Dump: %s  (%s%s', $zip, $size, $tail));
    Log::shouldHaveReceived('info')->with('dump_archived', ['file' => $zip, 'bytes' => $bytes])->once();
})->with([
    'ci, encrypted' => [['--ci' => true], 'pw', ", AES-256)\n"],
    'normal, plain' => [[], '', ")\n\n"],
]);

// ─── Dry run ──────────────────────────────────────────────────────────────────

function g1_row(string $table, string $rows, string $strategy, string $transform): string
{
    return sprintf("  %-24s %-14s %-10s %s\n", $table, $rows, $strategy, $transform);
}

function g1_sep(): string
{
    return '  '.str_repeat('─', 68)."\n";
}

const G1_MYSQL_ESTIMATE = 'SELECT TABLE_ROWS AS c FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?';

/**
 * @param  list<array{string, list<string>, mixed}>  $selects  [SQL, bindings, result row or Throwable]
 */
function g1_db(array $selects, int $purges): void
{
    $conn = Mockery::mock();
    $conn->shouldReceive('select')->never();
    foreach ($selects as [$sql, $bindings, $result]) {
        $exp = $conn->shouldReceive('selectOne')->once()->with($sql, $bindings);
        $result instanceof Throwable ? $exp->andThrow($result) : $exp->andReturn($result);
    }
    DB::shouldReceive('connection')->with('test_conn')->andReturn($conn);
    DB::shouldReceive('purge')->times($purges)->with('test_conn');
}

it('estimates rows per strategy in a dry run', function (): void {
    Storage::disk('local')->put('t.yaml', g1_yaml(
        "  users:\n    rows:\n      strategy: first\n      limit: 2\n    columns:\n      email:\n        strategy: \"null\"\n      name:\n        strategy: keep\n      phone:\n        strategy: \"null\"\n"
        ."  orders:\n    rows:\n      strategy: last\n      limit: 10\n"
        ."  logs:\n    rows:\n      strategy: schema\n"
        ."  items:\n    rows:\n      strategy: full\n"
        ."  ghost:\n    rows:\n      strategy: full\n"
    ));
    g1_bind(inspect: fn (ConnectionData $c): DatabaseSchemaData => $c->name === 'production-db'
        ? g1_schema([g1_table('users'), g1_table('orders'), g1_table('logs'), g1_table('items')])
        : throw new RuntimeException('target down'));
    // 'schema' copies no rows and is not queried; one source connection serves all tables.
    g1_db([
        [G1_MYSQL_ESTIMATE, ['users'], (object) ['c' => '5']],
        [G1_MYSQL_ESTIMATE, ['orders'], (object) ['c' => 3]],
        [G1_MYSQL_ESTIMATE, ['items'], (object) ['c' => 4]],
    ], 3);

    [$code, $out] = g1_call(['file' => 't.yaml', '--target' => 'staging', '--dry-run' => true]);

    expect($code)->toBe(0)->and($out)->toBe(
        "\n  Dry-run: production-db\n\n"
        .g1_row('Table', 'Rows (est.)', 'Strategy', 'Transformations')
        .g1_sep()
        .g1_row('users', '~2', 'first 2', 'email, phone')
        .g1_row('orders', '~3', 'last 10', '—')
        .g1_row('logs', '0', 'schema', '—')
        .g1_row('items', '~4', 'full', '—')
        .g1_row('ghost', 'NOT FOUND', '—', '—')
        .g1_sep()
        ."  Total                    ~9 rows across 5 tables, 1 not found, will be skipped\n"
        ."\n  No data will be transferred. Run without --dry-run to execute.\n\n"
    );
});

it('reads catalog statistics on server sources and counts only on SQLite', function (DatabaseConnectionType $type, string $sql, array $bindings, string $rows): void {
    Storage::disk('local')->put('t.yaml', g1_yaml("  users:\n    rows:\n      strategy: full\n"));
    g1_bind(connections: [
        'production-db' => g1_conn('production-db', $type, production: true),
        'staging' => g1_conn('staging'),
    ]);
    g1_db([[$sql, $bindings, (object) ['c' => 1500]]], 3);

    [$code, $out] = g1_call(['file' => 't.yaml', '--target' => 'staging', '--dry-run' => true]);

    expect($code)->toBe(0)->and($out)->toBe(
        "\n  Dry-run: production-db\n\n"
        ."  Schema diff: target matches source\n\n"
        .g1_row('Table', 'Rows (est.)', 'Strategy', 'Transformations')
        .g1_sep()
        .g1_row('users', $rows, 'full', '—')
        .g1_sep()
        ."  Total                    {$rows} rows across 1 tables\n"
        ."\n  No data will be transferred. Run without --dry-run to execute.\n\n"
    );
})->with([
    'mysql' => [DatabaseConnectionType::Mysql, G1_MYSQL_ESTIMATE, ['users'], '~1,500'],
    'mariadb' => [DatabaseConnectionType::MariaDB, G1_MYSQL_ESTIMATE, ['users'], '~1,500'],
    'sqlsrv' => [DatabaseConnectionType::SqlServer, 'SELECT SUM(p.rows) AS c FROM sys.partitions p WHERE p.object_id = OBJECT_ID(?) AND p.index_id IN (0, 1)', ['users'], '~1,500'],
    'pgsql' => [DatabaseConnectionType::PostgreSQL, 'SELECT c.reltuples::bigint AS c FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace WHERE n.nspname = current_schema() AND c.relname = ?', ['users'], '~1,500'],
    'sqlite' => [DatabaseConnectionType::Sqlite, 'SELECT COUNT(*) AS c FROM "users"', [], '1,500'],
]);

it('bounds the SQLite count of a first or last table in a dry run', function (string $strategy): void {
    Storage::disk('local')->put('t.yaml', g1_yaml("  users:\n    rows:\n      strategy: {$strategy}\n      limit: 100\n"));
    g1_bind(connections: [
        'production-db' => g1_conn('production-db', DatabaseConnectionType::Sqlite, production: true),
        'staging' => g1_conn('staging'),
    ]);
    g1_db([['SELECT COUNT(*) AS c FROM (SELECT 1 FROM "users" LIMIT 100)', [], (object) ['c' => 100]]], 3);

    [$code, $out] = g1_call(['file' => 't.yaml', '--target' => 'staging', '--dry-run' => true]);

    expect($code)->toBe(0)->and($out)->toContain(g1_row('users', '100', $strategy.' 100', '—'));
})->with(['first', 'last']);

it('leaves the estimate empty when the source cannot be opened in a dry run', function (): void {
    Storage::disk('local')->put('t.yaml', g1_yaml("  users:\n    rows:\n      strategy: full\n"));
    g1_bind();
    $connector = Mockery::mock(DatabaseConnectionService::class);
    // The connection check opens source and target; the third open is the estimate's.
    $opens = 0;
    $connector->shouldReceive('open')->andReturnUsing(static function () use (&$opens): string {
        return ++$opens <= 2 ? 'test_conn' : throw new RuntimeException('refused');
    });
    app()->instance(DatabaseConnectionService::class, $connector);
    DB::shouldReceive('purge')->twice()->with('test_conn');

    [$code, $out] = g1_call(['file' => 't.yaml', '--target' => 'staging', '--dry-run' => true]);

    expect($code)->toBe(0)
        ->and($out)->toContain(g1_row('users', '?', 'full', '—'))
        ->and($out)->toContain("  Total                    0 rows across 1 tables\n");
});

it('opens the source once for all tables of a dry run', function (): void {
    Storage::disk('local')->put('t.yaml', g1_yaml("  users:\n    rows:\n      strategy: full\n  orders:\n    rows:\n      strategy: full\n"));
    g1_bind(inspect: fn (): DatabaseSchemaData => g1_schema([g1_table('users'), g1_table('orders')]));
    $connector = Mockery::mock(DatabaseConnectionService::class);
    // Two for the connection check, one for the estimates.
    $connector->shouldReceive('open')->times(3)->andReturn('test_conn');
    app()->instance(DatabaseConnectionService::class, $connector);
    g1_db([
        [G1_MYSQL_ESTIMATE, ['users'], (object) ['c' => 1]],
        [G1_MYSQL_ESTIMATE, ['orders'], (object) ['c' => 2]],
    ], 3);

    [$code, $out] = g1_call(['file' => 't.yaml', '--target' => 'staging', '--dry-run' => true]);

    expect($code)->toBe(0)->and($out)->toContain("  Total                    ~3 rows across 2 tables\n");
});

it('opens no source connection when a dry run copies no rows', function (): void {
    Storage::disk('local')->put('t.yaml', g1_yaml("  users:\n    rows:\n      strategy: schema\n"));
    g1_bind();
    DB::shouldReceive('connection')->never();
    DB::shouldReceive('purge')->twice()->with('test_conn'); // the connection check only

    [$code, $out] = g1_call(['file' => 't.yaml', '--target' => 'staging', '--dry-run' => true]);

    expect($code)->toBe(0)->and($out)->toContain(g1_row('users', '0', 'schema', '—'));
});

it('falls back when the row count result is unusable', function (mixed $rows, string $label): void {
    Storage::disk('local')->put('t.yaml', g1_yaml("  users:\n    rows:\n      strategy: full\n"));
    g1_bind();
    g1_db([[G1_MYSQL_ESTIMATE, ['users'], $rows]], 3);

    [$code, $out] = g1_call(['file' => 't.yaml', '--target' => 'staging', '--dry-run' => true]);

    expect($code)->toBe(0)->and($out)->toBe(
        "\n  Dry-run: production-db\n\n"
        ."  Schema diff: target matches source\n\n"
        .g1_row('Table', 'Rows (est.)', 'Strategy', 'Transformations')
        .g1_sep()
        .g1_row('users', $label, 'full', '—')
        .g1_sep()
        ."  Total                    0 rows across 1 tables\n"
        ."\n  No data will be transferred. Run without --dry-run to execute.\n\n"
    );
})->with([
    'no row' => [null, '?'],
    'missing count column' => [(object) ['other' => 3], '?'],
    'non-numeric count' => [(object) ['c' => 'abc'], '?'],
    'never analyzed' => [(object) ['c' => -1], '?'],
    'query error' => [new RuntimeException('boom'), '?'],
]);

it('renders the schema diff in a dry run', function (): void {
    Storage::disk('local')->put('t.yaml', g1_yaml("  users:\n    rows:\n      strategy: last\n      limit: 1500\n"));
    g1_bind(inspect: fn (ConnectionData $c): DatabaseSchemaData => $c->name === 'production-db'
        ? g1_schema([
            g1_table('users', ['id' => ['int'], 'email' => ['varchar'], 'phone' => ['varchar']]),
            g1_table('orders'),
            g1_table('logs'),
            g1_table('items', ['id' => ['int'], 'a' => ['int'], 'c' => ['int']]),
        ])
        : g1_schema([
            g1_table('users', ['id' => ['bigint', true], 'name' => ['text'], 'nick' => ['text']]),
            g1_table('items', ['id' => ['int'], 'b' => ['int'], 'c' => ['bigint']]),
            g1_table('audit'),
            g1_table('extra2'),
        ]));
    g1_db([[G1_MYSQL_ESTIMATE, ['users'], (object) ['c' => 2000]]], 3);

    [$code, $out] = g1_call(['file' => 't.yaml', '--target' => 'staging', '--dry-run' => true]);

    expect($code)->toBe(0)->and($out)->toBe(
        "\n  Dry-run: production-db\n\n"
        ."  Schema diff: source → target\n\n"
        ."  Missing tables (2):   orders, logs\n"
        ."  Extra tables (2):     audit, extra2\n"
        ."  Modified table:        users: +2 cols (email, phone), -2 cols (name, nick), ~id (int → bigint, nullable changed)\n"
        ."  Modified table:        items: +1 col (a), -1 col (b), ~c (int → bigint)\n"
        ."\n"
        .g1_row('Table', 'Rows (est.)', 'Strategy', 'Transformations')
        .g1_sep()
        .g1_row('users', '~1,500', 'last 1,500', '—')
        .g1_sep()
        ."  Total                    ~1,500 rows across 1 tables\n"
        ."\n  No data will be transferred. Run without --dry-run to execute.\n\n"
    );
});

it('prints nothing for a dry run in CI mode', function (): void {
    Storage::disk('local')->put('t.yaml', g1_yaml());
    g1_bind();
    g1_db([[G1_MYSQL_ESTIMATE, ['users'], (object) ['c' => 1]]], 3);

    [$code, $out] = g1_call(['file' => 't.yaml', '--target' => 'staging', '--dry-run' => true, '--ci' => true]);

    expect($code)->toBe(0)->and($out)->toBe('');
});

// ─── Formatting helpers ───────────────────────────────────────────────────────

it('formats durations', function (float $seconds, string $expected): void {
    $command = app(RunCommand::class);

    expect((fn (float $s): string => $this->formatDuration($s))->call($command, $seconds))->toBe($expected);
})->with([
    [0.0, '0s'],
    [59.0, '59s'],
    [59.4, '59s'],
    [60.0, '1m 0s'],
    [75.4, '1m 15s'],
    [3600.0, '60m 0s'],
]);

it('formats byte counts', function (int $bytes, string $expected): void {
    $command = app(RunCommand::class);

    expect((fn (int $b): string => $this->formatBytes($b))->call($command, $bytes))->toBe($expected);
})->with([
    [0, '0 B'],
    [1023, '1023 B'],
    [1024, '1.0 KB'],
    [1536, '1.5 KB'],
    [1048575, '1024.0 KB'],
    [1048576, '1.0 MB'],
    [1073741824, '1.0 GB'],
    [1099511627776, '1024.0 GB'],
]);

// ─── Key remapping exhaustion & memory hints ──────────────────────────────────

function g1_bind_exhausted(): void
{
    g1_bind();
    $remapping = Mockery::mock(KeyRemappingService::class);
    $remapping->shouldReceive('generateMappings')->andThrow(new KeyRemappingExhaustedException(
        table: 'users', column: 'id', columnType: 'int', unsigned: false,
        typeMax: 2147483647, rowCount: 10, upperSlots: 3, gapSlots: 4,
    ));
    app()->instance(KeyRemappingService::class, $remapping);
}

it('prints the exhaustion hint in CI mode', function (): void {
    Storage::disk('local')->put('t.yaml', g1_remap_yaml());
    g1_bind_exhausted();

    [$code, $out] = g1_call(['file' => 't.yaml', '--target' => 'staging', '--ci' => true]);

    expect($code)->toBe(1)->and($out)->toBe(
        "Cannot remap users.id: key remapping exhausted.\n"
        ."  Column type: INT (signed, ceiling 2147483647)\n"
        ."  Rows requested: 10\n"
        ."  Slots available: 7 (3 above MAX(id), 4 gaps below)\n"
        ."\n"
        ."Hint: switch the column's strategy to 'keep' to skip remapping for this table, or widen the column type.\n"
        ."\n"
        ."    clonio cloning:column:edit t.yaml --table=users --column=id --strategy=keep\n"
        ."\n"
    );
});

it('cancels the exhaustion recovery by default when not interactive', function (): void {
    Storage::disk('local')->put('t.yaml', g1_remap_yaml());
    g1_bind_exhausted();

    [$code, $out] = g1_call(['file' => 't.yaml', '--target' => 'staging', '--no-interaction' => true]);

    expect($code)->toBe(0)
        ->and($out)->toBe(
            "\n"
            ."  Cannot remap users.id — key remapping exhausted.\n"
            ."  Column type: INT (signed, ceiling 2147483647)\n"
            ."  Rows requested: 10\n"
            ."  Slots available: 7 (3 above MAX(id), 4 gaps below)\n"
            ."\n"
            ."  Switching the column to the 'keep' strategy will skip remapping for this\n"
            ."  table and let the run continue with the original primary-key values.\n"
            ."\n"
            ."Cancelled. Re-run after editing the configuration.\n"
        )
        ->and(Storage::disk('local')->get('t.yaml'))->toBe(g1_remap_yaml());
});

it('patches the column and prints the re-run command after interactive confirmation', function (): void {
    Storage::disk('local')->put('other.yaml', g1_remap_yaml());
    g1_bind_exhausted();

    $command = app(RunCommand::class);
    $command->setLaravel(app());
    $input = new ArrayInput(['file' => 'other.yaml', '--target' => 'staging', '--skip-schema' => true], $command->getDefinition());
    $stream = fopen('php://memory', 'r+');
    fwrite($stream, "yes\n");
    rewind($stream);
    $input->setStream($stream);
    $input->setInteractive(true);
    $output = new BufferedOutput;

    $code = $command->run($input, $output);

    expect($code)->toBe(0)
        ->and($output->fetch())->toBe(
            "\n"
            ."  Cannot remap users.id — key remapping exhausted.\n"
            ."  Column type: INT (signed, ceiling 2147483647)\n"
            ."  Rows requested: 10\n"
            ."  Slots available: 7 (3 above MAX(id), 4 gaps below)\n"
            ."\n"
            ."  Switching the column to the 'keep' strategy will skip remapping for this\n"
            ."  table and let the run continue with the original primary-key values.\n"
            ."\n"
            ." Switch users.id strategy to 'keep' in other.yaml? (yes/no) [no]:\n"
            ." > \n"
            ."\n"
            ."Updated users.id → strategy: keep in other.yaml.\n"
            ."\nConfiguration patched. Re-run the cloning command:\n\n    clonio cloning:run other.yaml --target=staging --skip-schema\n\n"
        )
        ->and(Storage::disk('local')->get('other.yaml'))->toContain('strategy: keep');
});

it('suggests --no-memory-limit with the full original command line', function (array $flags, string $expected): void {
    Storage::disk('local')->put('t.yaml', g1_remap_yaml());
    g1_bind();
    $opened = 0;
    $connector = Mockery::mock(DatabaseConnectionService::class);
    $connector->shouldReceive('open')->andReturnUsing(function () use (&$opened): string {
        if (++$opened === 3) {
            throw new RuntimeException('Allowed memory size of 1 bytes exhausted');
        }

        return 'test_conn';
    });
    app()->instance(DatabaseConnectionService::class, $connector);

    [$code, $out] = g1_call([
        'file' => 't.yaml', '--target' => 'staging', '--allow-failure' => true, '--ci' => true, '--skip-schema' => true,
        '--skip-tables' => 'a,b', '--audit-channel' => 'x', '--file-based' => true, '--break-on-failure' => true, ...$flags,
    ]);

    expect($code)->toBe(1)->and($out)->toBe(
        "  Warning: --skip-tables name \"a\" matches no table in the config\n"
        ."  Warning: --skip-tables name \"b\" matches no table in the config\n"
        ."Memory exhausted during key mapping generation: Allowed memory size of 1 bytes exhausted\n\n"
        ."Hint: Re-run with --no-memory-limit to remove the PHP memory limit:\n\n"
        .'    clonio cloning:run t.yaml --target=staging --allow-failure --ci --skip-schema --skip-tables=a,b'
        .' --audit-channel=x'.$expected.' --break-on-failure'
        ." --no-memory-limit\n\n"
    );
})->with([
    'flags on' => [
        ['--remap-keys' => true, '--enforce-column-types' => true, '--drop-unknown-tables' => true, '--drop-extra-columns' => true, '--recreate-tables' => true, '--disable-fk-checks' => true],
        ' --remap-keys --file-based --enforce-column-types --drop-unknown-tables --drop-extra-columns --recreate-tables --disable-fk-checks',
    ],
    'flags off' => [
        ['--no-enforce-column-types' => true, '--no-drop-unknown-tables' => true, '--no-drop-extra-columns' => true, '--no-recreate-tables' => true, '--no-disable-fk-checks' => true],
        ' --file-based --no-enforce-column-types --no-drop-unknown-tables --no-drop-extra-columns --no-recreate-tables --no-disable-fk-checks',
    ],
]);

it('omits empty value options from the memory hint', function (): void {
    Storage::disk('local')->put('t.yaml', g1_remap_yaml(extraTables: "  c:\n    rows:\n      strategy: full\n"));
    g1_bind();
    $remapping = Mockery::mock(KeyRemappingService::class);
    $remapping->shouldReceive('generateMappings')->andThrow(new RuntimeException('Out of memory'));
    app()->instance(KeyRemappingService::class, $remapping);

    [$code, $out] = g1_call(['file' => 't.yaml', '--target' => 'staging', '--skip-tables' => '', '--only-tables' => 'c', '--audit-channel' => '']);

    expect($code)->toBe(1)->and($out)->toBe(
        "Memory exhausted during key mapping generation: Out of memory\n\n"
        ."Hint: Re-run with --no-memory-limit to remove the PHP memory limit:\n\n"
        ."    clonio cloning:run t.yaml --target=staging --only-tables=c --no-memory-limit\n\n"
    );
});

it('lists not-found and schema-failure tables from the result in CI mode', function (): void {
    Storage::disk('local')->put('t.yaml', g1_yaml());
    g1_bind(run: function (array $a): RunResultData {
        $a[4]->tableFinished('users', TableRunStatus::Transferred, 5, 0, []);
        $a[4]->tableFinished('ghost', TableRunStatus::NotFound, 0, 0, []);
        $a[4]->tableFinished('broken', TableRunStatus::SkippedBySchemaFailure, 0, 0, []);
        $a[4]->tableFinished('items', TableRunStatus::Failed, 0, 0, []);
        $a[4]->finished();

        return g1_result([
            g1_tr('users'),
            g1_tr('ghost', TableRunStatus::NotFound),
            g1_tr('broken', TableRunStatus::SkippedBySchemaFailure),
            g1_tr('items', TableRunStatus::Failed),
        ], rows: 5);
    });

    [$code, $out] = g1_call(['file' => 't.yaml', '--target' => 'staging', '--ci' => true]);

    expect($code)->toBe(0)->and($out)->toBe(
        "\n  Warning: 1 table not found in source (ghost)\n"
        ."\n  Error: 1 table skipped due to schema replication failure (broken)\n"
    );
});

it('ignores a row limit for the full strategy in a dry run', function (): void {
    Storage::disk('local')->put('t.yaml', g1_yaml("  users:\n    rows:\n      strategy: full\n      limit: 2\n"));
    g1_bind();
    g1_db([[G1_MYSQL_ESTIMATE, ['users'], (object) ['c' => 5]]], 3);

    [$code, $out] = g1_call(['file' => 't.yaml', '--target' => 'staging', '--dry-run' => true]);

    expect($code)->toBe(0)->and($out)->toBe(
        "\n  Dry-run: production-db\n\n"
        ."  Schema diff: target matches source\n\n"
        .g1_row('Table', 'Rows (est.)', 'Strategy', 'Transformations')
        .g1_sep()
        .g1_row('users', '~5', 'full', '—')
        .g1_sep()
        ."  Total                    ~5 rows across 1 tables\n"
        ."\n  No data will be transferred. Run without --dry-run to execute.\n\n"
    );
});

// ─── Live progress bars ───────────────────────────────────────────────────────

/**
 * A real ConsoleOutput (sections need one) writing to a memory stream.
 *
 * @param  resource  $stream
 */
function g1_console(mixed $stream, bool $decorated = true, int $verbosity = OutputInterface::VERBOSITY_VERBOSE, ?bool $stderrDecorated = null): ConsoleOutput
{
    return new class($stream, $decorated, $verbosity, $stderrDecorated ?? $decorated) extends ConsoleOutput
    {
        public function __construct(mixed $stream, bool $decorated, int $verbosity, bool $stderrDecorated)
        {
            StreamOutput::__construct($stream, $verbosity, $decorated);
            $this->setErrorOutput(new StreamOutput($stream, $verbosity, $stderrDecorated));
        }
    };
}

/** The stderr handler of the default log stack. */
function g1_stderr_handler(): StreamHandler
{
    $handlers = array_values(array_filter(
        Log::driver()->getLogger()->getHandlers(),
        static fn (object $h): bool => $h instanceof StreamHandler && $h->getUrl() === 'php://stderr',
    ));

    return $handlers[0];
}

/** Run cloning:run directly against the given output. */
function g1_run_on(OutputInterface $output, array $params = []): int
{
    $command = app(RunCommand::class);
    $command->setLaravel(app());

    return $command->run(new ArrayInput(['file' => 't.yaml', '--target' => 'staging', ...$params], $command->getDefinition()), $output);
}

/**
 * Replay the stream like a terminal: apply the cursor-up / erase sequences that
 * console sections and progress bars emit, and return the visible lines.
 *
 * @param  resource  $stream
 * @return list<string>
 */
function g1_screen(mixed $stream): array
{
    rewind($stream);
    $raw = (string) stream_get_contents($stream);
    fseek($stream, 0, SEEK_END);

    $lines = [''];
    $row = 0;
    $col = 0;
    $tokens = preg_split('/(\e\][^\e]*\e\\\\|\e\[[0-9;?]*[A-Za-z]|\n|\r)/', $raw, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);

    foreach ($tokens as $token) {
        if ($token === "\n") {
            $row++;
            $col = 0;
            $lines[$row] ??= '';
        } elseif ($token === "\r") {
            $col = 0;
        } elseif (preg_match('/^\e\[(\d*)A$/', $token, $m) === 1) {
            $row = max(0, $row - (int) ($m[1] === '' ? 1 : $m[1]));
        } elseif ($token === "\e[K" || $token === "\e[0K" || $token === "\e[0J" || $token === "\e[J") {
            $lines[$row] = mb_substr($lines[$row], 0, $col);
            if (str_ends_with($token, 'J')) {
                $lines = array_slice($lines, 0, $row + 1);
            }
        } elseif (! str_starts_with($token, "\e")) {
            $line = str_pad($lines[$row], $col);
            $lines[$row] = mb_substr($line, 0, $col).$token.mb_substr($line, $col + mb_strlen($token));
            $col += mb_strlen($token);
        }
    }

    return array_map(rtrim(...), $lines);
}

it('drives live progress bars on a decorated console and logs above them', function (): void {
    Storage::disk('local')->put('t.yaml', g1_yaml());
    $stream = fopen('php://memory', 'w+');
    $captured = [];
    $screens = [];
    g1_bind(run: function (array $a) use (&$captured, &$screens, $stream): RunResultData {
        $captured = $a;
        $a[4]->started(5);

        $a[4]->tableStarted('users');
        $users = new StatsTableTransferData;
        $users->setTotalRows(20, estimated: true);
        $users->setStatus(TableRunPhase::Loop);
        $users->recordLoop(new StatsLoopData(10, 0.25, 0.25, 0.5, 1.0, 9, 1));
        $a[4]->tableProgress('users', $users);
        Log::warning('row_skipped', ['table' => 'users']); // shown as the skip group instead
        Log::warning('table_column_note', ['table' => 'users']);
        $a[4]->tableFinished('users', TableRunStatus::Transferred, 9, 1, [new SkippedRow('users', 0, 0, null, 'dup')]);
        $screens['users'] = g1_screen($stream);

        $a[4]->tableStarted('items');
        $a[4]->tableFinished('items', TableRunStatus::Transferred, 1500, 0, []);
        $a[4]->tableFinished('ghost', TableRunStatus::NotFound, 0, 0, []);
        $a[4]->tableFinished('broken', TableRunStatus::SkippedBySchemaFailure, 0, 0, []);

        $a[4]->tableStarted('orders');
        $orders = new StatsTableTransferData;
        $orders->setStatus(TableRunPhase::Loop);
        $orders->recordLoop(new StatsLoopData(5, 0.25, 0.25, 0.5, 1.0, 5, 0));
        $a[4]->tableProgress('orders', $orders);
        $a[4]->tableFinished('orders', TableRunStatus::Failed, 0, 0, []);
        $screens['orders'] = g1_screen($stream);
        $a[4]->finished();

        return g1_result();
    });

    $code = g1_run_on(g1_console($stream));

    // Logging is handed back to stderr once the bars are gone.
    Log::warning('after_bars');

    $final = g1_screen($stream);
    // Below the four pre-run step lines: the log, the per-table bar, the overall bar.
    $bars = static fn (array $screen): array => array_slice($screen, 4);

    expect($code)->toBe(0)
        ->and($captured[4])->toBeInstanceOf(ProgressRunObserver::class)
        ->and($captured[5]->trackRowTotals)->toBeTrue() // trackRowTotals
        ->and($final[0])->toStartWith('  Validating YAML ')
        ->and($bars($screens['users']))->toBe([
            '  WARNING: table_column_note {"table":"users"}',
            '  ✓  users  (9 rows, 1 skipped)',
            '     └ 1× dup',
            '  users  [============================]   10  100000.0 s/M',
            '',
            '  tables  [=====>----------------------] 1/5',
            '',
        ])
        ->and($bars($screens['orders']))->toBe([
            '  WARNING: table_column_note {"table":"users"}',
            '  ✓  users  (9 rows, 1 skipped)',
            '     └ 1× dup',
            '  ✓  items  (1,500 rows)',
            '  ?  ghost  — not found in source, skipped',
            '  S  broken  — schema replication failed, skipped',
            '  ✗  orders  — transfer failed',
            '  orders  stopped after 5 rows',
            '',
            '  tables  [============================] 5/5',
            '',
        ])
        ->and(implode("\n", $final))->not->toContain('after_bars')
        ->and(implode("\n", $final))->not->toContain('row_skipped')
        ->and(implode("\n", $final))->not->toContain('timing summary');
});

it('keeps the step renderer when live bars are not possible', function (bool $decorated, array $params, string $expectedLine, string $observer): void {
    Storage::disk('local')->put('t.yaml', g1_yaml());
    $stream = fopen('php://memory', 'w+');
    $captured = [];
    g1_bind(run: function (array $a) use (&$captured): RunResultData {
        $captured = $a;
        $a[4]->tableStarted('users');
        $a[4]->tableFinished('users', TableRunStatus::Transferred, 3, 0, []);
        $a[4]->finished();

        return g1_result();
    });

    $code = g1_run_on(g1_console($stream, $decorated, OutputInterface::VERBOSITY_VERBOSE), $params);
    $screen = implode("\n", g1_screen($stream));

    expect($code)->toBe(0)
        ->and($captured[4])->toBeInstanceOf($observer)
        ->and($captured[5]->trackRowTotals)->toBeFalse() // trackRowTotals
        ->and($screen)->toContain($expectedLine)
        ->and($screen)->not->toContain('tables  [');
})->with([
    'plain console' => [false, [], '    users (3 rows) ✓', StepRunObserver::class],
    'decorated console in CI' => [true, ['--ci' => true], '', NullCloningRunObserver::class],
]);

it('shows no live bars on a decorated console without -v', function (): void {
    Storage::disk('local')->put('t.yaml', g1_yaml());
    $stream = fopen('php://memory', 'w+');
    g1_bind(run: function (array $a): RunResultData {
        expect($a[4])->toBeInstanceOf(DotRunObserver::class);
        $a[4]->tableFinished('users', TableRunStatus::Transferred, 3, 0, []);
        $a[4]->finished();

        return g1_result();
    });

    expect(g1_run_on(g1_console($stream, verbosity: OutputInterface::VERBOSITY_NORMAL)))->toBe(0)
        ->and(g1_screen($stream)[0])->toBe('.');
});

it('needs a console output with sections for live bars', function (): void {
    Storage::disk('local')->put('t.yaml', g1_yaml());
    g1_bind(run: function (array $a): RunResultData {
        expect($a[4])->toBeInstanceOf(StepRunObserver::class);

        return g1_result();
    });

    $output = new BufferedOutput(OutputInterface::VERBOSITY_VERBOSE, decorated: true);

    expect(g1_run_on($output))->toBe(0);
});

it('prints the timing summary only at -vvv and only for tables with timings', function (): void {
    Storage::disk('local')->put('t.yaml', g1_yaml());
    $captured = [];
    g1_bind(run: function (array $a) use (&$captured): RunResultData {
        $captured = $a;
        $a[4]->tableStarted('users');
        $a[4]->tableFinished('users', TableRunStatus::Failed, 0, 0, []); // never reported progress

        $a[4]->tableStarted('orders');
        $orders = new StatsTableTransferData;
        $orders->recordLoop(new StatsLoopData(2, 0.25, 0.25, 0.5, 1.0, 2, 0));
        $a[4]->tableProgress('orders', $orders);
        $a[4]->tableFinished('orders', TableRunStatus::Transferred, 2, 0, []);
        $a[4]->finished();

        return g1_result();
    });

    [$code, $out] = g1_call(['file' => 't.yaml', '--target' => 'staging', '-vvv' => true]);

    expect($code)->toBe(0)
        ->and($captured[5]->trackRowTotals)->toBeFalse()
        ->and($captured[4])->toBeInstanceOf(StepRunObserver::class)
        ->and($out)->toContain("    users ✗\n    orders ...\n    orders (2 rows) ✓\n      ── timing summary ──\n")
        ->and(substr_count($out, 'timing summary'))->toBe(1);
});

it('passes the null observer in CI, even at -vvv', function (): void {
    Storage::disk('local')->put('t.yaml', g1_yaml());
    $captured = [];
    g1_bind(run: function (array $a) use (&$captured): RunResultData {
        $captured = $a;

        return g1_result();
    });

    g1_call(['file' => 't.yaml', '--target' => 'staging', '--ci' => true, '-vvv' => true]);

    expect($captured[4])->toBeInstanceOf(NullCloningRunObserver::class);
});

it('keeps logging to stderr when stderr is not the terminal the bars run on', function (): void {
    Storage::disk('local')->put('t.yaml', g1_yaml());
    $stream = fopen('php://memory', 'w+');
    $levelDuringRun = null;
    g1_bind(run: function (array $a) use (&$levelDuringRun): RunResultData {
        $a[4]->started(1);
        $levelDuringRun = g1_stderr_handler()->getLevel();
        $a[4]->tableStarted('users');
        $a[4]->tableFinished('users', TableRunStatus::Transferred, 3, 0, []);
        $a[4]->finished();

        return g1_result();
    });

    $code = g1_run_on(g1_console($stream, stderrDecorated: false));

    expect($code)->toBe(0)
        ->and($levelDuringRun)->not->toBe(Level::Emergency)
        ->and(implode("\n", g1_screen($stream)))->toContain('tables  [');
});

it('clears the bars and hands logging back when the transfer throws', function (): void {
    Storage::disk('local')->put('t.yaml', g1_yaml());
    $stream = fopen('php://memory', 'w+');
    $levelBefore = g1_stderr_handler()->getLevel();
    g1_bind(run: function (array $a): RunResultData {
        try {
            $a[4]->started(2);
            $a[4]->tableStarted('users');
            expect(g1_stderr_handler()->getLevel())->toBe(Level::Emergency);

            throw new RuntimeException('target connection lost');
        } finally {
            $a[4]->finished();
        }
    });

    expect(fn (): int => g1_run_on(g1_console($stream)))->toThrow(RuntimeException::class, 'target connection lost');

    Log::warning('after_failure');

    $screen = implode("\n", g1_screen($stream));
    expect(g1_stderr_handler()->getLevel())->toBe($levelBefore)
        ->and($screen)->toContain('  users  stopped after 0 rows')
        ->and($screen)->toContain('  tables  stopped at 0/2')
        ->and($screen)->not->toContain('after_failure');
});

it('shows where the run stopped when it ends before every table is done', function (): void {
    Storage::disk('local')->put('t.yaml', g1_yaml());
    $stream = fopen('php://memory', 'w+');
    g1_bind(run: function (array $a): RunResultData {
        $a[4]->started(3);
        $a[4]->tableStarted('users');
        $a[4]->tableFinished('users', TableRunStatus::Failed, 0, 0, []); // --break-on-failure stops here
        $a[4]->finished();

        return g1_result(success: false);
    });

    g1_run_on(g1_console($stream), ['--break-on-failure' => true]);

    $screen = implode("\n", g1_screen($stream));
    expect($screen)->toContain('  ✗  users  — transfer failed')
        ->and($screen)->toContain('  users  stopped after 0 rows')
        ->and($screen)->toContain('  tables  stopped at 1/3')
        ->and($screen)->not->toContain('tables  [');
});
