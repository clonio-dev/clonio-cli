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
use App\Services\Cloning\CloningRunOrchestrator;
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
            'channels' => [
                'local-main' => [
                    'type' => 'local',
                    'audit_log' => ['path' => 'audit-logs'],
                    'run_log' => ['path' => 'run-logs'],
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
    $files = Storage::disk('local')->allFiles('audit-logs');
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
