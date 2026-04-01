<?php

declare(strict_types=1);

use App\Data\Cloning\ColumnCloningConfigData;
use App\Data\ConnectionData;
use App\Data\Pii\PiiMatcherData;
use App\Data\Pii\PiiMatcherSetData;
use App\Data\Schema\ColumnSchemaData;
use App\Data\Schema\DatabaseSchemaData;
use App\Data\Schema\TableSchemaData;
use App\Enums\DatabaseConnectionType;
use App\Enums\ExitCode;
use App\Services\Config\ConfigService;
use App\Services\Pii\PiiMatcherLoader;
use App\Services\Schema\SchemaInspector;
use Illuminate\Support\Facades\Storage;

function makeDumpMysqlConnection(string $name = 'production-db'): ConnectionData
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

function makeSimpleSchema(): DatabaseSchemaData
{
    return new DatabaseSchemaData(
        databaseName: 'mydb',
        tables: [
            new TableSchemaData(
                name: 'users',
                columns: [
                    new ColumnSchemaData(name: 'id', type: 'int', nullable: false, default: null, isPrimary: true),
                    new ColumnSchemaData(name: 'email', type: 'varchar', nullable: false, default: null, isPrimary: false),
                    new ColumnSchemaData(name: 'password', type: 'varchar', nullable: false, default: null, isPrimary: false),
                    new ColumnSchemaData(name: 'created_at', type: 'timestamp', nullable: true, default: null, isPrimary: false),
                ],
                foreignKeys: [],
            ),
        ],
    );
}

function makePiiMatcherSet(): PiiMatcherSetData
{
    return new PiiMatcherSetData([
        new PiiMatcherData(
            key: 'email_address',
            group: 'contact',
            name: 'Email Address',
            enabled: true,
            patterns: ['email'],
            transformation: new ColumnCloningConfigData(
                columnName: 'email',
                strategy: 'fake',
                fakerMethod: 'safeEmail',
                fakerArguments: [],
                hashAlgorithm: null,
                hashSalt: null,
                maskChar: null,
                visibleChars: null,
                preserveFormat: null,
                staticValue: null,
            ),
            isBaseline: true,
        ),
        new PiiMatcherData(
            key: 'password',
            group: 'authentication',
            name: 'Password / Secret',
            enabled: true,
            patterns: ['password'],
            transformation: new ColumnCloningConfigData(
                columnName: 'password',
                strategy: 'hash',
                fakerMethod: null,
                fakerArguments: [],
                hashAlgorithm: 'sha256',
                hashSalt: '',
                maskChar: null,
                visibleChars: null,
                preserveFormat: null,
                staticValue: null,
            ),
            isBaseline: true,
        ),
    ]);
}

it('exits with ValidationError(4) when --ci and no --connection', function (): void {
    Storage::fake('local');
    Storage::disk('local')->put('clonio.json', json_encode(['connections' => []]));

    $config = Mockery::mock(ConfigService::class);
    $config->shouldReceive('exists')->andReturn(true);

    $this->app->instance(ConfigService::class, $config);

    $this->artisan('cloning:dump', ['--ci' => true])
        ->expectsOutputToContain('--connection is required')
        ->assertExitCode(ExitCode::ValidationError->value);
});

it('exits with ConnectionError(3) when unknown --connection given', function (): void {
    Storage::fake('local');

    $config = Mockery::mock(ConfigService::class);
    $config->shouldReceive('exists')->andReturn(true);
    $config->shouldReceive('getConnection')->with('unknown')->andReturn(null);

    $this->app->instance(ConfigService::class, $config);

    $this->artisan('cloning:dump', ['--connection' => 'unknown', '--ci' => true])
        ->expectsOutputToContain("No connection named 'unknown' found.")
        ->assertExitCode(ExitCode::ConnectionError->value);
});

it('exits with ConfigError(2) when config file does not exist', function (): void {
    Storage::fake('local');

    $config = Mockery::mock(ConfigService::class);
    $config->shouldReceive('exists')->andReturn(false);

    $this->app->instance(ConfigService::class, $config);

    $this->artisan('cloning:dump', ['--connection' => 'prod', '--ci' => true])
        ->expectsOutputToContain('clonio init')
        ->assertExitCode(ExitCode::ConfigError->value);
});

it('exits with ConfigError(2) when no connections in config and interactive', function (): void {
    Storage::fake('local');

    $config = Mockery::mock(ConfigService::class);
    $config->shouldReceive('exists')->andReturn(true);
    $config->shouldReceive('getConnections')->andReturn([]);

    $this->app->instance(ConfigService::class, $config);

    $this->artisan('cloning:dump')
        ->expectsOutputToContain('No connections defined')
        ->assertExitCode(ExitCode::ConfigError->value);
});

it('happy path: writes YAML with PII columns detected', function (): void {
    Storage::fake('local');

    $connection = makeDumpMysqlConnection('production-db');
    $schema = makeSimpleSchema();
    $piiSet = makePiiMatcherSet();

    $config = Mockery::mock(ConfigService::class);
    $config->shouldReceive('exists')->andReturn(true);
    $config->shouldReceive('getConnection')->with('production-db')->andReturn($connection);

    $inspector = Mockery::mock(SchemaInspector::class);
    $inspector->shouldReceive('inspect')->with($connection)->andReturn($schema);

    $piiLoader = Mockery::mock(PiiMatcherLoader::class);
    $piiLoader->shouldReceive('load')->andReturn($piiSet);

    $this->app->instance(ConfigService::class, $config);
    $this->app->instance(SchemaInspector::class, $inspector);
    $this->app->instance(PiiMatcherLoader::class, $piiLoader);

    $this->artisan('cloning:dump', ['--connection' => 'production-db', '--ci' => true])
        ->assertExitCode(ExitCode::Success->value);

    expect(Storage::disk('local')->exists('production-db.cloning.yaml'))->toBeTrue();

    $yaml = Storage::disk('local')->get('production-db.cloning.yaml');
    expect($yaml)->toBeString();
    expect($yaml)->toContain('connection: production-db');
    expect($yaml)->toContain('strategy: fake');
    expect($yaml)->toContain('faker_method: safeEmail');
    expect($yaml)->toContain('strategy: hash');
    expect($yaml)->toContain('algorithm: sha256');
});

it('writes output to custom path when --output is specified', function (): void {
    Storage::fake('local');

    $connection = makeDumpMysqlConnection('production-db');
    $schema = makeSimpleSchema();
    $piiSet = makePiiMatcherSet();

    $config = Mockery::mock(ConfigService::class);
    $config->shouldReceive('exists')->andReturn(true);
    $config->shouldReceive('getConnection')->with('production-db')->andReturn($connection);

    $inspector = Mockery::mock(SchemaInspector::class);
    $inspector->shouldReceive('inspect')->andReturn($schema);

    $piiLoader = Mockery::mock(PiiMatcherLoader::class);
    $piiLoader->shouldReceive('load')->andReturn($piiSet);

    $this->app->instance(ConfigService::class, $config);
    $this->app->instance(SchemaInspector::class, $inspector);
    $this->app->instance(PiiMatcherLoader::class, $piiLoader);

    $this->artisan('cloning:dump', [
        '--connection' => 'production-db',
        '--output' => 'custom-output.cloning.yaml',
        '--ci' => true,
    ])->assertExitCode(ExitCode::Success->value);

    expect(Storage::disk('local')->exists('custom-output.cloning.yaml'))->toBeTrue();
});

it('overwrites existing file without prompt when --force is given', function (): void {
    Storage::fake('local');
    Storage::disk('local')->put('production-db.cloning.yaml', 'old content');

    $connection = makeDumpMysqlConnection('production-db');
    $schema = makeSimpleSchema();
    $piiSet = makePiiMatcherSet();

    $config = Mockery::mock(ConfigService::class);
    $config->shouldReceive('exists')->andReturn(true);
    $config->shouldReceive('getConnection')->with('production-db')->andReturn($connection);

    $inspector = Mockery::mock(SchemaInspector::class);
    $inspector->shouldReceive('inspect')->andReturn($schema);

    $piiLoader = Mockery::mock(PiiMatcherLoader::class);
    $piiLoader->shouldReceive('load')->andReturn($piiSet);

    $this->app->instance(ConfigService::class, $config);
    $this->app->instance(SchemaInspector::class, $inspector);
    $this->app->instance(PiiMatcherLoader::class, $piiLoader);

    $this->artisan('cloning:dump', [
        '--connection' => 'production-db',
        '--force' => true,
        '--ci' => true,
    ])->assertExitCode(ExitCode::Success->value);

    $yaml = Storage::disk('local')->get('production-db.cloning.yaml');
    expect($yaml)->not->toBe('old content');
    expect($yaml)->toContain('connection: production-db');
});

it('only-pii: only includes PII columns and tables', function (): void {
    Storage::fake('local');

    $connection = makeDumpMysqlConnection('production-db');

    // Schema with two tables: users (has PII), orders (no PII)
    $schema = new DatabaseSchemaData(
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
                    new ColumnSchemaData(name: 'total', type: 'decimal', nullable: false, default: null, isPrimary: false),
                ],
                foreignKeys: [],
            ),
        ],
    );

    $piiSet = makePiiMatcherSet();

    $config = Mockery::mock(ConfigService::class);
    $config->shouldReceive('exists')->andReturn(true);
    $config->shouldReceive('getConnection')->with('production-db')->andReturn($connection);

    $inspector = Mockery::mock(SchemaInspector::class);
    $inspector->shouldReceive('inspect')->andReturn($schema);

    $piiLoader = Mockery::mock(PiiMatcherLoader::class);
    $piiLoader->shouldReceive('load')->andReturn($piiSet);

    $this->app->instance(ConfigService::class, $config);
    $this->app->instance(SchemaInspector::class, $inspector);
    $this->app->instance(PiiMatcherLoader::class, $piiLoader);

    $this->artisan('cloning:dump', [
        '--connection' => 'production-db',
        '--only-pii' => true,
        '--ci' => true,
    ])->assertExitCode(ExitCode::Success->value);

    $yaml = Storage::disk('local')->get('production-db.cloning.yaml');
    expect($yaml)->toBeString();
    expect($yaml)->toContain('users:');
    expect($yaml)->not->toContain('orders:');
    expect($yaml)->toContain('email:');
    expect($yaml)->not->toContain('id:');
});
