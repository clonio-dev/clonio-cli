<?php

declare(strict_types=1);

use App\Commands\Cloning\RunCommand;
use App\Data\Cloning\KeyRemappingConfigData;
use App\Data\Cloning\RunResultData;
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
use App\Services\Cloning\SkippedRow;
use App\Services\Config\ConfigService;
use App\Services\Database\DatabaseConnectionService;
use App\Services\Schema\SchemaInspector;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

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
        ->and($args[5])->toBe([])
        ->and($args[6])->toBe(['users', 'orders']);
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
        ->and($args[5])->toBe(['users', 'orders'])
        ->and($args[6])->toBe([]);
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

it('casts non-string table option values to strings', function (string $option, int $index): void {
    Storage::disk('local')->put('t.yaml', g1_yaml());
    $args = null;
    g1_bind(run: function (array $a) use (&$args) {
        $args = $a;

        return g1_result();
    });

    [$code] = g1_call(['file' => 't.yaml', '--target' => 'staging', '--ci' => true, $option => 7]);

    expect($code)->toBe(0)->and($args[$index])->toBe(['7']);
})->with([
    'skip' => ['--skip-tables', 5],
    'only' => ['--only-tables', 6],
]);

it('merges YAML skip entries with --skip-tables without duplicates', function (): void {
    Storage::disk('local')->put('t.yaml', g1_yaml()."skip:\n  - orders\n  - logs\n");
    $args = null;
    g1_bind(run: function (array $a) use (&$args) {
        $args = $a;

        return g1_result();
    });

    [$code] = g1_call(['file' => 't.yaml', '--target' => 'staging', '--ci' => true, '--skip-tables' => 'orders']);

    expect($code)->toBe(0)->and($args[5])->toBe(['orders', 'logs']);
});

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
    chmod($dir, 0555);

    try {
        chdir($dir);
        $cwd = (string) getcwd();
        [$code, $out] = g1_call(['file' => 't.yaml', '--target' => 'dump']);
    } finally {
        chdir($previous);
        chmod($dir, 0755);
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

it('passes boolean flags and no table-start callback in normal mode', function (array $flags, bool $expected): void {
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
        ->and($args[4])->toBe($expected)
        ->and($args[9])->toBe($expected)
        ->and($args[10])->toBeNull()
        ->and($args[11])->toBeNull();
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
            $a[7]('t'.$i, TableRunStatus::Transferred, 1, 0, []);
        }

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
        $a[10]('users');
        $a[7]('users', TableRunStatus::Transferred, 1234, 0, []);
        $a[10]('orders');
        $a[7]('orders', TableRunStatus::Transferred, 1234, 1, [new SkippedRow('orders', 0, 0, null, 'dup')]);
        $a[10]('items');
        $rows = [new SkippedRow('items', 0, 0, null, 'A')];
        foreach (range('B', 'K') as $msg) {
            $rows[] = new SkippedRow('items', 0, 0, null, $msg);
            $rows[] = new SkippedRow('items', 0, 1, null, $msg);
        }
        $a[7]('items', TableRunStatus::Failed, 0, 0, $rows);
        $a[10]('bad');
        $a[7]('bad', TableRunStatus::Failed, 0, 0, []);
        $a[7]('ghost', TableRunStatus::NotFound, 0, 0, []);
        $a[7]('broken', TableRunStatus::SkippedBySchemaFailure, 0, 0, []);

        return g1_result();
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
        .g1_summary()
    );
});

it('renders normal-mode indicators and the run summary', function (): void {
    Storage::disk('local')->put('t.yaml', g1_yaml());
    g1_bind(run: function (array $a): RunResultData {
        $a[7]('users', TableRunStatus::Transferred, 5, 0, []);
        $a[7]('orders', TableRunStatus::Transferred, 5, 1, []);
        $a[7]('items', TableRunStatus::Failed, 0, 0, []);
        $a[7]('ghost', TableRunStatus::NotFound, 0, 0, []);
        $a[7]('broken', TableRunStatus::SkippedBySchemaFailure, 0, 0, []);
        $a[7]('flagged', TableRunStatus::SkippedByFlag, 0, 0, []);

        return g1_result([
            g1_tr('users', notes: ['n1', 'n2']),
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
        .g1_summary(2, 6, 10, '60s')
    );
});

it('deduplicates tables reported by progress and result', function (): void {
    Storage::disk('local')->put('t.yaml', g1_yaml());
    g1_bind(run: function (array $a): RunResultData {
        $a[7]('ghost', TableRunStatus::NotFound, 0, 0, []);
        $a[7]('broken', TableRunStatus::SkippedBySchemaFailure, 0, 0, []);

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
        $a[7]('flagged', TableRunStatus::SkippedByFlag, 0, 0, []);

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

/**
 * @param  array<string, list<object>|Throwable>  $selects  SQL => rows
 */
function g1_db(array $selects, int $purges): void
{
    $conn = Mockery::mock();
    foreach ($selects as $sql => $rows) {
        $exp = $conn->shouldReceive('select')->once()->with($sql);
        $rows instanceof Throwable ? $exp->andThrow($rows) : $exp->andReturn($rows);
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
    g1_db([
        'SELECT COUNT(*) as count FROM `users`' => [(object) ['count' => '5']],
        'SELECT COUNT(*) as count FROM `orders`' => [(object) ['count' => 3]],
        'SELECT COUNT(*) as count FROM `logs`' => [(object) ['count' => 7]],
        'SELECT COUNT(*) as count FROM `items`' => [(object) ['count' => 4], (object) ['count' => 99]],
    ], 6);

    [$code, $out] = g1_call(['file' => 't.yaml', '--target' => 'staging', '--dry-run' => true]);

    expect($code)->toBe(0)->and($out)->toBe(
        "\n  Dry-run: production-db\n\n"
        .g1_row('Table', 'Rows (est.)', 'Strategy', 'Transformations')
        .g1_sep()
        .g1_row('users', '2', 'first 2', 'email, phone')
        .g1_row('orders', '3', 'last 10', '—')
        .g1_row('logs', '0', 'schema', '—')
        .g1_row('items', '4', 'full', '—')
        .g1_row('ghost', 'NOT FOUND', '—', '—')
        .g1_sep()
        ."  Total                    9 rows across 5 tables, 1 not found, will be skipped\n"
        ."\n  No data will be transferred. Run without --dry-run to execute.\n\n"
    );
});

it('quotes the table name per source driver when counting rows', function (DatabaseConnectionType $type, string $quoted): void {
    Storage::disk('local')->put('t.yaml', g1_yaml("  users:\n    rows:\n      strategy: full\n"));
    g1_bind(connections: [
        'production-db' => g1_conn('production-db', $type, production: true),
        'staging' => g1_conn('staging'),
    ]);
    g1_db(['SELECT COUNT(*) as count FROM '.$quoted => [(object) ['count' => 1500]]], 3);

    [$code, $out] = g1_call(['file' => 't.yaml', '--target' => 'staging', '--dry-run' => true]);

    expect($code)->toBe(0)->and($out)->toBe(
        "\n  Dry-run: production-db\n\n"
        ."  Schema diff: target matches source\n\n"
        .g1_row('Table', 'Rows (est.)', 'Strategy', 'Transformations')
        .g1_sep()
        .g1_row('users', '1,500', 'full', '—')
        .g1_sep()
        ."  Total                    1,500 rows across 1 tables\n"
        ."\n  No data will be transferred. Run without --dry-run to execute.\n\n"
    );
})->with([
    'mysql' => [DatabaseConnectionType::Mysql, '`users`'],
    'mariadb' => [DatabaseConnectionType::MariaDB, '`users`'],
    'sqlsrv' => [DatabaseConnectionType::SqlServer, '[users]'],
    'pgsql' => [DatabaseConnectionType::PostgreSQL, '"users"'],
    'sqlite' => [DatabaseConnectionType::Sqlite, '"users"'],
]);

it('falls back when the row count result is unusable', function (array|Throwable $rows, string $label): void {
    Storage::disk('local')->put('t.yaml', g1_yaml("  users:\n    rows:\n      strategy: full\n"));
    g1_bind();
    g1_db(['SELECT COUNT(*) as count FROM `users`' => $rows], 3);

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
    'no rows' => [[], '?'],
    'missing count column' => [[(object) ['other' => 3]], '0'],
    'non-numeric count' => [[(object) ['count' => 'abc']], '0'],
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
    g1_db(['SELECT COUNT(*) as count FROM `users`' => [(object) ['count' => 2000]]], 3);

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
        .g1_row('users', '1,500', 'last 1,500', '—')
        .g1_sep()
        ."  Total                    1,500 rows across 1 tables\n"
        ."\n  No data will be transferred. Run without --dry-run to execute.\n\n"
    );
});

it('prints nothing for a dry run in CI mode', function (): void {
    Storage::disk('local')->put('t.yaml', g1_yaml());
    g1_bind();
    g1_db(['SELECT COUNT(*) as count FROM `users`' => [(object) ['count' => 1]]], 3);

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

it('suggests --no-memory-limit with the full original command line', function (): void {
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
        '--skip-tables' => 'a,b', '--audit-channel' => 'x', '--remap-keys' => true, '--file-based' => true,
        '--enforce-column-types' => true, '--no-enforce-column-types' => true, '--drop-unknown-tables' => true,
        '--no-drop-unknown-tables' => true, '--drop-extra-columns' => true, '--no-drop-extra-columns' => true,
        '--recreate-tables' => true, '--no-recreate-tables' => true, '--disable-fk-checks' => true,
        '--no-disable-fk-checks' => true, '--break-on-failure' => true,
    ]);

    expect($code)->toBe(1)->and($out)->toBe(
        "Memory exhausted during key mapping generation: Allowed memory size of 1 bytes exhausted\n\n"
        ."Hint: Re-run with --no-memory-limit to remove the PHP memory limit:\n\n"
        .'    clonio cloning:run t.yaml --target=staging --allow-failure --ci --skip-schema --skip-tables=a,b'
        .' --audit-channel=x --remap-keys --file-based --enforce-column-types --no-enforce-column-types'
        .' --drop-unknown-tables --no-drop-unknown-tables --drop-extra-columns --no-drop-extra-columns'
        .' --recreate-tables --no-recreate-tables --disable-fk-checks --no-disable-fk-checks --break-on-failure'
        ." --no-memory-limit\n\n"
    );
});

it('omits empty value options from the memory hint', function (): void {
    Storage::disk('local')->put('t.yaml', g1_remap_yaml());
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

it('collects not-found and schema-failure tables reported during progress in CI mode', function (): void {
    Storage::disk('local')->put('t.yaml', g1_yaml());
    g1_bind(run: function (array $a): RunResultData {
        $a[7]('users', TableRunStatus::Transferred, 5, 0, []);
        $a[7]('ghost', TableRunStatus::NotFound, 0, 0, []);
        $a[7]('broken', TableRunStatus::SkippedBySchemaFailure, 0, 0, []);
        $a[7]('items', TableRunStatus::Failed, 0, 0, []);

        return g1_result([g1_tr('users')], rows: 5);
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
    g1_db(['SELECT COUNT(*) as count FROM `users`' => [(object) ['count' => 5]]], 3);

    [$code, $out] = g1_call(['file' => 't.yaml', '--target' => 'staging', '--dry-run' => true]);

    expect($code)->toBe(0)->and($out)->toBe(
        "\n  Dry-run: production-db\n\n"
        ."  Schema diff: target matches source\n\n"
        .g1_row('Table', 'Rows (est.)', 'Strategy', 'Transformations')
        .g1_sep()
        .g1_row('users', '5', 'full', '—')
        .g1_sep()
        ."  Total                    5 rows across 1 tables\n"
        ."\n  No data will be transferred. Run without --dry-run to execute.\n\n"
    );
});
