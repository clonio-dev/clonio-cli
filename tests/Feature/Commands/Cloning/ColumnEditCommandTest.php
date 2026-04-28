<?php

declare(strict_types=1);

use App\Data\ConnectionData;
use App\Data\Schema\ColumnSchemaData;
use App\Data\Schema\DatabaseSchemaData;
use App\Data\Schema\ForeignKeyData;
use App\Data\Schema\TableSchemaData;
use App\Enums\DatabaseConnectionType;
use App\Enums\ExitCode;
use App\Services\Config\ConfigService;
use App\Services\Database\DatabaseConnectionService;
use App\Services\Schema\SchemaInspector;
use Illuminate\Support\Facades\Storage;

function makeRemappingConnection(): ConnectionData
{
    return new ConnectionData(
        name: 'production-db',
        type: DatabaseConnectionType::Sqlite,
        host: null,
        port: null,
        database: ':memory:',
        schema: null,
        username: null,
        password: '',
        isProduction: true,
    );
}

/**
 * @param  list<array{name: string, type: string, isPrimary: bool, unsigned?: bool}>  $columns
 * @param  list<array{columnName: string, referencedTable: string, referencedColumn: string}>  $foreignKeys
 */
function makeRemappingTable(string $name, array $columns, array $foreignKeys = []): TableSchemaData
{
    return new TableSchemaData(
        name: $name,
        columns: array_map(
            static fn (array $col): ColumnSchemaData => new ColumnSchemaData(
                name: $col['name'],
                type: $col['type'],
                nullable: false,
                default: null,
                isPrimary: $col['isPrimary'],
                unsigned: $col['unsigned'] ?? false,
            ),
            $columns,
        ),
        foreignKeys: array_map(
            static fn (array $fk): ForeignKeyData => new ForeignKeyData(
                columnName: $fk['columnName'],
                referencedTable: $fk['referencedTable'],
                referencedColumn: $fk['referencedColumn'],
            ),
            $foreignKeys,
        ),
    );
}

/**
 * @param  list<TableSchemaData>  $tables
 */
function bindRemappingDeps(array $tables, ?Throwable $openThrows = null): void
{
    $configMock = Mockery::mock(ConfigService::class);
    $configMock->shouldReceive('getConnection')
        ->with('production-db')
        ->andReturn(makeRemappingConnection());
    $configMock->shouldReceive('getConnection')
        ->andReturn(null);

    app()->instance(ConfigService::class, $configMock);

    $connectorMock = Mockery::mock(DatabaseConnectionService::class);
    if ($openThrows instanceof Throwable) {
        $connectorMock->shouldReceive('open')->andThrow($openThrows);
    } else {
        $connectorMock->shouldReceive('open')->andReturn('source');
    }

    app()->instance(DatabaseConnectionService::class, $connectorMock);

    $inspectorMock = Mockery::mock(SchemaInspector::class);
    $inspectorMock->shouldReceive('inspect')
        ->andReturn(new DatabaseSchemaData('test', $tables));

    app()->instance(SchemaInspector::class, $inspectorMock);
}

function makeTestCloningYaml(): string
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
      clear: delete
    columns:
      email:
        strategy: fake
        faker_method: safeEmail
        faker_arguments: []
      password:
        strategy: hash
        algorithm: sha256
        salt: ""
YAML;
}

it('exits with IoError when file does not exist', function (): void {
    Storage::fake('local');

    $this->artisan('cloning:column:edit', ['file' => 'missing.cloning.yaml'])
        ->expectsOutputToContain('File not found')
        ->assertExitCode(ExitCode::IoError->value);
});

it('exits with ValidationError when table not found', function (): void {
    Storage::fake('local');
    Storage::disk('local')->put('test.cloning.yaml', makeTestCloningYaml());

    $this->artisan('cloning:column:edit', [
        'file' => 'test.cloning.yaml',
        '--table' => 'nonexistent',
        '--column' => 'email',
        '--strategy' => 'keep',
    ])
        ->expectsOutputToContain("Table 'nonexistent' not found")
        ->assertExitCode(ExitCode::ValidationError->value);
});

it('exits with ValidationError for unknown strategy', function (): void {
    Storage::fake('local');
    Storage::disk('local')->put('test.cloning.yaml', makeTestCloningYaml());

    $this->artisan('cloning:column:edit', [
        'file' => 'test.cloning.yaml',
        '--table' => 'users',
        '--column' => 'email',
        '--strategy' => 'unknown_strategy',
    ])
        ->expectsOutputToContain("Unknown strategy: 'unknown_strategy'")
        ->assertExitCode(ExitCode::ValidationError->value);
});

it('updates column to keep strategy', function (): void {
    Storage::fake('local');
    Storage::disk('local')->put('test.cloning.yaml', makeTestCloningYaml());

    $this->artisan('cloning:column:edit', [
        'file' => 'test.cloning.yaml',
        '--table' => 'users',
        '--column' => 'email',
        '--strategy' => 'keep',
    ])
        ->expectsConfirmation('Apply this change?', 'yes')
        ->expectsOutputToContain('Updated users.email')
        ->assertExitCode(ExitCode::Success->value);

    $content = Storage::disk('local')->get('test.cloning.yaml');
    expect($content)->toBeString();
    expect($content)->toContain('strategy: keep');
});

it('updates column to fake strategy via flags', function (): void {
    Storage::fake('local');
    Storage::disk('local')->put('test.cloning.yaml', makeTestCloningYaml());

    $this->artisan('cloning:column:edit', [
        'file' => 'test.cloning.yaml',
        '--table' => 'users',
        '--column' => 'email',
        '--strategy' => 'fake',
        '--faker-method' => 'userName',
        '--faker-arguments' => '',
    ])
        ->expectsConfirmation('Apply this change?', 'yes')
        ->expectsOutputToContain('Updated users.email')
        ->assertExitCode(ExitCode::Success->value);

    $content = Storage::disk('local')->get('test.cloning.yaml');
    expect($content)->toContain('faker_method: userName');
});

it('updates column to hash strategy via flags', function (): void {
    Storage::fake('local');
    Storage::disk('local')->put('test.cloning.yaml', makeTestCloningYaml());

    $this->artisan('cloning:column:edit', [
        'file' => 'test.cloning.yaml',
        '--table' => 'users',
        '--column' => 'password',
        '--strategy' => 'hash',
        '--algorithm' => 'sha512',
        '--salt' => 'my-salt',
    ])
        ->expectsConfirmation('Apply this change?', 'yes')
        ->expectsOutputToContain('Updated users.password')
        ->assertExitCode(ExitCode::Success->value);

    $content = Storage::disk('local')->get('test.cloning.yaml');
    expect($content)->toContain('algorithm: sha512');
    expect($content)->toContain('salt: my-salt');
});

it('updates column to mask strategy via flags', function (): void {
    Storage::fake('local');
    Storage::disk('local')->put('test.cloning.yaml', makeTestCloningYaml());

    $this->artisan('cloning:column:edit', [
        'file' => 'test.cloning.yaml',
        '--table' => 'users',
        '--column' => 'email',
        '--strategy' => 'mask',
        '--visible-chars' => '4',
        '--mask-char' => '#',
        '--preserve-format' => true,
    ])
        ->expectsConfirmation('Apply this change?', 'yes')
        ->expectsOutputToContain('Updated users.email')
        ->assertExitCode(ExitCode::Success->value);

    $content = Storage::disk('local')->get('test.cloning.yaml');
    expect($content)->toContain('visible_chars: 4');
    expect($content)->toContain("mask_char: '#'");
    expect($content)->toContain('preserve_format: true');
});

it('updates column to static strategy via flags', function (): void {
    Storage::fake('local');
    Storage::disk('local')->put('test.cloning.yaml', makeTestCloningYaml());

    $this->artisan('cloning:column:edit', [
        'file' => 'test.cloning.yaml',
        '--table' => 'users',
        '--column' => 'email',
        '--strategy' => 'static',
        '--value' => 'redacted@example.com',
    ])
        ->expectsConfirmation('Apply this change?', 'yes')
        ->expectsOutputToContain('Updated users.email')
        ->assertExitCode(ExitCode::Success->value);

    $content = Storage::disk('local')->get('test.cloning.yaml');
    expect($content)->toContain('value: redacted@example.com');
});

it('cancels when user declines confirmation', function (): void {
    Storage::fake('local');
    Storage::disk('local')->put('test.cloning.yaml', makeTestCloningYaml());
    $original = Storage::disk('local')->get('test.cloning.yaml');

    $this->artisan('cloning:column:edit', [
        'file' => 'test.cloning.yaml',
        '--table' => 'users',
        '--column' => 'email',
        '--strategy' => 'keep',
    ])
        ->expectsConfirmation('Apply this change?', 'no')
        ->expectsOutputToContain('Cancelled.')
        ->assertExitCode(ExitCode::Success->value);

    // File should be unchanged
    expect(Storage::disk('local')->get('test.cloning.yaml'))->toBe($original);
});

it('shows no-arguments hint and skips faker_arguments prompt for no-arg methods', function (): void {
    Storage::fake('local');
    Storage::disk('local')->put('test.cloning.yaml', makeTestCloningYaml());

    // firstName takes no arguments — the hint should appear and no argument prompt shown
    $this->artisan('cloning:column:edit', [
        'file' => 'test.cloning.yaml',
        '--table' => 'users',
        '--column' => 'email',
        '--strategy' => 'fake',
        '--faker-method' => 'firstName',
        // No --faker-arguments flag: the command should skip asking for them
    ])
        ->expectsOutputToContain('firstName() takes no arguments')
        ->expectsConfirmation('Apply this change?', 'yes')
        ->assertExitCode(ExitCode::Success->value);

    $content = Storage::disk('local')->get('test.cloning.yaml');
    expect($content)->toContain('faker_method: firstName');
    // faker_arguments should be written as an empty collection ([] or {})
    expect($content)->toMatch('/faker_arguments:\s*(\[\]|\{.*\})/');
});

it('shows argument hint for methods that accept arguments', function (): void {
    Storage::fake('local');
    Storage::disk('local')->put('test.cloning.yaml', makeTestCloningYaml());

    $this->artisan('cloning:column:edit', [
        'file' => 'test.cloning.yaml',
        '--table' => 'users',
        '--column' => 'email',
        '--strategy' => 'fake',
        '--faker-method' => 'numberBetween',
        '--faker-arguments' => '1,100',
    ])
        ->expectsOutputToContain('numberBetween() arguments:')
        ->expectsConfirmation('Apply this change?', 'yes')
        ->assertExitCode(ExitCode::Success->value);

    $content = Storage::disk('local')->get('test.cloning.yaml');
    expect($content)->toContain('faker_method: numberBetween');
});

it('updates column to remapping strategy with auto-detected foreign keys', function (): void {
    Storage::fake('local');
    Storage::disk('local')->put('test.cloning.yaml', makeTestCloningYaml());

    bindRemappingDeps([
        makeRemappingTable('users', [
            ['name' => 'id', 'type' => 'int', 'isPrimary' => true, 'unsigned' => true],
            ['name' => 'email', 'type' => 'varchar', 'isPrimary' => false],
        ]),
        makeRemappingTable('posts', [
            ['name' => 'id', 'type' => 'int', 'isPrimary' => true],
            ['name' => 'user_id', 'type' => 'int', 'isPrimary' => false],
        ], [
            ['columnName' => 'user_id', 'referencedTable' => 'users', 'referencedColumn' => 'id'],
        ]),
    ]);

    $this->artisan('cloning:column:edit', [
        'file' => 'test.cloning.yaml',
        '--table' => 'users',
        '--column' => 'id',
        '--strategy' => 'remapping',
        '--remapping-use' => 'random_integer',
    ])
        ->expectsConfirmation('Apply this change?', 'yes')
        ->expectsOutputToContain('Updated users.id')
        ->assertExitCode(ExitCode::Success->value);

    $content = Storage::disk('local')->get('test.cloning.yaml');
    expect($content)->toContain('strategy: remapping');
    expect($content)->toContain('use: random_integer');
    expect($content)->toContain('table: posts');
    expect($content)->toContain('column: user_id');
    expect($content)->toContain('self_referential: false');
});

it('updates column to remapping strategy with new_uuid use', function (): void {
    Storage::fake('local');
    Storage::disk('local')->put('test.cloning.yaml', makeTestCloningYaml());

    bindRemappingDeps([
        makeRemappingTable('users', [
            ['name' => 'id', 'type' => 'uuid', 'isPrimary' => true],
        ]),
    ]);

    $this->artisan('cloning:column:edit', [
        'file' => 'test.cloning.yaml',
        '--table' => 'users',
        '--column' => 'id',
        '--strategy' => 'remapping',
        '--remapping-use' => 'new_uuid',
    ])
        ->expectsConfirmation('Apply this change?', 'yes')
        ->assertExitCode(ExitCode::Success->value);

    $content = Storage::disk('local')->get('test.cloning.yaml');
    expect($content)->toContain('use: new_uuid');
    expect($content)->toContain('foreign_keys: []');
});

it('detects self-referential foreign keys for remapping', function (): void {
    Storage::fake('local');
    Storage::disk('local')->put('test.cloning.yaml', makeTestCloningYaml());

    bindRemappingDeps([
        makeRemappingTable('users', [
            ['name' => 'id', 'type' => 'int', 'isPrimary' => true],
            ['name' => 'parent_id', 'type' => 'int', 'isPrimary' => false],
        ], [
            ['columnName' => 'parent_id', 'referencedTable' => 'users', 'referencedColumn' => 'id'],
        ]),
    ]);

    $this->artisan('cloning:column:edit', [
        'file' => 'test.cloning.yaml',
        '--table' => 'users',
        '--column' => 'id',
        '--strategy' => 'remapping',
        '--remapping-use' => 'random_integer',
    ])
        ->expectsConfirmation('Apply this change?', 'yes')
        ->assertExitCode(ExitCode::Success->value);

    $content = Storage::disk('local')->get('test.cloning.yaml');
    expect($content)->toContain('column: parent_id');
    expect($content)->toContain('self_referential: true');
});

it('hard-fails when target column is not a primary key', function (): void {
    Storage::fake('local');
    $original = makeTestCloningYaml();
    Storage::disk('local')->put('test.cloning.yaml', $original);

    bindRemappingDeps([
        makeRemappingTable('users', [
            ['name' => 'id', 'type' => 'int', 'isPrimary' => true],
            ['name' => 'email', 'type' => 'varchar', 'isPrimary' => false],
        ]),
    ]);

    $this->artisan('cloning:column:edit', [
        'file' => 'test.cloning.yaml',
        '--table' => 'users',
        '--column' => 'email',
        '--strategy' => 'remapping',
        '--remapping-use' => 'random_integer',
    ])
        ->expectsOutputToContain("'users.email' is not a primary key")
        ->assertExitCode(ExitCode::ValidationError->value);

    expect(Storage::disk('local')->get('test.cloning.yaml'))->toBe($original);
});

it('rejects unknown --remapping-use value', function (): void {
    Storage::fake('local');
    Storage::disk('local')->put('test.cloning.yaml', makeTestCloningYaml());

    $this->artisan('cloning:column:edit', [
        'file' => 'test.cloning.yaml',
        '--table' => 'users',
        '--column' => 'id',
        '--strategy' => 'remapping',
        '--remapping-use' => 'bogus',
    ])
        ->expectsOutputToContain("Unknown remapping use: 'bogus'")
        ->assertExitCode(ExitCode::ValidationError->value);
});

it('falls back to empty foreign_keys when source connection is unreachable', function (): void {
    Storage::fake('local');
    Storage::disk('local')->put('test.cloning.yaml', makeTestCloningYaml());

    bindRemappingDeps([], new RuntimeException('Connection refused'));

    $this->artisan('cloning:column:edit', [
        'file' => 'test.cloning.yaml',
        '--table' => 'users',
        '--column' => 'id',
        '--strategy' => 'remapping',
        '--remapping-use' => 'random_integer',
    ])
        ->expectsOutputToContain('Source connection unreachable')
        ->expectsConfirmation('Apply this change?', 'yes')
        ->assertExitCode(ExitCode::Success->value);

    $content = Storage::disk('local')->get('test.cloning.yaml');
    expect($content)->toContain('strategy: remapping');
    expect($content)->toContain('use: random_integer');
    expect($content)->toContain('foreign_keys: []');
});

it('falls back to empty foreign_keys when YAML connection name is unknown to clonio.json', function (): void {
    Storage::fake('local');
    Storage::disk('local')->put(
        'test.cloning.yaml',
        str_replace('connection: production-db', 'connection: missing-db', makeTestCloningYaml())
    );

    $configMock = Mockery::mock(ConfigService::class);
    $configMock->shouldReceive('getConnection')->with('missing-db')->andReturn(null);
    $this->app->instance(ConfigService::class, $configMock);

    $this->artisan('cloning:column:edit', [
        'file' => 'test.cloning.yaml',
        '--table' => 'users',
        '--column' => 'id',
        '--strategy' => 'remapping',
        '--remapping-use' => 'random_integer',
    ])
        ->expectsOutputToContain("Source connection 'missing-db' not found in clonio.json")
        ->expectsConfirmation('Apply this change?', 'yes')
        ->assertExitCode(ExitCode::Success->value);

    $content = Storage::disk('local')->get('test.cloning.yaml');
    expect($content)->toContain('foreign_keys: []');
});
