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
            'default' => 'local-main',
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

    $exitCode = $this->artisan('cloning:run cloning.yml --target=staging --ci -v')
        ->run();

    expect($exitCode)->toBe(ExitCode::Success->value);
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
