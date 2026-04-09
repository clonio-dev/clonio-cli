<?php

declare(strict_types=1);

use App\Data\Cloning\ColumnCloningConfigData;
use App\Data\ConnectionData;
use App\Data\Pii\PiiMatcherData;
use App\Data\Pii\PiiMatcherSetData;
use App\Data\Schema\ColumnSchemaData;
use App\Data\Schema\DatabaseSchemaData;
use App\Data\Schema\ForeignKeyData;
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

it('includes key_remapping section for tables with integer primary keys', function (): void {
    Storage::fake('local');

    $connection = makeDumpMysqlConnection('production-db');

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
                    new ColumnSchemaData(name: 'id', type: 'bigint', nullable: false, default: null, isPrimary: true),
                    new ColumnSchemaData(name: 'user_id', type: 'int', nullable: false, default: null, isPrimary: false),
                ],
                foreignKeys: [
                    new ForeignKeyData(columnName: 'user_id', referencedTable: 'users', referencedColumn: 'id'),
                ],
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

    $this->artisan('cloning:dump', ['--connection' => 'production-db', '--ci' => true])
        ->assertExitCode(ExitCode::Success->value);

    $yaml = Storage::disk('local')->get('production-db.cloning.yaml');
    expect($yaml)->toBeString();
    // Remapping is now written inline as a column strategy, not as a top-level section
    expect($yaml)->not->toContain('key_remapping:');
    expect($yaml)->toContain('strategy: remapping');
    expect($yaml)->toContain('- use: random_integer');
    expect($yaml)->toContain('- min: 100000');
    expect($yaml)->toContain('- max: 9999999');
    expect($yaml)->toContain('column: user_id');
    expect($yaml)->toContain('self_referential: false');
});

it('skips composite primary key tables from key remapping', function (): void {
    Storage::fake('local');

    $connection = makeDumpMysqlConnection('production-db');

    $schema = new DatabaseSchemaData(
        databaseName: 'mydb',
        tables: [
            new TableSchemaData(
                name: 'posts',
                columns: [
                    new ColumnSchemaData(name: 'id', type: 'int', nullable: false, default: null, isPrimary: true),
                    new ColumnSchemaData(name: 'title', type: 'varchar', nullable: false, default: null, isPrimary: false),
                ],
                foreignKeys: [],
            ),
            new TableSchemaData(
                name: 'tags',
                columns: [
                    new ColumnSchemaData(name: 'id', type: 'int', nullable: false, default: null, isPrimary: true),
                    new ColumnSchemaData(name: 'name', type: 'varchar', nullable: false, default: null, isPrimary: false),
                ],
                foreignKeys: [],
            ),
            new TableSchemaData(
                name: 'post_tag',
                columns: [
                    new ColumnSchemaData(name: 'post_id', type: 'int', nullable: false, default: null, isPrimary: true),
                    new ColumnSchemaData(name: 'tag_id', type: 'int', nullable: false, default: null, isPrimary: true),
                ],
                foreignKeys: [
                    new ForeignKeyData(columnName: 'post_id', referencedTable: 'posts', referencedColumn: 'id'),
                    new ForeignKeyData(columnName: 'tag_id', referencedTable: 'tags', referencedColumn: 'id'),
                ],
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

    $this->artisan('cloning:dump', ['--connection' => 'production-db', '--ci' => true])
        ->assertExitCode(ExitCode::Success->value);

    $yaml = Storage::disk('local')->get('production-db.cloning.yaml');
    expect($yaml)->toBeString();

    // posts and tags should have remapping (single integer PK)
    expect($yaml)->toContain('strategy: remapping');

    // post_tag (composite PK) should NOT have remapping
    // Its FK columns are listed in the parent tables' foreign_keys instead
    expect($yaml)->toContain('column: post_id');
    expect($yaml)->toContain('column: tag_id');

    // The pivot table should not have its own remapping entry
    // Count remapping occurrences — should be exactly 2 (posts.id + tags.id)
    expect(substr_count($yaml, 'strategy: remapping'))->toBe(2);
});

it('omits key_remapping section when no integer primary keys exist', function (): void {
    Storage::fake('local');

    $connection = makeDumpMysqlConnection('production-db');

    $schema = new DatabaseSchemaData(
        databaseName: 'mydb',
        tables: [
            new TableSchemaData(
                name: 'logs',
                columns: [
                    new ColumnSchemaData(name: 'uuid', type: 'varchar', nullable: false, default: null, isPrimary: true),
                    new ColumnSchemaData(name: 'message', type: 'text', nullable: false, default: null, isPrimary: false),
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

    $this->artisan('cloning:dump', ['--connection' => 'production-db', '--ci' => true])
        ->assertExitCode(ExitCode::Success->value);

    $yaml = Storage::disk('local')->get('production-db.cloning.yaml');
    expect($yaml)->not->toContain('strategy: remapping');
});

it('--all-columns includes keep-strategy columns in the YAML', function (): void {
    Storage::fake('local');

    $connection = makeDumpMysqlConnection('production-db');
    $schema = makeSimpleSchema(); // has id, email, password, created_at
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
        '--all-columns' => true,
        '--ci' => true,
    ])->assertExitCode(ExitCode::Success->value);

    $yaml = Storage::disk('local')->get('production-db.cloning.yaml');
    expect($yaml)->toBeString();
    // PII columns still present
    expect($yaml)->toContain('email:');
    expect($yaml)->toContain('strategy: fake');
    // Keep columns now also present
    expect($yaml)->toContain('created_at:');
    expect($yaml)->toContain('strategy: keep');
    // No 'no PII detected' comment since columns are explicitly listed
    expect($yaml)->not->toContain('# no PII detected');
});

it('--all-columns and --only-pii together return ValidationError', function (): void {
    Storage::fake('local');

    $connection = makeDumpMysqlConnection('production-db');

    $config = Mockery::mock(ConfigService::class);
    $config->shouldReceive('exists')->andReturn(true);
    $config->shouldReceive('getConnection')->with('production-db')->andReturn($connection);

    $this->app->instance(ConfigService::class, $config);

    $this->artisan('cloning:dump', [
        '--connection' => 'production-db',
        '--all-columns' => true,
        '--only-pii' => true,
        '--ci' => true,
    ])->assertExitCode(ExitCode::ValidationError->value);
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

it('writes enforce_column_types: true when --enforce-column-types flag is set', function (): void {
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
        '--enforce-column-types' => true,
        '--drop-unknown-tables' => true,
        '--drop-extra-columns' => true,
        '--ci' => true,
    ])->assertExitCode(ExitCode::Success->value);

    $yaml = Storage::disk('local')->get('production-db.cloning.yaml');
    expect($yaml)
        ->toContain('enforce_column_types: true')
        ->toContain('drop_unknown_tables: true')
        ->toContain('drop_extra_columns: true')
        ->toContain('disable_foreign_key_checks: true');
});

it('writes disable_foreign_key_checks: false when --no-disable-fk-checks flag is set', function (): void {
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
        '--no-disable-fk-checks' => true,
        '--ci' => true,
    ])->assertExitCode(ExitCode::Success->value);

    $yaml = Storage::disk('local')->get('production-db.cloning.yaml');
    expect($yaml)->toContain('disable_foreign_key_checks: false');
});

it('prompts for transfer options interactively and writes the chosen values', function (): void {
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

    $this->artisan('cloning:dump', ['--connection' => 'production-db'])
        ->expectsConfirmation('    Enforce column types on target?', 'yes')
        ->expectsConfirmation('    Drop unknown tables on target?', 'no')
        ->expectsConfirmation('    Drop extra columns on target?', 'no')
        ->expectsConfirmation('    Disable foreign key checks?', 'yes')
        ->assertExitCode(ExitCode::Success->value);

    $yaml = Storage::disk('local')->get('production-db.cloning.yaml');
    expect($yaml)
        ->toContain('enforce_column_types: true')
        ->toContain('drop_unknown_tables: false')
        ->toContain('disable_foreign_key_checks: true');
});
