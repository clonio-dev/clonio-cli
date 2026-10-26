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
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Yaml\Yaml;

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
    expect($yaml)->not->toContain('- min:');
    expect($yaml)->not->toContain('- max:');
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

it('writes remap_keys: false when {flag} is set', function (string $flag): void {
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
        $flag => true,
        '--ci' => true,
    ])->assertExitCode(ExitCode::Success->value);

    $yaml = Storage::disk('local')->get('production-db.cloning.yaml');
    expect($yaml)->toContain('remap_keys: false');
})->with(['--no-remap-keys', '--skip-remapping-keys']);

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
        ->expectsConfirmation('    Drop and re-create existing tables on target?', 'yes')
        ->expectsConfirmation('    Disable foreign key checks?', 'yes')
        ->expectsConfirmation('    Remap keys?', 'yes')
        ->assertExitCode(ExitCode::Success->value);

    $yaml = Storage::disk('local')->get('production-db.cloning.yaml');
    expect($yaml)
        ->toContain('enforce_column_types: true')
        ->toContain('drop_unknown_tables: false')
        ->toContain('recreate_tables: true')
        ->toContain('disable_foreign_key_checks: true')
        ->toContain('remap_keys: true');
});

it('interactive: prompts to select a connection from the choice list', function (): void {
    Storage::fake('local');

    $connection = makeDumpMysqlConnection('production-db');
    $schema = makeSimpleSchema();
    $piiSet = makePiiMatcherSet();

    $config = Mockery::mock(ConfigService::class);
    $config->shouldReceive('exists')->andReturn(true);
    $config->shouldReceive('getConnections')->andReturn([
        'production-db' => $connection,
        'staging-db' => makeDumpMysqlConnection('staging-db'),
    ]);
    $config->shouldReceive('getConnection')->with('production-db')->andReturn($connection);

    $inspector = Mockery::mock(SchemaInspector::class);
    $inspector->shouldReceive('inspect')->andReturn($schema);

    $piiLoader = Mockery::mock(PiiMatcherLoader::class);
    $piiLoader->shouldReceive('load')->andReturn($piiSet);

    $this->app->instance(ConfigService::class, $config);
    $this->app->instance(SchemaInspector::class, $inspector);
    $this->app->instance(PiiMatcherLoader::class, $piiLoader);

    $this->artisan('cloning:dump')
        ->expectsChoice('Select a connection to inspect', 'production-db', ['production-db', 'staging-db'])
        ->expectsConfirmation('    Enforce column types on target?', 'no')
        ->expectsConfirmation('    Drop unknown tables on target?', 'no')
        ->expectsConfirmation('    Drop extra columns on target?', 'no')
        ->expectsConfirmation('    Drop and re-create existing tables on target?', 'no')
        ->expectsConfirmation('    Disable foreign key checks?', 'yes')
        ->expectsConfirmation('    Remap keys?', 'yes')
        ->assertExitCode(ExitCode::Success->value);

    expect(Storage::disk('local')->exists('production-db.cloning.yaml'))->toBeTrue();
});

it('returns Success(0) when an existing file overwrite is declined', function (): void {
    Storage::fake('local');
    Storage::disk('local')->put('production-db.cloning.yaml', 'old content');

    $connection = makeDumpMysqlConnection('production-db');

    $config = Mockery::mock(ConfigService::class);
    $config->shouldReceive('exists')->andReturn(true);
    $config->shouldReceive('getConnection')->with('production-db')->andReturn($connection);

    // Inspector / PII loader must NOT be called once overwrite is declined.
    $inspector = Mockery::mock(SchemaInspector::class);
    $inspector->shouldNotReceive('inspect');

    $this->app->instance(ConfigService::class, $config);
    $this->app->instance(SchemaInspector::class, $inspector);

    $this->artisan('cloning:dump', ['--connection' => 'production-db'])
        ->expectsConfirmation('File production-db.cloning.yaml already exists. Overwrite? [y/N]', 'no')
        ->assertExitCode(ExitCode::Success->value);

    // Untouched
    expect(Storage::disk('local')->get('production-db.cloning.yaml'))->toBe('old content');
});

it('returns ConnectionError(3) when schema inspection throws', function (): void {
    Storage::fake('local');

    $connection = makeDumpMysqlConnection('production-db');

    $config = Mockery::mock(ConfigService::class);
    $config->shouldReceive('exists')->andReturn(true);
    $config->shouldReceive('getConnection')->with('production-db')->andReturn($connection);

    $inspector = Mockery::mock(SchemaInspector::class);
    $inspector->shouldReceive('inspect')->andThrow(new RuntimeException('connection refused'));

    $this->app->instance(ConfigService::class, $config);
    $this->app->instance(SchemaInspector::class, $inspector);

    $this->artisan('cloning:dump', ['--connection' => 'production-db', '--ci' => true])
        ->expectsOutputToContain('Failed to inspect database: connection refused')
        ->assertExitCode(ExitCode::ConnectionError->value);
});

it('skips foreign keys that reference a table without a single integer primary key', function (): void {
    Storage::fake('local');

    $connection = makeDumpMysqlConnection('production-db');

    // `events` has an integer PK (so key remapping is built), but its FK
    // references `accounts`, whose PK is a varchar (not in intPkByTable).
    // This exercises the FK skip branch (DumpCommand line 339).
    $schema = new DatabaseSchemaData(
        databaseName: 'mydb',
        tables: [
            new TableSchemaData(
                name: 'accounts',
                columns: [
                    new ColumnSchemaData(name: 'uuid', type: 'varchar', nullable: false, default: null, isPrimary: true),
                    new ColumnSchemaData(name: 'name', type: 'varchar', nullable: false, default: null, isPrimary: false),
                ],
                foreignKeys: [],
            ),
            new TableSchemaData(
                name: 'events',
                columns: [
                    new ColumnSchemaData(name: 'id', type: 'int', nullable: false, default: null, isPrimary: true),
                    new ColumnSchemaData(name: 'account_uuid', type: 'varchar', nullable: false, default: null, isPrimary: false),
                ],
                foreignKeys: [
                    new ForeignKeyData(columnName: 'account_uuid', referencedTable: 'accounts', referencedColumn: 'uuid'),
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
    // events.id is remapped, but the FK to accounts is skipped (no remapping FK entry).
    expect($yaml)->toContain('strategy: remapping');
    expect($yaml)->not->toContain('column: account_uuid');
});

function makeFrameworkSchema(): DatabaseSchemaData
{
    $idColumn = new ColumnSchemaData(name: 'id', type: 'int', nullable: false, default: null, isPrimary: true);

    return new DatabaseSchemaData(
        databaseName: 'mydb',
        tables: [
            new TableSchemaData(
                name: 'users',
                columns: [
                    $idColumn,
                    new ColumnSchemaData(name: 'email', type: 'varchar', nullable: false, default: null, isPrimary: false),
                ],
                foreignKeys: [],
            ),
            new TableSchemaData(
                name: 'cache',
                columns: [new ColumnSchemaData(name: 'key', type: 'varchar', nullable: false, default: null, isPrimary: true)],
                foreignKeys: [],
            ),
            new TableSchemaData(name: 'telescope_entries', columns: [$idColumn], foreignKeys: []),
        ],
    );
}

function bindFrameworkDumpMocks(): void
{
    $connection = makeDumpMysqlConnection('production-db');

    $config = Mockery::mock(ConfigService::class);
    $config->shouldReceive('exists')->andReturn(true);
    $config->shouldReceive('getConnection')->with('production-db')->andReturn($connection);

    $inspector = Mockery::mock(SchemaInspector::class);
    $inspector->shouldReceive('inspect')->andReturn(makeFrameworkSchema());

    $piiLoader = Mockery::mock(PiiMatcherLoader::class);
    $piiLoader->shouldReceive('load')->andReturn(makePiiMatcherSet());

    app()->instance(ConfigService::class, $config);
    app()->instance(SchemaInspector::class, $inspector);
    app()->instance(PiiMatcherLoader::class, $piiLoader);
}

/**
 * @return array<string, mixed>
 */
function dumpedTables(): array
{
    $parsed = Yaml::parse((string) Storage::disk('local')->get('production-db.cloning.yaml'));
    assert(is_array($parsed) && is_array($parsed['tables']));

    return $parsed['tables'];
}

it('sets known framework tables to the schema strategy in --ci mode', function (): void {
    Storage::fake('local');
    bindFrameworkDumpMocks();

    $this->artisan('cloning:dump', ['--connection' => 'production-db', '--ci' => true])
        ->assertExitCode(ExitCode::Success->value);

    $tables = dumpedTables();
    expect($tables['cache']['rows']['strategy'])->toBe('schema')
        ->and($tables['telescope_entries']['rows']['strategy'])->toBe('schema')
        ->and($tables['users']['rows']['strategy'])->toBe('full');
});

it('keeps framework tables on full with --no-framework-tables', function (): void {
    Storage::fake('local');
    bindFrameworkDumpMocks();

    $this->artisan('cloning:dump', ['--connection' => 'production-db', '--ci' => true, '--no-framework-tables' => true])
        ->assertExitCode(ExitCode::Success->value);

    expect(dumpedTables()['cache']['rows']['strategy'])->toBe('full');
});

it('keeps schema-only framework tables with --only-pii', function (): void {
    Storage::fake('local');
    bindFrameworkDumpMocks();

    $this->artisan('cloning:dump', ['--connection' => 'production-db', '--ci' => true, '--only-pii' => true])
        ->assertExitCode(ExitCode::Success->value);

    $tables = dumpedTables();
    expect($tables)->toHaveKeys(['users', 'cache', 'telescope_entries'])
        ->and($tables['cache']['rows']['strategy'])->toBe('schema');
});

it('asks before applying the schema strategy to framework tables', function (string $answer, string $expected): void {
    Storage::fake('local');
    bindFrameworkDumpMocks();

    $this->artisan('cloning:dump', ['--connection' => 'production-db'])
        ->expectsOutputToContain('Known framework tables')
        ->expectsConfirmation('  Copy only the schema of these tables, without rows?', $answer)
        ->expectsConfirmation('    Enforce column types on target?', 'no')
        ->expectsConfirmation('    Drop unknown tables on target?', 'no')
        ->expectsConfirmation('    Drop extra columns on target?', 'no')
        ->expectsConfirmation('    Drop and re-create existing tables on target?', 'no')
        ->expectsConfirmation('    Disable foreign key checks?', 'yes')
        ->expectsConfirmation('    Remap keys?', 'no')
        ->assertExitCode(ExitCode::Success->value);

    expect(dumpedTables()['cache']['rows']['strategy'])->toBe($expected);
})->with([
    'accepted' => ['yes', 'schema'],
    'declined' => ['no', 'full'],
]);

function g5_bindDumpMocks(ConnectionData $connection, DatabaseSchemaData $schema): void
{
    $config = Mockery::mock(ConfigService::class);
    $config->shouldReceive('exists')->andReturn(true);
    $config->shouldReceive('getConnections')->andReturn([
        $connection->name => $connection,
        'other-db' => makeDumpMysqlConnection('other-db'),
    ]);
    $config->shouldReceive('getConnection')->with($connection->name)->andReturn($connection);

    $inspector = Mockery::mock(SchemaInspector::class);
    $inspector->shouldReceive('inspect')->with($connection)->andReturn($schema);

    $piiLoader = Mockery::mock(PiiMatcherLoader::class);
    $piiLoader->shouldReceive('load')->andReturn(makePiiMatcherSet());

    app()->instance(ConfigService::class, $config);
    app()->instance(SchemaInspector::class, $inspector);
    app()->instance(PiiMatcherLoader::class, $piiLoader);
}

/**
 * Runs cloning:dump non-interactively (prompts resolve to their defaults)
 * without console mocking.
 *
 * @param  array<string, mixed>  $parameters
 * @return array{0: int, 1: string}
 */
function g5_runDumpDefaults(array $parameters): array
{
    $exitCode = Artisan::call('cloning:dump', [...$parameters, '--no-interaction' => true]);

    return [$exitCode, Artisan::output()];
}

function g5_keyRemappingSchema(): DatabaseSchemaData
{
    return new DatabaseSchemaData(
        databaseName: 'mydb',
        tables: [
            // Composite primary key comes first: must be skipped, not stop the scan.
            new TableSchemaData(
                name: 'pivot',
                columns: [
                    new ColumnSchemaData(name: 'a_id', type: 'int', nullable: false, default: null, isPrimary: true),
                    new ColumnSchemaData(name: 'b_id', type: 'int', nullable: false, default: null, isPrimary: true),
                ],
                foreignKeys: [],
            ),
            new TableSchemaData(
                name: 'accounts',
                columns: [
                    new ColumnSchemaData(name: 'id', type: 'SERIAL', nullable: false, default: null, isPrimary: true),
                    new ColumnSchemaData(name: 'email', type: 'varchar', nullable: false, default: null, isPrimary: false),
                ],
                foreignKeys: [],
            ),
            new TableSchemaData(
                name: 'logs',
                columns: [
                    new ColumnSchemaData(name: 'id', type: 'bigserial', nullable: false, default: null, isPrimary: true),
                ],
                foreignKeys: [],
            ),
            new TableSchemaData(
                name: 'events',
                columns: [
                    new ColumnSchemaData(name: 'id', type: 'INTEGER', nullable: false, default: null, isPrimary: true),
                    new ColumnSchemaData(name: 'pivot_a_id', type: 'int', nullable: true, default: null, isPrimary: false),
                    new ColumnSchemaData(name: 'account_id', type: 'int', nullable: true, default: null, isPrimary: false),
                ],
                foreignKeys: [
                    // Skipped FK first: must not stop the scan of later FKs.
                    new ForeignKeyData(columnName: 'pivot_a_id', referencedTable: 'pivot', referencedColumn: 'a_id'),
                    new ForeignKeyData(columnName: 'account_id', referencedTable: 'accounts', referencedColumn: 'id'),
                ],
            ),
            new TableSchemaData(
                name: 'cache',
                columns: [new ColumnSchemaData(name: 'key', type: 'varchar', nullable: false, default: null, isPrimary: true)],
                foreignKeys: [],
            ),
        ],
    );
}

it('prints the exact progress and summary report and writes the exact YAML', function (): void {
    Storage::fake('local');
    $this->withoutMockingConsoleOutput();
    g5_bindDumpMocks(makeDumpMysqlConnection('production-db'), makeSimpleSchema());

    [$exitCode, $output] = g5_runDumpDefaults(['--connection' => 'production-db', '--output' => '', '--locale' => '']);

    expect($exitCode)->toBe(ExitCode::Success->value)
        ->and($output)->toBe(<<<'TXT'

  Inspecting "production-db" (mysql @ db.prod.io:3306) ...

  Schema fetched: 1 tables, 4 columns

  Transfer options:

  PII auto-detection:
    ✓  2 columns matched across 1 table
    ○  2 columns set to keep

  Written: ./production-db.cloning.yaml

  Review the file, adjust strategies as needed, then run:
    clonio cloning:run production-db.cloning.yaml --target <name>


TXT)
        ->and(Storage::disk('local')->get('production-db.cloning.yaml'))->toBe(<<<'YAML'
# yaml-language-server: $schema=https://clonio.dev/schema/cloning-v1.json
version: "1"
connection: production-db

options:
  chunk_size: 1000
  enforce_column_types: false
  drop_unknown_tables: false
  drop_extra_columns: false
  recreate_tables: false
  disable_foreign_key_checks: true
  remap_keys: true
  faker_locale: en_US

tables:
  users:
    rows:
      strategy: full
      clear: delete
    columns:
      # Primary Key — remapped
      id:
        strategy: remapping
        arguments:
          - use: random_integer
          - foreign_keys: []
      # PII: Email Address
      email:
        strategy: fake
        faker_method: safeEmail
        faker_arguments: []
      # PII: Password / Secret
      password:
        strategy: hash
        algorithm: sha256
        salt: ""


YAML);
});

it('reports framework tables, local host without port and remaps serial/integer keys', function (): void {
    Storage::fake('local');
    $this->withoutMockingConsoleOutput();
    $connection = new ConnectionData(name: 'pg', type: DatabaseConnectionType::PostgreSQL, host: null, port: null, database: 'mydb', schema: null, username: 'u', password: 'p', isProduction: false);
    g5_bindDumpMocks($connection, g5_keyRemappingSchema());

    [$exitCode, $output] = g5_runDumpDefaults(['--connection' => 'pg']);

    expect($exitCode)->toBe(ExitCode::Success->value)
        ->and($output)->toBe(<<<'TXT'

  Inspecting "pg" (pgsql @ local) ...

  Schema fetched: 5 tables, 9 columns

  Known framework tables (cache, sessions, queues, tokens, logs):
    cache (Laravel cache)

  Transfer options:

  PII auto-detection:
    ✓  1 column matched across 5 tables
    ○  8 columns set to keep

  Written: ./pg.cloning.yaml

  Review the file, adjust strategies as needed, then run:
    clonio cloning:run pg.cloning.yaml --target <name>


TXT)
        ->and(Storage::disk('local')->get('pg.cloning.yaml'))->toBe(<<<'YAML'
# yaml-language-server: $schema=https://clonio.dev/schema/cloning-v1.json
version: "1"
connection: pg

options:
  chunk_size: 1000
  enforce_column_types: false
  drop_unknown_tables: false
  drop_extra_columns: false
  recreate_tables: false
  disable_foreign_key_checks: true
  remap_keys: true
  faker_locale: en_US

tables:
  pivot:
    rows:
      strategy: full
      clear: delete
    # no PII detected — no columns listed; all kept as-is

  accounts:
    rows:
      strategy: full
      clear: delete
    columns:
      # Primary Key — remapped
      id:
        strategy: remapping
        arguments:
          - use: random_integer
          - foreign_keys: [{table: events, column: account_id, self_referential: false}]
      # PII: Email Address
      email:
        strategy: fake
        faker_method: safeEmail
        faker_arguments: []

  logs:
    rows:
      strategy: full
      clear: delete
    columns:
      # Primary Key — remapped
      id:
        strategy: remapping
        arguments:
          - use: random_integer
          - foreign_keys: []

  events:
    rows:
      strategy: full
      clear: delete
    columns:
      # Primary Key — remapped
      id:
        strategy: remapping
        arguments:
          - use: random_integer
          - foreign_keys: []

  cache:
    rows:
      strategy: schema
      clear: delete
    # no PII detected — no columns listed; all kept as-is


YAML);
});

it('uses singular wording for a single PII column and a single keep column', function (): void {
    Storage::fake('local');
    $this->withoutMockingConsoleOutput();
    $schema = new DatabaseSchemaData(
        databaseName: 'mydb',
        tables: [
            new TableSchemaData(
                name: 'contacts',
                columns: [
                    new ColumnSchemaData(name: 'email', type: 'varchar', nullable: false, default: null, isPrimary: false),
                    new ColumnSchemaData(name: 'note', type: 'text', nullable: true, default: null, isPrimary: false),
                ],
                foreignKeys: [],
            ),
        ],
    );
    g5_bindDumpMocks(makeDumpMysqlConnection('production-db'), $schema);

    [$exitCode, $output] = g5_runDumpDefaults(['--connection' => 'production-db', '--locale' => 'de_DE', '--output' => 'custom.yaml']);

    expect($exitCode)->toBe(ExitCode::Success->value)
        ->and($output)->toContain("    ✓  1 column matched across 1 table\n    ○  1 column set to keep\n")
        ->and($output)->toContain("  Written: ./custom.yaml\n")
        ->and(Storage::disk('local')->get('custom.yaml'))->toContain("  faker_locale: de_DE\n");
});

it('suppresses all non-error output in --ci mode', function (): void {
    Storage::fake('local');
    $this->withoutMockingConsoleOutput();
    g5_bindDumpMocks(makeDumpMysqlConnection('production-db'), g5_keyRemappingSchema());

    [$exitCode, $output] = g5_runDumpDefaults(['--connection' => 'production-db', '--ci' => true]);

    expect($exitCode)->toBe(ExitCode::Success->value)
        ->and($output)->toBe('')
        ->and(dumpedTables()['cache']['rows']['strategy'])->toBe('schema');
});

it('defaults to the first connection when none is given non-interactively', function (): void {
    Storage::fake('local');
    $this->withoutMockingConsoleOutput();
    g5_bindDumpMocks(makeDumpMysqlConnection('production-db'), makeSimpleSchema());

    [$exitCode] = g5_runDumpDefaults([]);

    expect($exitCode)->toBe(ExitCode::Success->value)
        ->and(Storage::disk('local')->exists('production-db.cloning.yaml'))->toBeTrue()
        ->and(Storage::disk('local')->exists('other-db.cloning.yaml'))->toBeFalse();
});

it('inspects the connection picked from the interactive list', function (): void {
    Storage::fake('local');
    g5_bindDumpMocks(makeDumpMysqlConnection('production-db'), makeSimpleSchema());
    $other = makeDumpMysqlConnection('other-db');
    $config = app(ConfigService::class);
    $config->shouldReceive('getConnection')->with('other-db')->andReturn($other);
    app(SchemaInspector::class)->shouldReceive('inspect')->with($other)->andReturn(makeSimpleSchema());

    $this->artisan('cloning:dump', ['--connection' => '', '--ci' => false, '--no-interaction' => false])
        ->expectsChoice('Select a connection to inspect', 'other-db', ['production-db', 'other-db'])
        ->expectsConfirmation('    Enforce column types on target?', 'no')
        ->expectsConfirmation('    Drop unknown tables on target?', 'no')
        ->expectsConfirmation('    Drop extra columns on target?', 'no')
        ->expectsConfirmation('    Drop and re-create existing tables on target?', 'no')
        ->expectsConfirmation('    Disable foreign key checks?', 'yes')
        ->expectsConfirmation('    Remap keys?', 'yes')
        ->assertExitCode(ExitCode::Success->value);

    expect(Storage::disk('local')->exists('other-db.cloning.yaml'))->toBeTrue()
        ->and(Storage::disk('local')->exists('production-db.cloning.yaml'))->toBeFalse();
});

it('treats an empty --connection as missing in --ci mode', function (): void {
    Storage::fake('local');
    g5_bindDumpMocks(makeDumpMysqlConnection('production-db'), makeSimpleSchema());

    $this->artisan('cloning:dump', ['--connection' => '', '--ci' => true])
        ->expectsOutputToContain('--connection is required in --ci mode.')
        ->assertExitCode(ExitCode::ValidationError->value);
});

it('keeps an existing file when overwrite is not confirmed (default no)', function (): void {
    Storage::fake('local');
    Storage::disk('local')->put('production-db.cloning.yaml', 'old content');
    $this->withoutMockingConsoleOutput();
    g5_bindDumpMocks(makeDumpMysqlConnection('production-db'), makeSimpleSchema());

    [$exitCode, $output] = g5_runDumpDefaults(['--connection' => 'production-db']);

    expect($exitCode)->toBe(ExitCode::Success->value)
        ->and($output)->toBe('')
        ->and(Storage::disk('local')->get('production-db.cloning.yaml'))->toBe('old content');
});

it('explains why --all-columns and --only-pii conflict', function (): void {
    Storage::fake('local');
    g5_bindDumpMocks(makeDumpMysqlConnection('production-db'), makeSimpleSchema());

    $this->artisan('cloning:dump', ['--connection' => 'production-db', '--all-columns' => true, '--only-pii' => true, '--ci' => true])
        ->expectsOutputToContain('--all-columns and --only-pii cannot be used together.')
        ->assertExitCode(ExitCode::ValidationError->value);
});
