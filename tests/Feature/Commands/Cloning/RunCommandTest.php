<?php

declare(strict_types=1);

use App\Data\Cloning\CloningConfigData;
use App\Data\Cloning\CloningOptionsData;
use App\Data\Cloning\RunResultData;
use App\Data\Cloning\TableCloningConfigData;
use App\Data\Cloning\TableRowConfigData;
use App\Data\Cloning\TableRunResultData;
use App\Data\Cloning\TableRunStatus;
use App\Data\ConnectionData;
use App\Data\Schema\ColumnSchemaData;
use App\Data\Schema\DatabaseSchemaData;
use App\Data\Schema\TableSchemaData;
use App\Enums\DatabaseConnectionType;
use App\Enums\ExitCode;
use App\Exceptions\KeyRemappingExhaustedException;
use App\Services\Cloning\CloningRunOrchestrator;
use App\Services\Cloning\KeyRemappingService;
use App\Services\Cloning\SkippedRow;
use App\Services\Config\ConfigService;
use App\Services\Database\DatabaseConnectionService;
use App\Services\Schema\SchemaInspector;
use Illuminate\Support\Facades\Storage;

function makeRunMysqlConnection(string $name = 'production-db'): ConnectionData
{
    return new ConnectionData(
        name: $name,
        type: DatabaseConnectionType::Mysql,
        host: 'db.prod.io',
        port: 3306,
        database: 'mydb',
        schema: null,
        username: 'root',
        password: 'secret',
        isProduction: true,
    );
}

function makeRunTargetConnection(string $name = 'staging'): ConnectionData
{
    return new ConnectionData(
        name: $name,
        type: DatabaseConnectionType::Mysql,
        host: 'db.staging.io',
        port: 3306,
        database: 'stagingdb',
        schema: null,
        username: 'root',
        password: 'secret',
        isProduction: false,
    );
}

function makeRunSimpleSchema(): DatabaseSchemaData
{
    return new DatabaseSchemaData(
        databaseName: 'mydb',
        tables: [
            new TableSchemaData(
                name: 'users',
                columns: [
                    new ColumnSchemaData(name: 'id', type: 'int', nullable: false, default: null, isPrimary: true),
                    new ColumnSchemaData(name: 'email', type: 'varchar', nullable: false, default: null, isPrimary: false),
                ],
                foreignKeys: [],
            ),
        ],
    );
}

function makeRunCloningConfig(): CloningConfigData
{
    return new CloningConfigData(
        version: '1',
        connectionName: 'production-db',
        options: new CloningOptionsData(
            chunkSize: 1000,
            enforceColumnTypes: false,
            dropUnknownTables: false,
            dropExtraColumns: false,
            disableForeignKeyChecks: true,
            fakerLocale: 'en_US',
        ),
        tables: [
            new TableCloningConfigData(
                tableName: 'users',
                rows: new TableRowConfigData(strategy: 'full', limit: null, sortBy: null),
                columns: [],
            ),
        ],
    );
}

function makeRunResult(): RunResultData
{
    return new RunResultData(
        success: true,
        tables: [
            new TableRunResultData(
                tableName: 'users',
                status: TableRunStatus::Transferred,
                rowsTransferred: 100,
                rowsSkipped: 0,
                durationSeconds: 0.5,
                failureReason: null,
            ),
        ],
        totalRows: 100,
        skippedRows: 0,
        durationSeconds: 0.6,
        failureReason: null,
    );
}

it('returns IoError(5) when YAML file does not exist', function (): void {
    $this->artisan('cloning:run', ['file' => '/nonexistent/path/missing.yaml', '--ci' => true])
        ->expectsOutputToContain('File not found')
        ->assertExitCode(ExitCode::IoError->value);
});

it('returns ValidationError(4) when YAML is invalid', function (): void {
    Storage::fake('local');
    Storage::disk('local')->put('bad.yaml', "invalid: yaml\n  badly: [nested");

    $config = Mockery::mock(ConfigService::class);
    $this->app->instance(ConfigService::class, $config);

    $this->artisan('cloning:run', ['file' => 'bad.yaml', '--ci' => true])
        ->assertExitCode(ExitCode::ValidationError->value);
});

it('returns ValidationError(4) when --skip-tables and --only-tables are both provided', function (): void {
    Storage::fake('local');
    // Write a valid YAML
    $yaml = <<<'YAML'
version: "1"
connection: production-db
options:
  chunk_size: 1000
  enforce_column_types: false
  drop_unknown_tables: false
  disable_foreign_key_checks: true
  faker_locale: en_US
tables:
  users:
    rows:
      strategy: full
YAML;
    Storage::disk('local')->put('test.cloning.yaml', $yaml);

    $config = Mockery::mock(ConfigService::class);
    $config->shouldReceive('getConnection')->with('production-db')->andReturn(makeRunMysqlConnection());
    $this->app->instance(ConfigService::class, $config);

    $connector = Mockery::mock(DatabaseConnectionService::class);
    $connector->shouldReceive('resolvePassword')->andReturn('secret');
    $connector->shouldReceive('buildConfig')->andReturn([]);
    $connector->shouldReceive('open')->andReturn('test_conn');
    $this->app->instance(DatabaseConnectionService::class, $connector);

    $this->artisan('cloning:run', [
        'file' => 'test.cloning.yaml',
        '--skip-tables' => 'users',
        '--only-tables' => 'orders',
        '--ci' => true,
    ])
        ->expectsOutputToContain('mutually exclusive')
        ->assertExitCode(ExitCode::ValidationError->value);
});

it('returns ValidationError(4) when --ci is used without --target', function (): void {
    Storage::fake('local');
    $yaml = <<<'YAML'
version: "1"
connection: production-db
options:
  chunk_size: 1000
  enforce_column_types: false
  drop_unknown_tables: false
  disable_foreign_key_checks: true
  faker_locale: en_US
tables:
  users:
    rows:
      strategy: full
YAML;
    Storage::disk('local')->put('test.cloning.yaml', $yaml);

    $config = Mockery::mock(ConfigService::class);
    $config->shouldReceive('getConnection')->with('production-db')->andReturn(makeRunMysqlConnection());
    $this->app->instance(ConfigService::class, $config);

    $connector = Mockery::mock(DatabaseConnectionService::class);
    $connector->shouldReceive('open')->andReturn('test_conn');
    $this->app->instance(DatabaseConnectionService::class, $connector);

    $this->artisan('cloning:run', [
        'file' => 'test.cloning.yaml',
        '--ci' => true,
    ])
        ->expectsOutputToContain('Target required in CI mode')
        ->assertExitCode(ExitCode::ValidationError->value);
});

function makeRunDumpConnection(string $name = 'dump-target', ?DatabaseConnectionType $dialect = DatabaseConnectionType::PostgreSQL): ConnectionData
{
    return new ConnectionData(
        name: $name, type: DatabaseConnectionType::Dump, host: null, port: null,
        database: null, schema: null, username: null, password: '', isProduction: false, dialect: $dialect,
    );
}

function dumpRunYaml(): string
{
    return <<<'YAML'
version: "1"
connection: production-db
options:
  chunk_size: 1000
  enforce_column_types: false
  drop_unknown_tables: false
  disable_foreign_key_checks: true
  faker_locale: en_US
tables:
  users:
    rows:
      strategy: full
YAML;
}

it('returns ValidationError(4) when a dump target has no dialect', function (): void {
    Storage::fake('local');
    Storage::disk('local')->put('test.cloning.yaml', dumpRunYaml());

    $config = Mockery::mock(ConfigService::class);
    $config->shouldReceive('getConnection')->with('production-db')->andReturn(makeRunMysqlConnection());
    $config->shouldReceive('getConnection')->with('dump-target')->andReturn(makeRunDumpConnection('dump-target', null));
    $this->app->instance(ConfigService::class, $config);

    $this->artisan('cloning:run', ['file' => 'test.cloning.yaml', '--target' => 'dump-target', '--ci' => true])
        ->expectsOutputToContain('has no valid dialect')
        ->assertExitCode(ExitCode::ValidationError->value);
});

it('returns ValidationError(4) when the source connection is itself a dump', function (): void {
    Storage::fake('local');
    Storage::disk('local')->put('test.cloning.yaml', dumpRunYaml());

    $config = Mockery::mock(ConfigService::class);
    $config->shouldReceive('getConnection')->with('production-db')->andReturn(makeRunDumpConnection('production-db'));
    $config->shouldReceive('getConnection')->with('dump-target')->andReturn(makeRunDumpConnection('dump-target'));
    $this->app->instance(ConfigService::class, $config);

    $this->artisan('cloning:run', ['file' => 'test.cloning.yaml', '--target' => 'dump-target', '--ci' => true])
        ->expectsOutputToContain('Source connection must be a real database')
        ->assertExitCode(ExitCode::ValidationError->value);
});

it('returns ConnectionError(3) when source connection is unknown', function (): void {
    Storage::fake('local');
    $yaml = <<<'YAML'
version: "1"
connection: unknown-source
options:
  chunk_size: 1000
  enforce_column_types: false
  drop_unknown_tables: false
  disable_foreign_key_checks: true
  faker_locale: en_US
tables:
  users:
    rows:
      strategy: full
YAML;
    Storage::disk('local')->put('test.cloning.yaml', $yaml);

    $config = Mockery::mock(ConfigService::class);
    $config->shouldReceive('getConnection')->with('unknown-source')->andReturn(null);
    $this->app->instance(ConfigService::class, $config);

    $this->artisan('cloning:run', [
        'file' => 'test.cloning.yaml',
        '--target' => 'staging',
        '--ci' => true,
    ])
        ->expectsOutputToContain('not found')
        ->assertExitCode(ExitCode::ConnectionError->value);
});

it('returns ConnectionError(3) when target connection is unknown', function (): void {
    Storage::fake('local');
    $yaml = <<<'YAML'
version: "1"
connection: production-db
options:
  chunk_size: 1000
  enforce_column_types: false
  drop_unknown_tables: false
  disable_foreign_key_checks: true
  faker_locale: en_US
tables:
  users:
    rows:
      strategy: full
YAML;
    Storage::disk('local')->put('test.cloning.yaml', $yaml);

    $config = Mockery::mock(ConfigService::class);
    $config->shouldReceive('getConnection')->with('production-db')->andReturn(makeRunMysqlConnection());
    $config->shouldReceive('getConnection')->with('unknown-target')->andReturn(null);
    $this->app->instance(ConfigService::class, $config);

    $connector = Mockery::mock(DatabaseConnectionService::class);
    $connector->shouldReceive('open')->andReturn('test_conn');
    $this->app->instance(DatabaseConnectionService::class, $connector);

    $this->artisan('cloning:run', [
        'file' => 'test.cloning.yaml',
        '--target' => 'unknown-target',
        '--ci' => true,
    ])
        ->expectsOutputToContain('not found')
        ->assertExitCode(ExitCode::ConnectionError->value);
});

it('returns ValidationError(4) when source and target are the same connection', function (): void {
    Storage::fake('local');
    $yaml = <<<'YAML'
version: "1"
connection: production-db
options:
  chunk_size: 1000
  enforce_column_types: false
  drop_unknown_tables: false
  disable_foreign_key_checks: true
  faker_locale: en_US
tables:
  users:
    rows:
      strategy: full
YAML;
    Storage::disk('local')->put('test.cloning.yaml', $yaml);

    $config = Mockery::mock(ConfigService::class);
    $config->shouldReceive('getConnection')->with('production-db')->andReturn(makeRunMysqlConnection());
    $this->app->instance(ConfigService::class, $config);

    $connector = Mockery::mock(DatabaseConnectionService::class);
    $connector->shouldReceive('open')->andReturn('test_conn');
    $this->app->instance(DatabaseConnectionService::class, $connector);

    $this->artisan('cloning:run', [
        'file' => 'test.cloning.yaml',
        '--target' => 'production-db',
        '--ci' => true,
    ])
        ->expectsOutputToContain('cannot be the same')
        ->assertExitCode(ExitCode::ValidationError->value);
});

it('excludes production connections from interactive target selection', function (): void {
    Storage::fake('local');
    $yaml = <<<'YAML'
version: "1"
connection: production-db
options:
  chunk_size: 1000
  enforce_column_types: false
  drop_unknown_tables: false
  disable_foreign_key_checks: true
  faker_locale: en_US
tables:
  users:
    rows:
      strategy: full
YAML;
    Storage::disk('local')->put('test.cloning.yaml', $yaml);

    $prodConnection = makeRunMysqlConnection('production-db');
    $prod2Connection = new ConnectionData(
        name: 'other-prod',
        type: DatabaseConnectionType::Mysql,
        host: 'db.prod2.io',
        port: 3306,
        database: 'prod2db',
        schema: null,
        username: 'root',
        password: 'secret',
        isProduction: true,
    );
    $stagingConnection = makeRunTargetConnection('staging');

    $config = Mockery::mock(ConfigService::class);
    $config->shouldReceive('getConnection')->with('production-db')->andReturn($prodConnection);
    $config->shouldReceive('getConnection')->with('staging')->andReturn($stagingConnection);
    $config->shouldReceive('getConnections')->andReturn([
        'production-db' => $prodConnection,
        'other-prod' => $prod2Connection,
        'staging' => $stagingConnection,
    ]);
    $config->shouldReceive('load')->andReturn(['connections' => []]);
    $this->app->instance(ConfigService::class, $config);

    $connector = Mockery::mock(DatabaseConnectionService::class);
    $connector->shouldReceive('open')->andReturn('test_conn');
    $this->app->instance(DatabaseConnectionService::class, $connector);

    $inspector = Mockery::mock(SchemaInspector::class);
    $inspector->shouldReceive('inspect')->andReturn(makeRunSimpleSchema());
    $this->app->instance(SchemaInspector::class, $inspector);

    $orchestrator = Mockery::mock(CloningRunOrchestrator::class);
    $orchestrator->shouldReceive('run')->andReturn(makeRunResult());
    $this->app->instance(CloningRunOrchestrator::class, $orchestrator);

    // Interactive selection should only show 'staging', not 'other-prod'
    $this->artisan('cloning:run', ['file' => 'test.cloning.yaml'])
        ->expectsChoice('Select a target connection', 'staging', ['staging'])
        ->assertExitCode(ExitCode::Success->value);
});

it('exits 0 on dry-run with mocked schema inspector', function (): void {
    Storage::fake('local');
    $yaml = <<<'YAML'
version: "1"
connection: production-db
options:
  chunk_size: 1000
  enforce_column_types: false
  drop_unknown_tables: false
  disable_foreign_key_checks: true
  faker_locale: en_US
tables:
  users:
    rows:
      strategy: full
YAML;
    Storage::disk('local')->put('test.cloning.yaml', $yaml);

    $config = Mockery::mock(ConfigService::class);
    $config->shouldReceive('getConnection')->with('production-db')->andReturn(makeRunMysqlConnection());
    $config->shouldReceive('getConnection')->with('staging')->andReturn(makeRunTargetConnection());
    $this->app->instance(ConfigService::class, $config);

    $connector = Mockery::mock(DatabaseConnectionService::class);
    $connector->shouldReceive('open')->andReturn('test_conn');
    $this->app->instance(DatabaseConnectionService::class, $connector);

    $inspector = Mockery::mock(SchemaInspector::class);
    $inspector->shouldReceive('inspect')->andReturn(makeRunSimpleSchema());
    $this->app->instance(SchemaInspector::class, $inspector);

    $this->artisan('cloning:run', [
        'file' => 'test.cloning.yaml',
        '--target' => 'staging',
        '--dry-run' => true,
    ])
        ->assertExitCode(ExitCode::Success->value);
});

it('exits 0 on happy path with mocked orchestrator', function (): void {
    Storage::fake('local');
    $yaml = <<<'YAML'
version: "1"
connection: production-db
options:
  chunk_size: 1000
  enforce_column_types: false
  drop_unknown_tables: false
  disable_foreign_key_checks: true
  faker_locale: en_US
tables:
  users:
    rows:
      strategy: full
YAML;
    Storage::disk('local')->put('test.cloning.yaml', $yaml);

    $config = Mockery::mock(ConfigService::class);
    $config->shouldReceive('getConnection')->with('production-db')->andReturn(makeRunMysqlConnection());
    $config->shouldReceive('getConnection')->with('staging')->andReturn(makeRunTargetConnection());
    $config->shouldReceive('load')->andReturn(['connections' => []]);
    $this->app->instance(ConfigService::class, $config);

    $connector = Mockery::mock(DatabaseConnectionService::class);
    $connector->shouldReceive('open')->andReturn('test_conn');
    $this->app->instance(DatabaseConnectionService::class, $connector);

    $inspector = Mockery::mock(SchemaInspector::class);
    $inspector->shouldReceive('inspect')->andReturn(makeRunSimpleSchema());
    $this->app->instance(SchemaInspector::class, $inspector);

    $orchestrator = Mockery::mock(CloningRunOrchestrator::class);
    $orchestrator->shouldReceive('run')->andReturn(makeRunResult());
    $this->app->instance(CloningRunOrchestrator::class, $orchestrator);

    $this->artisan('cloning:run', [
        'file' => 'test.cloning.yaml',
        '--target' => 'staging',
        '--ci' => true,
    ])
        ->assertExitCode(ExitCode::Success->value);
});

it('returns GeneralError(1) when run fails without --allow-failure', function (): void {
    Storage::fake('local');
    $yaml = <<<'YAML'
version: "1"
connection: production-db
options:
  chunk_size: 1000
  enforce_column_types: false
  drop_unknown_tables: false
  disable_foreign_key_checks: true
  faker_locale: en_US
tables:
  users:
    rows:
      strategy: full
YAML;
    Storage::disk('local')->put('test.cloning.yaml', $yaml);

    $config = Mockery::mock(ConfigService::class);
    $config->shouldReceive('getConnection')->with('production-db')->andReturn(makeRunMysqlConnection());
    $config->shouldReceive('getConnection')->with('staging')->andReturn(makeRunTargetConnection());
    $config->shouldReceive('load')->andReturn(['connections' => []]);
    $this->app->instance(ConfigService::class, $config);

    $connector = Mockery::mock(DatabaseConnectionService::class);
    $connector->shouldReceive('open')->andReturn('test_conn');
    $this->app->instance(DatabaseConnectionService::class, $connector);

    $inspector = Mockery::mock(SchemaInspector::class);
    $inspector->shouldReceive('inspect')->andReturn(makeRunSimpleSchema());
    $this->app->instance(SchemaInspector::class, $inspector);

    $failedResult = new RunResultData(
        success: false,
        tables: [
            new TableRunResultData('users', TableRunStatus::Failed, 0, 0, 0.1, 'Connection refused'),
        ],
        totalRows: 0,
        skippedRows: 0,
        durationSeconds: 0.1,
        failureReason: 'One or more tables failed to transfer',
    );

    $orchestrator = Mockery::mock(CloningRunOrchestrator::class);
    $orchestrator->shouldReceive('run')->andReturn($failedResult);
    $this->app->instance(CloningRunOrchestrator::class, $orchestrator);

    $this->artisan('cloning:run', [
        'file' => 'test.cloning.yaml',
        '--target' => 'staging',
        '--ci' => true,
    ])
        ->assertExitCode(ExitCode::GeneralError->value);
});

it('returns Success(0) when run fails with --allow-failure', function (): void {
    Storage::fake('local');
    $yaml = <<<'YAML'
version: "1"
connection: production-db
options:
  chunk_size: 1000
  enforce_column_types: false
  drop_unknown_tables: false
  disable_foreign_key_checks: true
  faker_locale: en_US
tables:
  users:
    rows:
      strategy: full
YAML;
    Storage::disk('local')->put('test.cloning.yaml', $yaml);

    $config = Mockery::mock(ConfigService::class);
    $config->shouldReceive('getConnection')->with('production-db')->andReturn(makeRunMysqlConnection());
    $config->shouldReceive('getConnection')->with('staging')->andReturn(makeRunTargetConnection());
    $config->shouldReceive('load')->andReturn(['connections' => []]);
    $this->app->instance(ConfigService::class, $config);

    $connector = Mockery::mock(DatabaseConnectionService::class);
    $connector->shouldReceive('open')->andReturn('test_conn');
    $this->app->instance(DatabaseConnectionService::class, $connector);

    $inspector = Mockery::mock(SchemaInspector::class);
    $inspector->shouldReceive('inspect')->andReturn(makeRunSimpleSchema());
    $this->app->instance(SchemaInspector::class, $inspector);

    $failedResult = new RunResultData(
        success: false,
        tables: [
            new TableRunResultData('users', TableRunStatus::Failed, 0, 0, 0.1, 'Connection refused'),
        ],
        totalRows: 0,
        skippedRows: 0,
        durationSeconds: 0.1,
        failureReason: 'One or more tables failed to transfer',
    );

    $orchestrator = Mockery::mock(CloningRunOrchestrator::class);
    $orchestrator->shouldReceive('run')->andReturn($failedResult);
    $this->app->instance(CloningRunOrchestrator::class, $orchestrator);

    $this->artisan('cloning:run', [
        'file' => 'test.cloning.yaml',
        '--target' => 'staging',
        '--allow-failure' => true,
        '--ci' => true,
    ])
        ->assertExitCode(ExitCode::Success->value);
});

it('handles not-found tables in result', function (): void {
    Storage::fake('local');
    $yaml = <<<'YAML'
version: "1"
connection: production-db
options:
  chunk_size: 1000
  enforce_column_types: false
  drop_unknown_tables: false
  disable_foreign_key_checks: true
  faker_locale: en_US
tables:
  users:
    rows:
      strategy: full
  missing_table:
    rows:
      strategy: full
YAML;
    Storage::disk('local')->put('test.cloning.yaml', $yaml);

    $config = Mockery::mock(ConfigService::class);
    $config->shouldReceive('getConnection')->with('production-db')->andReturn(makeRunMysqlConnection());
    $config->shouldReceive('getConnection')->with('staging')->andReturn(makeRunTargetConnection());
    $config->shouldReceive('load')->andReturn(['connections' => []]);
    $this->app->instance(ConfigService::class, $config);

    $connector = Mockery::mock(DatabaseConnectionService::class);
    $connector->shouldReceive('open')->andReturn('test_conn');
    $this->app->instance(DatabaseConnectionService::class, $connector);

    $inspector = Mockery::mock(SchemaInspector::class);
    $inspector->shouldReceive('inspect')->andReturn(makeRunSimpleSchema());
    $this->app->instance(SchemaInspector::class, $inspector);

    $resultWithNotFound = new RunResultData(
        success: true,
        tables: [
            new TableRunResultData('users', TableRunStatus::Transferred, 100, 0, 0.1, null),
            new TableRunResultData('missing_table', TableRunStatus::NotFound, 0, 0, 0.0, null),
        ],
        totalRows: 100,
        skippedRows: 0,
        durationSeconds: 0.2,
        failureReason: null,
    );

    $orchestrator = Mockery::mock(CloningRunOrchestrator::class);
    $orchestrator->shouldReceive('run')->andReturn($resultWithNotFound);
    $this->app->instance(CloningRunOrchestrator::class, $orchestrator);

    $this->artisan('cloning:run', [
        'file' => 'test.cloning.yaml',
        '--target' => 'staging',
    ])
        ->expectsOutputToContain('Warning')
        ->assertExitCode(ExitCode::Success->value);
});

it('shows connection error when source cannot connect', function (): void {
    Storage::fake('local');
    $yaml = <<<'YAML'
version: "1"
connection: production-db
options:
  chunk_size: 1000
  enforce_column_types: false
  drop_unknown_tables: false
  disable_foreign_key_checks: true
  faker_locale: en_US
tables:
  users:
    rows:
      strategy: full
YAML;
    Storage::disk('local')->put('test.cloning.yaml', $yaml);

    $config = Mockery::mock(ConfigService::class);
    $config->shouldReceive('getConnection')->with('production-db')->andReturn(makeRunMysqlConnection());
    $config->shouldReceive('getConnection')->with('staging')->andReturn(makeRunTargetConnection());
    $this->app->instance(ConfigService::class, $config);

    $connector = Mockery::mock(DatabaseConnectionService::class);
    $connector->shouldReceive('open')->with(Mockery::on(static fn ($c): bool => $c->name === 'production-db'))->andThrow(new RuntimeException('Connection refused'));
    $this->app->instance(DatabaseConnectionService::class, $connector);

    $this->artisan('cloning:run', [
        'file' => 'test.cloning.yaml',
        '--target' => 'staging',
        '--ci' => true,
    ])
        ->expectsOutputToContain('Cannot connect')
        ->assertExitCode(ExitCode::ConnectionError->value);
});

it('writes audit artefacts when audit config is present', function (): void {
    Storage::fake('local');
    $yaml = <<<'YAML'
version: "1"
connection: production-db
options:
  chunk_size: 1000
  enforce_column_types: false
  drop_unknown_tables: false
  disable_foreign_key_checks: true
  faker_locale: en_US
tables:
  users:
    rows:
      strategy: full
YAML;
    Storage::disk('local')->put('test.cloning.yaml', $yaml);

    $config = Mockery::mock(ConfigService::class);
    $config->shouldReceive('getConnection')->with('production-db')->andReturn(makeRunMysqlConnection());
    $config->shouldReceive('getConnection')->with('staging')->andReturn(makeRunTargetConnection());
    $config->shouldReceive('load')->andReturn([
        'connections' => [],
        'audit' => [
            'use' => ['local-main'],
            'channels' => [
                'local-main' => [
                    'type' => 'local',
                    'path' => 'clonio-logs',
                ],
            ],
        ],
    ]);
    $this->app->instance(ConfigService::class, $config);

    $connector = Mockery::mock(DatabaseConnectionService::class);
    $connector->shouldReceive('open')->andReturn('test_conn');
    $this->app->instance(DatabaseConnectionService::class, $connector);

    $inspector = Mockery::mock(SchemaInspector::class);
    $inspector->shouldReceive('inspect')->andReturn(makeRunSimpleSchema());
    $this->app->instance(SchemaInspector::class, $inspector);

    $orchestrator = Mockery::mock(CloningRunOrchestrator::class);
    $orchestrator->shouldReceive('run')->andReturn(makeRunResult());
    $this->app->instance(CloningRunOrchestrator::class, $orchestrator);

    $this->artisan('cloning:run', [
        'file' => 'test.cloning.yaml',
        '--target' => 'staging',
        '--ci' => true,
    ])
        ->assertExitCode(ExitCode::Success->value);

    // Check that audit files were written
    $files = Storage::disk('local')->allFiles('clonio-logs');
    expect($files)->not->toBeEmpty();
    expect($files[0])->toContain('_audit.html');
});

it('shows target connection error when target cannot connect', function (): void {
    Storage::fake('local');
    $yaml = <<<'YAML'
version: "1"
connection: production-db
options:
  chunk_size: 1000
  enforce_column_types: false
  drop_unknown_tables: false
  disable_foreign_key_checks: true
  faker_locale: en_US
tables:
  users:
    rows:
      strategy: full
YAML;
    Storage::disk('local')->put('test.cloning.yaml', $yaml);

    $config = Mockery::mock(ConfigService::class);
    $config->shouldReceive('getConnection')->with('production-db')->andReturn(makeRunMysqlConnection());
    $config->shouldReceive('getConnection')->with('staging')->andReturn(makeRunTargetConnection());
    $this->app->instance(ConfigService::class, $config);

    $connector = Mockery::mock(DatabaseConnectionService::class);
    // Source connects OK
    $connector->shouldReceive('open')->with(Mockery::on(static fn ($c): bool => $c->name === 'production-db'))->andReturn('test_conn');
    // Target fails
    $connector->shouldReceive('open')->with(Mockery::on(static fn ($c): bool => $c->name === 'staging'))->andThrow(new RuntimeException('Target connection refused'));
    $this->app->instance(DatabaseConnectionService::class, $connector);

    $this->artisan('cloning:run', [
        'file' => 'test.cloning.yaml',
        '--target' => 'staging',
        '--ci' => true,
    ])
        ->expectsOutputToContain('Cannot connect')
        ->assertExitCode(ExitCode::ConnectionError->value);
});

it('dry-run shows schema diff when target is missing tables', function (): void {
    Storage::fake('local');
    $yaml = <<<'YAML'
version: "1"
connection: production-db
options:
  chunk_size: 1000
  enforce_column_types: false
  drop_unknown_tables: false
  disable_foreign_key_checks: true
  faker_locale: en_US
tables:
  users:
    rows:
      strategy: full
YAML;
    Storage::disk('local')->put('test.cloning.yaml', $yaml);

    $config = Mockery::mock(ConfigService::class);
    $config->shouldReceive('getConnection')->with('production-db')->andReturn(makeRunMysqlConnection());
    $config->shouldReceive('getConnection')->with('staging')->andReturn(makeRunTargetConnection());
    $this->app->instance(ConfigService::class, $config);

    $connector = Mockery::mock(DatabaseConnectionService::class);
    $connector->shouldReceive('open')->andReturn('test_conn');
    $this->app->instance(DatabaseConnectionService::class, $connector);

    $sourceSchema = new DatabaseSchemaData(
        databaseName: 'mydb',
        tables: [
            new TableSchemaData(
                name: 'users',
                columns: [
                    new ColumnSchemaData(name: 'id', type: 'int', nullable: false, default: null, isPrimary: true),
                    new ColumnSchemaData(name: 'email', type: 'varchar', nullable: false, default: null, isPrimary: false),
                ],
                foreignKeys: [],
            ),
            new TableSchemaData(
                name: 'orders',
                columns: [
                    new ColumnSchemaData(name: 'id', type: 'int', nullable: false, default: null, isPrimary: true),
                ],
                foreignKeys: [],
            ),
        ],
    );

    $targetSchema = new DatabaseSchemaData(
        databaseName: 'stagingdb',
        tables: [
            new TableSchemaData(
                name: 'users',
                columns: [
                    new ColumnSchemaData(name: 'id', type: 'int', nullable: false, default: null, isPrimary: true),
                    new ColumnSchemaData(name: 'email', type: 'varchar', nullable: false, default: null, isPrimary: false),
                ],
                foreignKeys: [],
            ),
        ],
    );

    $callCount = 0;
    $inspector = Mockery::mock(SchemaInspector::class);
    $inspector->shouldReceive('inspect')->andReturnUsing(static function () use (&$callCount, $sourceSchema, $targetSchema) {
        $callCount++;

        return $callCount === 1 ? $sourceSchema : $targetSchema;
    });
    $this->app->instance(SchemaInspector::class, $inspector);

    $this->artisan('cloning:run', [
        'file' => 'test.cloning.yaml',
        '--target' => 'staging',
        '--dry-run' => true,
    ])
        ->expectsOutputToContain('Schema diff')
        ->assertExitCode(ExitCode::Success->value);

    expect($callCount)->toBe(2);
});

it('dry-run shows identical schema message when schemas match', function (): void {
    Storage::fake('local');
    $yaml = <<<'YAML'
version: "1"
connection: production-db
options:
  chunk_size: 1000
  enforce_column_types: false
  drop_unknown_tables: false
  disable_foreign_key_checks: true
  faker_locale: en_US
tables:
  users:
    rows:
      strategy: full
YAML;
    Storage::disk('local')->put('test.cloning.yaml', $yaml);

    $config = Mockery::mock(ConfigService::class);
    $config->shouldReceive('getConnection')->with('production-db')->andReturn(makeRunMysqlConnection());
    $config->shouldReceive('getConnection')->with('staging')->andReturn(makeRunTargetConnection());
    $this->app->instance(ConfigService::class, $config);

    $connector = Mockery::mock(DatabaseConnectionService::class);
    $connector->shouldReceive('open')->andReturn('test_conn');
    $this->app->instance(DatabaseConnectionService::class, $connector);

    $inspector = Mockery::mock(SchemaInspector::class);
    $inspector->shouldReceive('inspect')->andReturn(makeRunSimpleSchema());
    $this->app->instance(SchemaInspector::class, $inspector);

    $this->artisan('cloning:run', [
        'file' => 'test.cloning.yaml',
        '--target' => 'staging',
        '--dry-run' => true,
    ])
        ->expectsOutputToContain('target matches source')
        ->assertExitCode(ExitCode::Success->value);
});

it('applies --enforce-column-types override from CLI flags', function (): void {
    Storage::fake('local');
    $yaml = <<<'YAML'
version: "1"
connection: production-db
options:
  chunk_size: 1000
  enforce_column_types: false
  drop_unknown_tables: false
  disable_foreign_key_checks: true
  faker_locale: en_US
tables:
  users:
    rows:
      strategy: full
YAML;
    Storage::disk('local')->put('test.cloning.yaml', $yaml);

    $config = Mockery::mock(ConfigService::class);
    $config->shouldReceive('getConnection')->with('production-db')->andReturn(makeRunMysqlConnection());
    $config->shouldReceive('getConnection')->with('staging')->andReturn(makeRunTargetConnection());
    $config->shouldReceive('load')->andReturn(['connections' => []]);
    $this->app->instance(ConfigService::class, $config);

    $connector = Mockery::mock(DatabaseConnectionService::class);
    $connector->shouldReceive('open')->andReturn('test_conn');
    $this->app->instance(DatabaseConnectionService::class, $connector);

    $inspector = Mockery::mock(SchemaInspector::class);
    $inspector->shouldReceive('inspect')->andReturn(makeRunSimpleSchema());
    $this->app->instance(SchemaInspector::class, $inspector);

    $capturedConfig = null;
    $orchestrator = Mockery::mock(CloningRunOrchestrator::class);
    $orchestrator->shouldReceive('run')
        ->withArgs(function (CloningConfigData $cfg) use (&$capturedConfig): bool {
            $capturedConfig = $cfg;

            return true;
        })
        ->andReturn(makeRunResult());
    $this->app->instance(CloningRunOrchestrator::class, $orchestrator);

    $this->artisan('cloning:run', [
        'file' => 'test.cloning.yaml',
        '--target' => 'staging',
        '--enforce-column-types' => true,
        '--no-disable-fk-checks' => true,
        '--ci' => true,
    ])->assertExitCode(ExitCode::Success->value);

    expect($capturedConfig)->not->toBeNull();
    expect($capturedConfig->options->enforceColumnTypes)->toBeTrue();
    expect($capturedConfig->options->disableForeignKeyChecks)->toBeFalse();
    // Unchanged from YAML
    expect($capturedConfig->options->dropUnknownTables)->toBeFalse();
});

it('passes break_on_failure true to orchestrator when --break-on-failure is set', function (): void {
    Storage::fake('local');

    $yaml = <<<'YAML'
version: "1"
connection: production-db
options:
  chunk_size: 1000
  enforce_column_types: false
  drop_unknown_tables: false
  disable_foreign_key_checks: true
  faker_locale: en_US
tables:
  users:
    rows:
      strategy: full
YAML;
    Storage::disk('local')->put('test.cloning.yaml', $yaml);

    $config = Mockery::mock(ConfigService::class);
    $config->shouldReceive('getConnection')->with('production-db')->andReturn(makeRunMysqlConnection());
    $config->shouldReceive('getConnection')->with('staging')->andReturn(makeRunTargetConnection());
    $config->shouldReceive('load')->andReturn([]);
    $this->app->instance(ConfigService::class, $config);

    $connector = Mockery::mock(DatabaseConnectionService::class);
    $connector->shouldReceive('open')->andReturn('test_conn');
    $this->app->instance(DatabaseConnectionService::class, $connector);

    $inspector = Mockery::mock(SchemaInspector::class);
    $inspector->shouldReceive('inspect')->andReturn(makeRunSimpleSchema());
    $this->app->instance(SchemaInspector::class, $inspector);

    $capturedBreakOnFailure = null;

    $orchestrator = Mockery::mock(CloningRunOrchestrator::class);
    $orchestrator->shouldReceive('run')
        ->withArgs(static function ($cfg, $src, $tgt, $schema, $skip, $skipTbls, $only, $cb, $km, bool $bof) use (&$capturedBreakOnFailure): bool {
            $capturedBreakOnFailure = $bof;

            return true;
        })
        ->andReturn(makeRunResult());
    $this->app->instance(CloningRunOrchestrator::class, $orchestrator);

    $this->artisan('cloning:run', [
        'file' => 'test.cloning.yaml',
        '--target' => 'staging',
        '--ci' => true,
        '--break-on-failure' => true,
    ])->assertExitCode(ExitCode::Success->value);

    expect($capturedBreakOnFailure)->toBeTrue();
});

it('merges yaml-level skipTables with cli --skip-tables when calling orchestrator', function (): void {
    Storage::fake('local');

    $yaml = <<<'YAML'
version: "1"
connection: production-db
options:
  chunk_size: 1000
  enforce_column_types: false
  drop_unknown_tables: false
  disable_foreign_key_checks: true
  faker_locale: en_US
skip:
  - audit_logs
tables:
  users:
    rows:
      strategy: full
YAML;
    Storage::disk('local')->put('test.cloning.yaml', $yaml);

    $config = Mockery::mock(ConfigService::class);
    $config->shouldReceive('getConnection')->with('production-db')->andReturn(makeRunMysqlConnection());
    $config->shouldReceive('getConnection')->with('staging')->andReturn(makeRunTargetConnection());
    $config->shouldReceive('load')->andReturn([]);
    $this->app->instance(ConfigService::class, $config);

    $connector = Mockery::mock(DatabaseConnectionService::class);
    $connector->shouldReceive('open')->andReturn('test_conn');
    $this->app->instance(DatabaseConnectionService::class, $connector);

    $inspector = Mockery::mock(SchemaInspector::class);
    $inspector->shouldReceive('inspect')->andReturn(makeRunSimpleSchema());
    $this->app->instance(SchemaInspector::class, $inspector);

    $capturedSkipTables = null;

    $orchestrator = Mockery::mock(CloningRunOrchestrator::class);
    $orchestrator->shouldReceive('run')
        ->withArgs(static function ($cfg, $src, $tgt, $schema, $skipSchema, array $skipTbls, $only, $cb, $km, $bof) use (&$capturedSkipTables): bool {
            $capturedSkipTables = $skipTbls;

            return true;
        })
        ->andReturn(makeRunResult());
    $this->app->instance(CloningRunOrchestrator::class, $orchestrator);

    $this->artisan('cloning:run', [
        'file' => 'test.cloning.yaml',
        '--target' => 'staging',
        '--ci' => true,
        '--skip-tables' => 'sessions',
    ])->assertExitCode(ExitCode::Success->value);

    expect($capturedSkipTables)->toContain('audit_logs');
    expect($capturedSkipTables)->toContain('sessions');
});

it('renders verbose phase steps with start-then-finalize layout', function (): void {
    Storage::fake('local');
    Storage::disk('local')->put('cloning.yml', file_get_contents(__DIR__.'/fixtures/cloning-minimal.yml'));

    $this->mock(ConfigService::class, function ($mock): void {
        $mock->shouldReceive('getConnection')->andReturn(makeRunMysqlConnection());
        $mock->shouldReceive('getConnections')->andReturn([
            'production-db' => makeRunMysqlConnection(),
            'staging' => makeRunTargetConnection(),
        ]);
        $mock->shouldReceive('load')->andReturn([]);
    });

    $this->mock(DatabaseConnectionService::class, function ($mock): void {
        $mock->shouldReceive('open')->andReturn('test_conn');
    });

    $this->mock(SchemaInspector::class, function ($mock): void {
        $mock->shouldReceive('inspect')->andReturn(new DatabaseSchemaData(
            databaseName: 'mydb',
            tables: [],
        ));
    });

    $this->mock(CloningRunOrchestrator::class, function ($mock): void {
        $mock->shouldReceive('run')->andReturn(new RunResultData(
            success: true,
            tables: [],
            totalRows: 0,
            skippedRows: 0,
            durationSeconds: 0.0,
            failureReason: null,
        ));
    });

    $this->artisan('cloning:run cloning.yml --target=staging -v')
        ->expectsOutputToContain('Validating YAML')
        ->expectsOutputToContain('Connecting to')
        ->expectsOutputToContain('✓')
        ->assertExitCode(ExitCode::Success->value);
});

it('renders skip groups under the table line in verbose mode', function (): void {
    Storage::fake('local');
    Storage::disk('local')->put('cloning.yml', file_get_contents(__DIR__.'/fixtures/cloning-minimal.yml'));

    $skippedRows = [
        new SkippedRow(
            tableName: 'orders',
            chunkOffset: 0,
            rowIndex: 0,
            pkSnapshot: ['id' => 1],
            sqlError: 'SQLSTATE[23000]: FK fail',
        ),
        new SkippedRow(
            tableName: 'orders',
            chunkOffset: 0,
            rowIndex: 1,
            pkSnapshot: ['id' => 2],
            sqlError: 'SQLSTATE[23000]: FK fail',
        ),
        new SkippedRow(
            tableName: 'orders',
            chunkOffset: 0,
            rowIndex: 2,
            pkSnapshot: ['id' => 3],
            sqlError: 'SQLSTATE[22001]: Data too long',
        ),
    ];

    $this->mock(ConfigService::class, function ($mock): void {
        $mock->shouldReceive('getConnection')->andReturn(makeRunMysqlConnection());
        $mock->shouldReceive('getConnections')->andReturn([
            'production-db' => makeRunMysqlConnection(),
            'staging' => makeRunTargetConnection(),
        ]);
        $mock->shouldReceive('load')->andReturn([]);
    });

    $this->mock(DatabaseConnectionService::class, function ($mock): void {
        $mock->shouldReceive('open')->andReturn('test_conn');
    });

    $this->mock(SchemaInspector::class, function ($mock): void {
        $mock->shouldReceive('inspect')->andReturn(new DatabaseSchemaData(
            databaseName: 'mydb',
            tables: [],
        ));
    });

    $this->mock(CloningRunOrchestrator::class, function ($mock) use ($skippedRows): void {
        $mock->shouldReceive('run')->andReturnUsing(function (
            CloningConfigData $config,
            ConnectionData $source,
            ConnectionData $target,
            DatabaseSchemaData $sourceSchema,
            bool $skipSchema,
            array $skipTables,
            array $onlyTables,
            callable $onProgress,
            ?KeyRemappingService $keyRemapping = null,
            bool $breakOnFailure = false,
            ?callable $onTableStart = null,
        ) use ($skippedRows): RunResultData {
            if ($onTableStart !== null) {
                $onTableStart('orders');
            }

            $onProgress('orders', TableRunStatus::Transferred, 5, 3, $skippedRows);

            return new RunResultData(
                success: true,
                tables: [],
                totalRows: 5,
                skippedRows: 3,
                durationSeconds: 0.0,
                failureReason: null,
            );
        });
    });

    $this->artisan('cloning:run cloning.yml --target=staging -v')
        ->expectsOutputToContain('└ 2× SQLSTATE[23000]: FK fail')
        ->expectsOutputToContain('└ 1× SQLSTATE[22001]: Data too long')
        ->assertExitCode(ExitCode::Success->value);
});

it('renders skip groups under a fully failed table line in verbose mode', function (): void {
    Storage::fake('local');
    Storage::disk('local')->put('cloning.yml', file_get_contents(__DIR__.'/fixtures/cloning-minimal.yml'));

    $skippedRows = [
        new SkippedRow(
            tableName: 'invoice_lines',
            chunkOffset: 0,
            rowIndex: 0,
            pkSnapshot: ['id' => 1],
            sqlError: "SQLSTATE[42S22]: Column not found: 1054 Unknown column 'tax_rate_v2' in 'field list'",
        ),
        new SkippedRow(
            tableName: 'invoice_lines',
            chunkOffset: 0,
            rowIndex: 1,
            pkSnapshot: ['id' => 2],
            sqlError: "SQLSTATE[42S22]: Column not found: 1054 Unknown column 'tax_rate_v2' in 'field list'",
        ),
    ];

    $this->mock(ConfigService::class, function ($mock): void {
        $mock->shouldReceive('getConnection')->andReturn(makeRunMysqlConnection());
        $mock->shouldReceive('getConnections')->andReturn([
            'production-db' => makeRunMysqlConnection(),
            'staging' => makeRunTargetConnection(),
        ]);
        $mock->shouldReceive('load')->andReturn([]);
    });

    $this->mock(DatabaseConnectionService::class, function ($mock): void {
        $mock->shouldReceive('open')->andReturn('test_conn');
    });

    $this->mock(SchemaInspector::class, function ($mock): void {
        $mock->shouldReceive('inspect')->andReturn(new DatabaseSchemaData(
            databaseName: 'mydb',
            tables: [],
        ));
    });

    $this->mock(CloningRunOrchestrator::class, function ($mock) use ($skippedRows): void {
        $mock->shouldReceive('run')->andReturnUsing(function (
            CloningConfigData $config,
            ConnectionData $source,
            ConnectionData $target,
            DatabaseSchemaData $sourceSchema,
            bool $skipSchema,
            array $skipTables,
            array $onlyTables,
            callable $onProgress,
            ?KeyRemappingService $keyRemapping = null,
            bool $breakOnFailure = false,
            ?callable $onTableStart = null,
        ) use ($skippedRows): RunResultData {
            if ($onTableStart !== null) {
                $onTableStart('invoice_lines');
            }

            $onProgress('invoice_lines', TableRunStatus::Failed, 0, 2, $skippedRows);

            return new RunResultData(
                success: false,
                tables: [],
                totalRows: 0,
                skippedRows: 2,
                durationSeconds: 0.0,
                failureReason: 'simulated',
            );
        });
    });

    $this->artisan('cloning:run cloning.yml --target=staging -v')
        ->expectsOutputToContain('└ 2× SQLSTATE[42S22]: Column not found')
        ->expectsOutputToContain('✗');
});

function makeRemappingYaml(): string
{
    return <<<'YAML'
version: "1"
connection: production-db
options:
  chunk_size: 1000
  enforce_column_types: false
  drop_unknown_tables: false
  disable_foreign_key_checks: true
  faker_locale: en_US
tables:
  users:
    rows:
      strategy: full
    columns:
      id:
        strategy: remapping
        arguments:
          - use: random_integer
          - foreign_keys: []
YAML;
}

function bindExhaustionMocks(KeyRemappingExhaustedException $exhausted): void
{
    $config = Mockery::mock(ConfigService::class);
    $config->shouldReceive('getConnection')->with('production-db')->andReturn(makeRunMysqlConnection());
    $config->shouldReceive('getConnection')->with('staging')->andReturn(makeRunTargetConnection());
    $config->shouldReceive('load')->andReturn(['connections' => []]);
    app()->instance(ConfigService::class, $config);

    $connector = Mockery::mock(DatabaseConnectionService::class);
    $connector->shouldReceive('open')->andReturn('test_conn');
    app()->instance(DatabaseConnectionService::class, $connector);

    $inspector = Mockery::mock(SchemaInspector::class);
    $inspector->shouldReceive('inspect')->andReturn(makeRunSimpleSchema());
    app()->instance(SchemaInspector::class, $inspector);

    $remapping = Mockery::mock(KeyRemappingService::class);
    $remapping->shouldReceive('generateMappings')->andThrow($exhausted);
    $remapping->shouldReceive('cleanup')->andReturnNull();
    app()->instance(KeyRemappingService::class, $remapping);
}

it('returns GeneralError(1) and prints prose hint when key remapping exhausted in --ci', function (): void {
    Storage::fake('local');
    Storage::disk('local')->put('test.cloning.yaml', makeRemappingYaml());

    bindExhaustionMocks(new KeyRemappingExhaustedException(
        table: 'users',
        column: 'id',
        columnType: 'tinyint',
        unsigned: true,
        typeMax: 255,
        rowCount: 199,
        upperSlots: 56,
        gapSlots: 0,
    ));

    $this->artisan('cloning:run', [
        'file' => 'test.cloning.yaml',
        '--target' => 'staging',
        '--ci' => true,
    ])
        ->expectsOutputToContain('Cannot remap users.id')
        ->expectsOutputToContain('TINYINT')
        ->expectsOutputToContain("strategy to 'keep'")
        ->expectsOutputToContain('cloning:column:edit')
        ->assertExitCode(ExitCode::GeneralError->value);
});

it('exits 0 with re-run hint after interactively switching offending column to keep', function (): void {
    Storage::fake('local');
    Storage::disk('local')->put('test.cloning.yaml', makeRemappingYaml());

    bindExhaustionMocks(new KeyRemappingExhaustedException(
        table: 'users',
        column: 'id',
        columnType: 'tinyint',
        unsigned: true,
        typeMax: 255,
        rowCount: 199,
        upperSlots: 56,
        gapSlots: 0,
    ));

    $this->artisan('cloning:run', [
        'file' => 'test.cloning.yaml',
        '--target' => 'staging',
    ])
        ->expectsConfirmation("Switch users.id strategy to 'keep' in test.cloning.yaml?", 'yes')
        ->expectsConfirmation('Apply this change?', 'yes')
        ->expectsOutputToContain('Updated users.id')
        ->expectsOutputToContain('Configuration patched. Re-run')
        ->assertExitCode(ExitCode::Success->value);

    $patched = Storage::disk('local')->get('test.cloning.yaml');
    expect($patched)->toBeString();
    expect($patched)->toContain('strategy: keep');
    expect($patched)->not->toContain('strategy: remapping');
});

it('exits 0 with cancellation message when user declines the strategy switch', function (): void {
    Storage::fake('local');
    $original = makeRemappingYaml();
    Storage::disk('local')->put('test.cloning.yaml', $original);

    bindExhaustionMocks(new KeyRemappingExhaustedException(
        table: 'users',
        column: 'id',
        columnType: 'tinyint',
        unsigned: true,
        typeMax: 255,
        rowCount: 199,
        upperSlots: 56,
        gapSlots: 0,
    ));

    $this->artisan('cloning:run', [
        'file' => 'test.cloning.yaml',
        '--target' => 'staging',
    ])
        ->expectsConfirmation("Switch users.id strategy to 'keep' in test.cloning.yaml?", 'no')
        ->expectsOutputToContain('Cancelled')
        ->assertExitCode(ExitCode::Success->value);

    expect(Storage::disk('local')->get('test.cloning.yaml'))->toBe($original);
});

// ─────────────────────────────────────────────────────────────────────────────
// Real SQLite-backed paths (no server needed). These exercise the live inspect /
// countRows / schema-diff / full-run / dump branches that mocked services skip.
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Create a real on-disk SQLite database with a `users` table holding $rows rows.
 */
function makeSqliteDb(string $path, int $rows = 3, bool $withOrders = false): void
{
    if (is_file($path)) {
        @unlink($path);
    }

    $pdo = new PDO('sqlite:'.$path);
    $pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, email TEXT NOT NULL)');

    for ($i = 1; $i <= $rows; $i++) {
        $pdo->exec(sprintf("INSERT INTO users (id, email) VALUES (%d, 'user%d@example.com')", $i, $i));
    }

    if ($withOrders) {
        $pdo->exec('CREATE TABLE orders (id INTEGER PRIMARY KEY, total INTEGER)');
        $pdo->exec('INSERT INTO orders (id, total) VALUES (1, 100)');
    }

    $pdo = null;
}

/**
 * Write a clonio.json onto the faked local disk with SQLite source + target.
 */
function writeSqliteClonioJson(string $sourcePath, string $targetPath): void
{
    Storage::disk('local')->put('clonio.json', (string) json_encode([
        'connections' => [
            'production-db' => [
                'type' => 'sqlite',
                'database' => $sourcePath,
                'is_production' => true,
            ],
            'staging' => [
                'type' => 'sqlite',
                'database' => $targetPath,
                'is_production' => false,
            ],
        ],
    ]));
}

function sqliteCloningYaml(bool $withMissing = false, string $strategy = 'full', ?int $limit = null): string
{
    $extra = $withMissing ? "  missing_table:\n    rows:\n      strategy: full\n" : '';
    $rows = $limit !== null ? "      strategy: {$strategy}\n      limit: {$limit}" : "      strategy: {$strategy}";

    return <<<YAML
version: "1"
connection: production-db
options:
  chunk_size: 1000
  enforce_column_types: false
  drop_unknown_tables: false
  disable_foreign_key_checks: true
  faker_locale: en_US
tables:
  users:
    rows:
{$rows}
{$extra}
YAML;
}

it('dry-run counts real rows and applies the first/last strategy limit', function (): void {
    Storage::fake('local');
    $source = sys_get_temp_dir().'/clonio_run_src_'.uniqid().'.db';
    $target = sys_get_temp_dir().'/clonio_run_tgt_'.uniqid().'.db';
    makeSqliteDb($source, rows: 5);
    makeSqliteDb($target, rows: 0);
    writeSqliteClonioJson($source, $target);
    Storage::disk('local')->put('test.cloning.yaml', sqliteCloningYaml(strategy: 'first', limit: 2));

    $this->artisan('cloning:run', [
        'file' => 'test.cloning.yaml',
        '--target' => 'staging',
        '--dry-run' => true,
    ])
        ->expectsOutputToContain('Dry-run')
        ->expectsOutputToContain('No data will be transferred')
        ->assertExitCode(ExitCode::Success->value);

    @unlink($source);
    @unlink($target);
});

it('dry-run renders NOT FOUND row and not-found total for missing tables', function (): void {
    Storage::fake('local');
    $source = sys_get_temp_dir().'/clonio_run_src_'.uniqid().'.db';
    $target = sys_get_temp_dir().'/clonio_run_tgt_'.uniqid().'.db';
    makeSqliteDb($source, rows: 2);
    makeSqliteDb($target, rows: 0);
    writeSqliteClonioJson($source, $target);
    Storage::disk('local')->put('test.cloning.yaml', sqliteCloningYaml(withMissing: true));

    $this->artisan('cloning:run', [
        'file' => 'test.cloning.yaml',
        '--target' => 'staging',
        '--dry-run' => true,
    ])
        ->expectsOutputToContain('NOT FOUND')
        ->expectsOutputToContain('not found, will be skipped')
        ->assertExitCode(ExitCode::Success->value);

    @unlink($source);
    @unlink($target);
});

it('dry-run reports schema diff with extra tables on the source', function (): void {
    Storage::fake('local');
    $source = sys_get_temp_dir().'/clonio_run_src_'.uniqid().'.db';
    $target = sys_get_temp_dir().'/clonio_run_tgt_'.uniqid().'.db';
    // Source has users + orders; target only users → orders is "missing" on target.
    makeSqliteDb($source, rows: 2, withOrders: true);
    makeSqliteDb($target, rows: 0);
    writeSqliteClonioJson($source, $target);
    Storage::disk('local')->put('test.cloning.yaml', sqliteCloningYaml());

    $this->artisan('cloning:run', [
        'file' => 'test.cloning.yaml',
        '--target' => 'staging',
        '--dry-run' => true,
    ])
        ->expectsOutputToContain('Schema diff: source → target')
        ->expectsOutputToContain('Missing tables')
        ->assertExitCode(ExitCode::Success->value);

    @unlink($source);
    @unlink($target);
});

it('completes a real full run over SQLite and prints the summary line', function (): void {
    Storage::fake('local');
    $source = sys_get_temp_dir().'/clonio_run_src_'.uniqid().'.db';
    $target = sys_get_temp_dir().'/clonio_run_tgt_'.uniqid().'.db';
    makeSqliteDb($source, rows: 3);
    // Target needs the destination schema (schema replication then data transfer).
    makeSqliteDb($target, rows: 0);
    writeSqliteClonioJson($source, $target);
    Storage::disk('local')->put('test.cloning.yaml', sqliteCloningYaml());

    // Non-CI so the "Tables: x/y Rows: ..." summary line (and dot-newline) renders.
    $this->artisan('cloning:run', [
        'file' => 'test.cloning.yaml',
        '--target' => 'staging',
    ])
        ->expectsOutputToContain('Tables:')
        ->assertExitCode(ExitCode::Success->value);

    @unlink($source);
    @unlink($target);
});

it('prints the per-table timing summary at -vvv on a real run', function (): void {
    Storage::fake('local');
    $source = sys_get_temp_dir().'/clonio_run_src_'.uniqid().'.db';
    $target = sys_get_temp_dir().'/clonio_run_tgt_'.uniqid().'.db';
    makeSqliteDb($source, rows: 3);
    makeSqliteDb($target, rows: 0);
    writeSqliteClonioJson($source, $target);
    Storage::disk('local')->put('test.cloning.yaml', sqliteCloningYaml());

    // Live bars need a real TTY; test output is a non-decorated BufferedOutput, so
    // -vvv takes the fallback path but still emits the per-table timing summary.
    $this->artisan('cloning:run test.cloning.yaml --target=staging -vvv')
        ->expectsOutputToContain('timing summary')
        ->expectsOutputToContain('Tables:')
        ->assertExitCode(ExitCode::Success->value);

    @unlink($source);
    @unlink($target);
});

it('renders the verbose schema-comparison phase on a real run', function (): void {
    Storage::fake('local');
    $source = sys_get_temp_dir().'/clonio_run_src_'.uniqid().'.db';
    $target = sys_get_temp_dir().'/clonio_run_tgt_'.uniqid().'.db';
    makeSqliteDb($source, rows: 2, withOrders: true);
    makeSqliteDb($target, rows: 0);
    writeSqliteClonioJson($source, $target);
    Storage::disk('local')->put('test.cloning.yaml', sqliteCloningYaml());

    $this->artisan('cloning:run test.cloning.yaml --target=staging -v')
        ->expectsOutputToContain('Comparing schema')
        ->assertExitCode(ExitCode::Success->value);

    @unlink($source);
    @unlink($target);
});

it('produces a SQL dump archive when the target is a dump connection', function (): void {
    Storage::fake('local');
    $source = sys_get_temp_dir().'/clonio_run_src_'.uniqid().'.db';
    makeSqliteDb($source, rows: 4);

    Storage::disk('local')->put('clonio.json', (string) json_encode([
        'connections' => [
            'production-db' => [
                'type' => 'sqlite',
                'database' => $source,
                'is_production' => true,
            ],
            'dump-target' => [
                'type' => 'dump',
                'dialect' => 'sqlite',
                'is_production' => false,
            ],
        ],
    ]));
    Storage::disk('local')->put('test.cloning.yaml', sqliteCloningYaml());

    $this->artisan('cloning:run', [
        'file' => 'test.cloning.yaml',
        '--target' => 'dump-target',
    ])
        ->expectsOutputToContain('Dump:')
        ->assertExitCode(ExitCode::Success->value);

    @unlink($source);
});

it('returns GeneralError(1) when the dump archive cannot be finalised', function (): void {
    // Mocked orchestrator never calls begin() on the dump sink, so finish() throws.
    Storage::fake('local');
    Storage::disk('local')->put('test.cloning.yaml', dumpRunYaml());

    $config = Mockery::mock(ConfigService::class);
    $config->shouldReceive('getConnection')->with('production-db')->andReturn(makeRunMysqlConnection());
    $config->shouldReceive('getConnection')->with('dump-target')->andReturn(makeRunDumpConnection('dump-target', DatabaseConnectionType::Mysql));
    $config->shouldReceive('load')->andReturn(['connections' => []]);
    $this->app->instance(ConfigService::class, $config);

    $connector = Mockery::mock(DatabaseConnectionService::class);
    $connector->shouldReceive('open')->andReturn('test_conn');
    $this->app->instance(DatabaseConnectionService::class, $connector);

    $inspector = Mockery::mock(SchemaInspector::class);
    $inspector->shouldReceive('inspect')->andReturn(makeRunSimpleSchema());
    $this->app->instance(SchemaInspector::class, $inspector);

    $orchestrator = Mockery::mock(CloningRunOrchestrator::class);
    $orchestrator->shouldReceive('run')->andReturn(makeRunResult());
    $this->app->instance(CloningRunOrchestrator::class, $orchestrator);

    $this->artisan('cloning:run', [
        'file' => 'test.cloning.yaml',
        '--target' => 'dump-target',
        '--ci' => true,
    ])
        ->expectsOutputToContain('Failed to finalise dump archive')
        ->assertExitCode(ExitCode::GeneralError->value);
});

it('returns ConfigError(7) when there is no other connection to use as target', function (): void {
    Storage::fake('local');
    Storage::disk('local')->put('test.cloning.yaml', dumpRunYaml());

    $prod = makeRunMysqlConnection('production-db');
    $config = Mockery::mock(ConfigService::class);
    $config->shouldReceive('getConnection')->with('production-db')->andReturn($prod);
    $config->shouldReceive('getConnections')->andReturn(['production-db' => $prod]);
    $this->app->instance(ConfigService::class, $config);

    $connector = Mockery::mock(DatabaseConnectionService::class);
    $connector->shouldReceive('open')->andReturn('test_conn');
    $this->app->instance(DatabaseConnectionService::class, $connector);

    $this->artisan('cloning:run', ['file' => 'test.cloning.yaml'])
        ->expectsOutputToContain('No other connections available')
        ->assertExitCode(ExitCode::ConfigError->value);
});

it('returns ConnectionError(3) when source schema inspection fails', function (): void {
    Storage::fake('local');
    Storage::disk('local')->put('test.cloning.yaml', dumpRunYaml());

    $config = Mockery::mock(ConfigService::class);
    $config->shouldReceive('getConnection')->with('production-db')->andReturn(makeRunMysqlConnection());
    $config->shouldReceive('getConnection')->with('staging')->andReturn(makeRunTargetConnection());
    $config->shouldReceive('load')->andReturn(['connections' => []]);
    $this->app->instance(ConfigService::class, $config);

    $connector = Mockery::mock(DatabaseConnectionService::class);
    $connector->shouldReceive('open')->andReturn('test_conn');
    $this->app->instance(DatabaseConnectionService::class, $connector);

    $inspector = Mockery::mock(SchemaInspector::class);
    $inspector->shouldReceive('inspect')->andThrow(new RuntimeException('schema boom'));
    $this->app->instance(SchemaInspector::class, $inspector);

    $this->artisan('cloning:run', [
        'file' => 'test.cloning.yaml',
        '--target' => 'staging',
        '--ci' => true,
    ])
        ->expectsOutputToContain('Failed to inspect source schema')
        ->assertExitCode(ExitCode::ConnectionError->value);
});

it('rethrows a non-memory error raised during key mapping generation', function (): void {
    Storage::fake('local');
    Storage::disk('local')->put('test.cloning.yaml', makeRemappingYaml());

    $config = Mockery::mock(ConfigService::class);
    $config->shouldReceive('getConnection')->with('production-db')->andReturn(makeRunMysqlConnection());
    $config->shouldReceive('getConnection')->with('staging')->andReturn(makeRunTargetConnection());
    $config->shouldReceive('load')->andReturn(['connections' => []]);
    $this->app->instance(ConfigService::class, $config);

    $connector = Mockery::mock(DatabaseConnectionService::class);
    $connector->shouldReceive('open')->andReturn('test_conn');
    $this->app->instance(DatabaseConnectionService::class, $connector);

    $inspector = Mockery::mock(SchemaInspector::class);
    $inspector->shouldReceive('inspect')->andReturn(makeRunSimpleSchema());
    $this->app->instance(SchemaInspector::class, $inspector);

    $remapping = Mockery::mock(KeyRemappingService::class);
    $remapping->shouldReceive('generateMappings')->andThrow(new RuntimeException('unexpected remap failure'));
    $remapping->shouldReceive('cleanup')->andReturnNull();
    $this->app->instance(KeyRemappingService::class, $remapping);

    $this->artisan('cloning:run', [
        'file' => 'test.cloning.yaml',
        '--target' => 'staging',
        '--ci' => true,
    ])->assertExitCode(ExitCode::GeneralError->value);
})->throws(RuntimeException::class, 'unexpected remap failure');

it('prints a --no-memory-limit hint when key mapping runs out of memory', function (): void {
    Storage::fake('local');
    Storage::disk('local')->put('test.cloning.yaml', makeRemappingYaml());

    $config = Mockery::mock(ConfigService::class);
    $config->shouldReceive('getConnection')->with('production-db')->andReturn(makeRunMysqlConnection());
    $config->shouldReceive('getConnection')->with('staging')->andReturn(makeRunTargetConnection());
    $config->shouldReceive('load')->andReturn(['connections' => []]);
    $this->app->instance(ConfigService::class, $config);

    $connector = Mockery::mock(DatabaseConnectionService::class);
    $connector->shouldReceive('open')->andReturn('test_conn');
    $this->app->instance(DatabaseConnectionService::class, $connector);

    $inspector = Mockery::mock(SchemaInspector::class);
    $inspector->shouldReceive('inspect')->andReturn(makeRunSimpleSchema());
    $this->app->instance(SchemaInspector::class, $inspector);

    $remapping = Mockery::mock(KeyRemappingService::class);
    $remapping->shouldReceive('generateMappings')->andThrow(new RuntimeException('Allowed memory size of 12345 bytes exhausted'));
    $remapping->shouldReceive('cleanup')->andReturnNull();
    $this->app->instance(KeyRemappingService::class, $remapping);

    $this->artisan('cloning:run', [
        'file' => 'test.cloning.yaml',
        '--target' => 'staging',
        '--ci' => true,
    ])
        ->expectsOutputToContain('Memory exhausted during key mapping generation')
        ->assertExitCode(ExitCode::GeneralError->value);
});

it('reports schema-replication failures and not-found tables collected during a CI run', function (): void {
    Storage::fake('local');
    Storage::disk('local')->put('test.cloning.yaml', dumpRunYaml());

    $config = Mockery::mock(ConfigService::class);
    $config->shouldReceive('getConnection')->with('production-db')->andReturn(makeRunMysqlConnection());
    $config->shouldReceive('getConnection')->with('staging')->andReturn(makeRunTargetConnection());
    $config->shouldReceive('load')->andReturn(['connections' => []]);
    $this->app->instance(ConfigService::class, $config);

    $connector = Mockery::mock(DatabaseConnectionService::class);
    $connector->shouldReceive('open')->andReturn('test_conn');
    $this->app->instance(DatabaseConnectionService::class, $connector);

    $inspector = Mockery::mock(SchemaInspector::class);
    $inspector->shouldReceive('inspect')->andReturn(makeRunSimpleSchema());
    $this->app->instance(SchemaInspector::class, $inspector);

    $result = new RunResultData(
        success: true,
        tables: [
            new TableRunResultData('users', TableRunStatus::Transferred, 10, 0, 0.1, null),
            new TableRunResultData('gone', TableRunStatus::NotFound, 0, 0, 0.0, null),
            new TableRunResultData('broken', TableRunStatus::SkippedBySchemaFailure, 0, 0, 0.0, null),
        ],
        totalRows: 10,
        skippedRows: 0,
        durationSeconds: 0.2,
        failureReason: null,
    );

    $orchestrator = Mockery::mock(CloningRunOrchestrator::class);
    $orchestrator->shouldReceive('run')->andReturnUsing(function (
        CloningConfigData $config,
        ConnectionData $source,
        ConnectionData $target,
        DatabaseSchemaData $sourceSchema,
        bool $skipSchema,
        array $skipTables,
        array $onlyTables,
        callable $onProgress,
        ?KeyRemappingService $keyRemapping = null,
        bool $breakOnFailure = false,
        ?callable $onTableStart = null,
    ) use ($result): RunResultData {
        // CI branch of onProgress collects not-found + schema-failure names.
        $onProgress('gone', TableRunStatus::NotFound, 0, 0, []);
        $onProgress('broken', TableRunStatus::SkippedBySchemaFailure, 0, 0, []);

        return $result;
    });
    $this->app->instance(CloningRunOrchestrator::class, $orchestrator);

    $this->artisan('cloning:run', [
        'file' => 'test.cloning.yaml',
        '--target' => 'staging',
    ])
        ->expectsOutputToContain('schema replication failure')
        ->assertExitCode(ExitCode::Success->value);
});

it('formats minutes-and-seconds durations in the summary line', function (): void {
    Storage::fake('local');
    Storage::disk('local')->put('test.cloning.yaml', dumpRunYaml());

    $config = Mockery::mock(ConfigService::class);
    $config->shouldReceive('getConnection')->with('production-db')->andReturn(makeRunMysqlConnection());
    $config->shouldReceive('getConnection')->with('staging')->andReturn(makeRunTargetConnection());
    $config->shouldReceive('load')->andReturn(['connections' => []]);
    $this->app->instance(ConfigService::class, $config);

    $connector = Mockery::mock(DatabaseConnectionService::class);
    $connector->shouldReceive('open')->andReturn('test_conn');
    $this->app->instance(DatabaseConnectionService::class, $connector);

    $inspector = Mockery::mock(SchemaInspector::class);
    $inspector->shouldReceive('inspect')->andReturn(makeRunSimpleSchema());
    $this->app->instance(SchemaInspector::class, $inspector);

    $result = new RunResultData(
        success: true,
        tables: [new TableRunResultData('users', TableRunStatus::Transferred, 100, 0, 90.0, null)],
        totalRows: 100,
        skippedRows: 0,
        durationSeconds: 90.0,
        failureReason: null,
    );

    $orchestrator = Mockery::mock(CloningRunOrchestrator::class);
    $orchestrator->shouldReceive('run')->andReturn($result);
    $this->app->instance(CloningRunOrchestrator::class, $orchestrator);

    $this->artisan('cloning:run', [
        'file' => 'test.cloning.yaml',
        '--target' => 'staging',
    ])
        ->expectsOutputToContain('1m 30s')
        ->assertExitCode(ExitCode::Success->value);
});

it('returns GeneralError(1) when cloning:column:edit fails during interactive recovery', function (): void {
    Storage::fake('local');
    Storage::disk('local')->put('test.cloning.yaml', makeRemappingYaml());

    // The offending table is absent from the YAML → the delegated cloning:column:edit
    // returns ValidationError, which the recovery flow surfaces as GeneralError.
    bindExhaustionMocks(new KeyRemappingExhaustedException(
        table: 'absent_table',
        column: 'id',
        columnType: 'tinyint',
        unsigned: true,
        typeMax: 255,
        rowCount: 199,
        upperSlots: 56,
        gapSlots: 0,
    ));

    $this->artisan('cloning:run', [
        'file' => 'test.cloning.yaml',
        '--target' => 'staging',
    ])
        ->expectsConfirmation("Switch absent_table.id strategy to 'keep' in test.cloning.yaml?", 'yes')
        ->expectsOutputToContain('Failed to update column strategy')
        ->assertExitCode(ExitCode::GeneralError->value);
});

it('parses --only-tables and forwards it to the orchestrator', function (): void {
    Storage::fake('local');
    Storage::disk('local')->put('test.cloning.yaml', dumpRunYaml());

    $config = Mockery::mock(ConfigService::class);
    $config->shouldReceive('getConnection')->with('production-db')->andReturn(makeRunMysqlConnection());
    $config->shouldReceive('getConnection')->with('staging')->andReturn(makeRunTargetConnection());
    $config->shouldReceive('load')->andReturn(['connections' => []]);
    $this->app->instance(ConfigService::class, $config);

    $connector = Mockery::mock(DatabaseConnectionService::class);
    $connector->shouldReceive('open')->andReturn('test_conn');
    $this->app->instance(DatabaseConnectionService::class, $connector);

    $inspector = Mockery::mock(SchemaInspector::class);
    $inspector->shouldReceive('inspect')->andReturn(makeRunSimpleSchema());
    $this->app->instance(SchemaInspector::class, $inspector);

    $capturedOnly = null;
    $orchestrator = Mockery::mock(CloningRunOrchestrator::class);
    $orchestrator->shouldReceive('run')
        ->withArgs(static function ($cfg, $src, $tgt, $schema, $skipSchema, $skipTbls, array $only) use (&$capturedOnly): bool {
            $capturedOnly = $only;

            return true;
        })
        ->andReturn(makeRunResult());
    $this->app->instance(CloningRunOrchestrator::class, $orchestrator);

    $this->artisan('cloning:run', [
        'file' => 'test.cloning.yaml',
        '--target' => 'staging',
        '--ci' => true,
        '--only-tables' => 'users, orders',
    ])->assertExitCode(ExitCode::Success->value);

    expect($capturedOnly)->toBe(['users', 'orders']);
});

it('dry-run returns ConnectionError(3) when source schema inspection fails', function (): void {
    Storage::fake('local');
    Storage::disk('local')->put('test.cloning.yaml', dumpRunYaml());

    $config = Mockery::mock(ConfigService::class);
    $config->shouldReceive('getConnection')->with('production-db')->andReturn(makeRunMysqlConnection());
    $config->shouldReceive('getConnection')->with('staging')->andReturn(makeRunTargetConnection());
    $this->app->instance(ConfigService::class, $config);

    $connector = Mockery::mock(DatabaseConnectionService::class);
    $connector->shouldReceive('open')->andReturn('test_conn');
    $this->app->instance(DatabaseConnectionService::class, $connector);

    $inspector = Mockery::mock(SchemaInspector::class);
    $inspector->shouldReceive('inspect')->andThrow(new RuntimeException('dry inspect boom'));
    $this->app->instance(SchemaInspector::class, $inspector);

    $this->artisan('cloning:run', [
        'file' => 'test.cloning.yaml',
        '--target' => 'staging',
        '--dry-run' => true,
    ])
        ->expectsOutputToContain('Failed to inspect source schema')
        ->assertExitCode(ExitCode::ConnectionError->value);
});

it('dry-run proceeds without a diff when only the target inspection fails', function (): void {
    Storage::fake('local');
    Storage::disk('local')->put('test.cloning.yaml', dumpRunYaml());

    $config = Mockery::mock(ConfigService::class);
    $config->shouldReceive('getConnection')->with('production-db')->andReturn(makeRunMysqlConnection());
    $config->shouldReceive('getConnection')->with('staging')->andReturn(makeRunTargetConnection());
    $this->app->instance(ConfigService::class, $config);

    $connector = Mockery::mock(DatabaseConnectionService::class);
    $connector->shouldReceive('open')->andReturn('test_conn');
    $this->app->instance(DatabaseConnectionService::class, $connector);

    // First inspect (source) succeeds; second (target) throws → diff is skipped (non-fatal).
    $callCount = 0;
    $inspector = Mockery::mock(SchemaInspector::class);
    $inspector->shouldReceive('inspect')->andReturnUsing(static function () use (&$callCount) {
        $callCount++;

        if ($callCount === 1) {
            return makeRunSimpleSchema();
        }

        throw new RuntimeException('target inspect failed');
    });
    $this->app->instance(SchemaInspector::class, $inspector);

    $this->artisan('cloning:run', [
        'file' => 'test.cloning.yaml',
        '--target' => 'staging',
        '--dry-run' => true,
    ])
        ->expectsOutputToContain('Dry-run')
        ->assertExitCode(ExitCode::Success->value);
});

it('dry-run renders extra-table and modified-column schema diffs', function (): void {
    Storage::fake('local');
    $source = sys_get_temp_dir().'/clonio_run_src_'.uniqid().'.db';
    $target = sys_get_temp_dir().'/clonio_run_tgt_'.uniqid().'.db';

    // Source: users(id, email). Target: users(id, email TEXT→ but add an extra
    // column + an extra table) so the diff shows extra tables and modified columns.
    $pdoSrc = new PDO('sqlite:'.$source);
    $pdoSrc->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, email TEXT NOT NULL)');
    $pdoSrc->exec("INSERT INTO users (id, email) VALUES (1, 'a@example.com')");
    $pdoSrc = null;

    $pdoTgt = new PDO('sqlite:'.$target);
    // email differs (INTEGER vs TEXT) → modified column; legacy table → extra on target.
    $pdoTgt->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, email INTEGER)');
    $pdoTgt->exec('CREATE TABLE legacy (id INTEGER PRIMARY KEY)');
    $pdoTgt = null;

    writeSqliteClonioJson($source, $target);
    Storage::disk('local')->put('test.cloning.yaml', sqliteCloningYaml());

    $this->artisan('cloning:run', [
        'file' => 'test.cloning.yaml',
        '--target' => 'staging',
        '--dry-run' => true,
    ])
        ->expectsOutputToContain('Schema diff: source → target')
        ->assertExitCode(ExitCode::Success->value);

    @unlink($source);
    @unlink($target);
});

it('honours --audit-channel override even without an audit config block', function (): void {
    Storage::fake('local');
    Storage::disk('local')->put('test.cloning.yaml', dumpRunYaml());

    $config = Mockery::mock(ConfigService::class);
    $config->shouldReceive('getConnection')->with('production-db')->andReturn(makeRunMysqlConnection());
    $config->shouldReceive('getConnection')->with('staging')->andReturn(makeRunTargetConnection());
    $config->shouldReceive('load')->andReturn(['connections' => []]);
    $this->app->instance(ConfigService::class, $config);

    $connector = Mockery::mock(DatabaseConnectionService::class);
    $connector->shouldReceive('open')->andReturn('test_conn');
    $this->app->instance(DatabaseConnectionService::class, $connector);

    $inspector = Mockery::mock(SchemaInspector::class);
    $inspector->shouldReceive('inspect')->andReturn(makeRunSimpleSchema());
    $this->app->instance(SchemaInspector::class, $inspector);

    $orchestrator = Mockery::mock(CloningRunOrchestrator::class);
    $orchestrator->shouldReceive('run')->andReturn(makeRunResult());
    $this->app->instance(CloningRunOrchestrator::class, $orchestrator);

    // 'local' channel with no config block: delivery is attempted (label "Delivering audit log").
    $this->artisan('cloning:run', [
        'file' => 'test.cloning.yaml',
        '--target' => 'staging',
        '--ci' => true,
        '--audit-channel' => 'local',
    ])->assertExitCode(ExitCode::Success->value);
});
