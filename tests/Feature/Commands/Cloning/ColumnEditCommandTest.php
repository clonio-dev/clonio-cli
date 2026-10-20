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
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Yaml\Yaml;

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

it('exits with ValidationError for invalid YAML missing tables section', function (): void {
    Storage::fake('local');
    Storage::disk('local')->put('test.cloning.yaml', "version: \"1\"\nconnection: production-db\n");

    $this->artisan('cloning:column:edit', [
        'file' => 'test.cloning.yaml',
        '--table' => 'users',
        '--column' => 'email',
        '--strategy' => 'keep',
    ])
        ->expectsOutputToContain('Invalid cloning YAML: missing tables section.')
        ->assertExitCode(ExitCode::ValidationError->value);
});

it('errors when no .cloning.yaml files exist and no file argument given', function (): void {
    Storage::fake('local');

    $this->artisan('cloning:column:edit', [
        '--table' => 'users',
        '--column' => 'email',
        '--strategy' => 'keep',
    ])
        ->expectsOutputToContain('No .cloning.yaml files found')
        ->assertExitCode(ExitCode::IoError->value);
});

it('auto-selects the single .cloning.yaml file when no file argument given', function (): void {
    Storage::fake('local');
    Storage::disk('local')->put('only.cloning.yaml', makeTestCloningYaml());

    $this->artisan('cloning:column:edit', [
        '--table' => 'users',
        '--column' => 'email',
        '--strategy' => 'keep',
    ])
        ->expectsConfirmation('Apply this change?', 'yes')
        ->expectsOutputToContain('only.cloning.yaml')
        ->assertExitCode(ExitCode::Success->value);
});

it('prompts to select among multiple .cloning.yaml files', function (): void {
    Storage::fake('local');
    Storage::disk('local')->put('a.cloning.yaml', makeTestCloningYaml());
    Storage::disk('local')->put('b.cloning.yaml', makeTestCloningYaml());

    $this->artisan('cloning:column:edit', [
        '--table' => 'users',
        '--column' => 'email',
        '--strategy' => 'keep',
    ])
        ->expectsChoice('Select a cloning YAML file', 'b.cloning.yaml', ['a.cloning.yaml', 'b.cloning.yaml'])
        ->expectsConfirmation('Apply this change?', 'yes')
        ->expectsOutputToContain('b.cloning.yaml')
        ->assertExitCode(ExitCode::Success->value);
});

it('selects a table interactively when no --table option given', function (): void {
    Storage::fake('local');
    Storage::disk('local')->put('test.cloning.yaml', makeTestCloningYaml());

    $this->artisan('cloning:column:edit', [
        'file' => 'test.cloning.yaml',
        '--column' => 'email',
        '--strategy' => 'keep',
    ])
        ->expectsChoice('Select a table', 'users', ['users'])
        ->expectsConfirmation('Apply this change?', 'yes')
        ->assertExitCode(ExitCode::Success->value);
});

it('asks for the column name when the table has no columns yet', function (): void {
    Storage::fake('local');
    $yaml = <<<'YAML'
version: "1"
connection: production-db
tables:
  empty_table:
    rows:
      strategy: full
YAML;
    Storage::disk('local')->put('test.cloning.yaml', $yaml);

    $this->artisan('cloning:column:edit', [
        'file' => 'test.cloning.yaml',
        '--table' => 'empty_table',
        '--strategy' => 'keep',
    ])
        ->expectsQuestion('Column name (no columns listed yet — enter the name to add)', 'new_col')
        ->expectsConfirmation('Apply this change?', 'yes')
        ->assertExitCode(ExitCode::Success->value);

    expect(Storage::disk('local')->get('test.cloning.yaml'))->toContain('new_col');
});

it('selects an existing column from the interactive list', function (): void {
    Storage::fake('local');
    Storage::disk('local')->put('test.cloning.yaml', makeTestCloningYaml());

    $this->artisan('cloning:column:edit', [
        'file' => 'test.cloning.yaml',
        '--table' => 'users',
        '--strategy' => 'keep',
    ])
        ->expectsChoice('Select a column', 'password', ['email', 'password', '+ Add new column'])
        ->expectsConfirmation('Apply this change?', 'yes')
        ->expectsOutputToContain('Updated users.password')
        ->assertExitCode(ExitCode::Success->value);
});

it('adds a new column via the interactive "+ Add new column" choice', function (): void {
    Storage::fake('local');
    Storage::disk('local')->put('test.cloning.yaml', makeTestCloningYaml());

    $this->artisan('cloning:column:edit', [
        'file' => 'test.cloning.yaml',
        '--table' => 'users',
        '--strategy' => 'keep',
    ])
        ->expectsChoice('Select a column', '+ Add new column', ['email', 'password', '+ Add new column'])
        ->expectsQuestion('Column name', 'phone')
        ->expectsConfirmation('Apply this change?', 'yes')
        ->assertExitCode(ExitCode::Success->value);

    expect(Storage::disk('local')->get('test.cloning.yaml'))->toContain('phone');
});

it('errors when the entered column name is empty', function (): void {
    Storage::fake('local');
    $yaml = <<<'YAML'
version: "1"
connection: production-db
tables:
  empty_table:
    rows:
      strategy: full
YAML;
    Storage::disk('local')->put('test.cloning.yaml', $yaml);

    $this->artisan('cloning:column:edit', [
        'file' => 'test.cloning.yaml',
        '--table' => 'empty_table',
        '--strategy' => 'keep',
    ])
        ->expectsQuestion('Column name (no columns listed yet — enter the name to add)', '')
        ->expectsOutputToContain('Column name cannot be empty.')
        ->assertExitCode(ExitCode::ValidationError->value);
});

it('selects a strategy interactively when no --strategy option given', function (): void {
    Storage::fake('local');
    Storage::disk('local')->put('test.cloning.yaml', makeTestCloningYaml());

    $this->artisan('cloning:column:edit', [
        'file' => 'test.cloning.yaml',
        '--table' => 'users',
        '--column' => 'email',
    ])
        ->expectsChoice(
            'Select a strategy',
            'Keep — Copy the original value as-is — no transformation applied.',
            [
                'Keep — Copy the original value as-is — no transformation applied.',
                'Fake — Replace with a realistic fake value generated by FakerPHP.',
                'Hash — Replace with a one-way hash of the original value. Useful for passwords and secrets.',
                'Mask — Partially mask the value, preserving a configurable number of visible characters.',
                'Static — Replace every value with a fixed string.',
                'Template — Build a string by mixing literal text with {fakerMethod} placeholders. Example: {userName}@acme.test',
                'Remapping — Replace primary key values; foreign keys auto-detected from the source schema.',
                'Exclude — Leave the column out of the transfer. The target column must be nullable or have a default.',
            ],
        )
        ->expectsConfirmation('Apply this change?', 'yes')
        ->assertExitCode(ExitCode::Success->value);

    expect(Storage::disk('local')->get('test.cloning.yaml'))->toContain('strategy: keep');
});

it('collects faker method and arguments interactively', function (): void {
    Storage::fake('local');
    Storage::disk('local')->put('test.cloning.yaml', makeTestCloningYaml());

    $this->artisan('cloning:column:edit', [
        'file' => 'test.cloning.yaml',
        '--table' => 'users',
        '--column' => 'email',
        '--strategy' => 'fake',
    ])
        ->expectsChoice('Faker method', 'numberBetween', [
            'safeEmail', 'userName', 'name', 'firstName', 'lastName',
            'phoneNumber', 'address', 'city', 'postcode', 'countryCode',
            'company', 'jobTitle', 'date', 'latitude', 'longitude',
            'buildingNumber', 'text', 'sentence', 'url', 'ipv4',
            'uuid', 'word', 'numberBetween', 'randomFloat',
        ])
        ->expectsQuestion('Faker arguments (comma-separated)', '1, 100')
        ->expectsConfirmation('Apply this change?', 'yes')
        ->assertExitCode(ExitCode::Success->value);

    $content = Storage::disk('local')->get('test.cloning.yaml');
    expect($content)->toContain('faker_method: numberBetween');
});

it('writes empty faker_arguments when an empty string is supplied for an arg-taking method', function (): void {
    Storage::fake('local');
    Storage::disk('local')->put('test.cloning.yaml', makeTestCloningYaml());

    $this->artisan('cloning:column:edit', [
        'file' => 'test.cloning.yaml',
        '--table' => 'users',
        '--column' => 'email',
        '--strategy' => 'fake',
        '--faker-method' => 'numberBetween',
        '--faker-arguments' => '',
    ])
        ->expectsConfirmation('Apply this change?', 'yes')
        ->assertExitCode(ExitCode::Success->value);

    $content = Storage::disk('local')->get('test.cloning.yaml');
    expect($content)->toContain('faker_method: numberBetween');
    expect($content)->toMatch('/faker_arguments:\s*(\[\]|\{.*\})/');
});

it('parses mixed numeric and string faker arguments', function (): void {
    Storage::fake('local');
    Storage::disk('local')->put('test.cloning.yaml', makeTestCloningYaml());

    $this->artisan('cloning:column:edit', [
        'file' => 'test.cloning.yaml',
        '--table' => 'users',
        '--column' => 'email',
        '--strategy' => 'fake',
        '--faker-method' => 'randomFloat',
        '--faker-arguments' => '2, 1.5, hello',
    ])
        ->expectsConfirmation('Apply this change?', 'yes')
        ->assertExitCode(ExitCode::Success->value);

    $content = Storage::disk('local')->get('test.cloning.yaml');
    expect($content)->toContain('1.5');
    expect($content)->toContain('hello');
});

it('collects hash algorithm interactively', function (): void {
    Storage::fake('local');
    Storage::disk('local')->put('test.cloning.yaml', makeTestCloningYaml());

    $this->artisan('cloning:column:edit', [
        'file' => 'test.cloning.yaml',
        '--table' => 'users',
        '--column' => 'password',
        '--strategy' => 'hash',
    ])
        ->expectsChoice('Algorithm', 'sha512', ['sha256', 'sha512', 'md5', 'sha1'])
        ->expectsQuestion('Salt (optional)', 'pepper')
        ->expectsConfirmation('Apply this change?', 'yes')
        ->assertExitCode(ExitCode::Success->value);

    $content = Storage::disk('local')->get('test.cloning.yaml');
    expect($content)->toContain('algorithm: sha512');
    expect($content)->toContain('salt: pepper');
});

it('collects mask parameters interactively', function (): void {
    Storage::fake('local');
    Storage::disk('local')->put('test.cloning.yaml', makeTestCloningYaml());

    $this->artisan('cloning:column:edit', [
        'file' => 'test.cloning.yaml',
        '--table' => 'users',
        '--column' => 'email',
        '--strategy' => 'mask',
    ])
        ->expectsQuestion('Visible characters', '3')
        ->expectsQuestion('Mask character', '#')
        ->expectsConfirmation('Preserve format', 'yes')
        ->expectsConfirmation('Apply this change?', 'yes')
        ->assertExitCode(ExitCode::Success->value);

    $content = Storage::disk('local')->get('test.cloning.yaml');
    expect($content)->toContain('visible_chars: 3');
    expect($content)->toContain('preserve_format: true');
});

it('collects static value interactively', function (): void {
    Storage::fake('local');
    Storage::disk('local')->put('test.cloning.yaml', makeTestCloningYaml());

    $this->artisan('cloning:column:edit', [
        'file' => 'test.cloning.yaml',
        '--table' => 'users',
        '--column' => 'email',
        '--strategy' => 'static',
    ])
        ->expectsQuestion('Static value', 'fixed@example.com')
        ->expectsConfirmation('Apply this change?', 'yes')
        ->assertExitCode(ExitCode::Success->value);

    expect(Storage::disk('local')->get('test.cloning.yaml'))->toContain('value: fixed@example.com');
});

it('collects remapping mode interactively', function (): void {
    Storage::fake('local');
    Storage::disk('local')->put('test.cloning.yaml', makeTestCloningYaml());

    bindRemappingDeps([
        makeRemappingTable('users', [
            ['name' => 'id', 'type' => 'int', 'isPrimary' => true],
        ]),
    ]);

    $this->artisan('cloning:column:edit', [
        'file' => 'test.cloning.yaml',
        '--table' => 'users',
        '--column' => 'id',
        '--strategy' => 'remapping',
    ])
        ->expectsChoice('Remapping mode', 'New UUID (UUIDv7)', [
            'Random integer — auto-bounded by column type ceiling',
            'New UUID (UUIDv7)',
        ])
        ->expectsConfirmation('Apply this change?', 'yes')
        ->assertExitCode(ExitCode::Success->value);

    expect(Storage::disk('local')->get('test.cloning.yaml'))->toContain('use: new_uuid');
});

it('warns and writes empty foreign_keys when YAML has no connection name', function (): void {
    Storage::fake('local');
    Storage::disk('local')->put(
        'test.cloning.yaml',
        str_replace("connection: production-db\n", '', makeTestCloningYaml())
    );

    $this->artisan('cloning:column:edit', [
        'file' => 'test.cloning.yaml',
        '--table' => 'users',
        '--column' => 'id',
        '--strategy' => 'remapping',
        '--remapping-use' => 'random_integer',
    ])
        ->expectsOutputToContain('Source connection unknown')
        ->expectsConfirmation('Apply this change?', 'yes')
        ->assertExitCode(ExitCode::Success->value);

    expect(Storage::disk('local')->get('test.cloning.yaml'))->toContain('foreign_keys: []');
});

it('hard-fails when the table is missing from the source schema for remapping', function (): void {
    Storage::fake('local');
    $original = makeTestCloningYaml();
    Storage::disk('local')->put('test.cloning.yaml', $original);

    bindRemappingDeps([
        makeRemappingTable('other_table', [
            ['name' => 'id', 'type' => 'int', 'isPrimary' => true],
        ]),
    ]);

    $this->artisan('cloning:column:edit', [
        'file' => 'test.cloning.yaml',
        '--table' => 'users',
        '--column' => 'id',
        '--strategy' => 'remapping',
        '--remapping-use' => 'random_integer',
    ])
        ->expectsOutputToContain("Table 'users' does not exist in source schema")
        ->assertExitCode(ExitCode::ValidationError->value);

    expect(Storage::disk('local')->get('test.cloning.yaml'))->toBe($original);
});

it('hard-fails when the column is missing from the source schema for remapping', function (): void {
    Storage::fake('local');
    $original = makeTestCloningYaml();
    Storage::disk('local')->put('test.cloning.yaml', $original);

    bindRemappingDeps([
        makeRemappingTable('users', [
            ['name' => 'id', 'type' => 'int', 'isPrimary' => true],
        ]),
    ]);

    $this->artisan('cloning:column:edit', [
        'file' => 'test.cloning.yaml',
        '--table' => 'users',
        '--column' => 'missing_col',
        '--strategy' => 'remapping',
        '--remapping-use' => 'random_integer',
    ])
        ->expectsOutputToContain("Column 'missing_col' does not exist on table 'users'")
        ->assertExitCode(ExitCode::ValidationError->value);

    expect(Storage::disk('local')->get('test.cloning.yaml'))->toBe($original);
});

it('ignores foreign keys that reference a different table or column during detection', function (): void {
    Storage::fake('local');
    Storage::disk('local')->put('test.cloning.yaml', makeTestCloningYaml());

    bindRemappingDeps([
        makeRemappingTable('users', [
            ['name' => 'id', 'type' => 'int', 'isPrimary' => true],
            ['name' => 'email', 'type' => 'varchar', 'isPrimary' => false],
        ]),
        makeRemappingTable('posts', [
            ['name' => 'id', 'type' => 'int', 'isPrimary' => true],
            ['name' => 'user_email', 'type' => 'varchar', 'isPrimary' => false],
            ['name' => 'org_id', 'type' => 'int', 'isPrimary' => false],
        ], [
            // References users.email (different column than the remapped id) — should be skipped
            ['columnName' => 'user_email', 'referencedTable' => 'users', 'referencedColumn' => 'email'],
            // References a different table — should be skipped
            ['columnName' => 'org_id', 'referencedTable' => 'orgs', 'referencedColumn' => 'id'],
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

    expect(Storage::disk('local')->get('test.cloning.yaml'))->toContain('foreign_keys: []');
});

it('sets the exclude strategy without extra parameters', function (): void {
    Storage::fake('local');
    Storage::disk('local')->put('test.cloning.yaml', makeTestCloningYaml());

    $this->artisan('cloning:column:edit', [
        'file' => 'test.cloning.yaml',
        '--table' => 'users',
        '--column' => 'email',
        '--strategy' => 'exclude',
    ])
        ->expectsConfirmation('Apply this change?', 'yes')
        ->assertExitCode(ExitCode::Success->value);

    expect(Storage::disk('local')->get('test.cloning.yaml'))->toContain('strategy: exclude');
});

/**
 * Runs cloning:column:edit non-interactively so every prompt resolves to its default.
 *
 * @param  array<string, mixed>  $parameters
 * @return array{0: int, 1: string}
 */
function g5_runColumnEdit(array $parameters): array
{
    $exitCode = Artisan::call('cloning:column:edit', [...$parameters, '--no-interaction' => true]);
    $output = Artisan::output();

    return [$exitCode, $output];
}

/**
 * @return array<string, mixed>
 */
function g5_columnConfig(string $table, string $column, string $file = 'test.cloning.yaml'): array
{
    $data = Yaml::parse((string) Storage::disk('local')->get($file));

    return $data['tables'][$table]['columns'][$column];
}

function g5_twoTableYaml(): string
{
    return <<<'YAML'
version: "1"
connection: production-db
tables:
  users:
    columns:
      email:
        strategy: fake
        faker_method: safeEmail
        faker_arguments: []
      legacy: {}
  orders:
    columns:
      total:
        strategy: keep
YAML;
}

it('treats an empty file argument as auto-discovery and ignores non-yaml files', function (): void {
    Storage::fake('local');
    Storage::disk('local')->put('aaa-notes.txt', 'noise');
    Storage::disk('local')->put('only.cloning.yaml', makeTestCloningYaml());

    [$exitCode] = g5_runColumnEdit(['file' => '', '--table' => 'users', '--column' => 'email', '--strategy' => 'keep']);

    expect($exitCode)->toBe(ExitCode::Success->value)
        ->and(g5_columnConfig('users', 'email', 'only.cloning.yaml'))->toBe(['strategy' => 'keep'])
        ->and(Storage::disk('local')->get('aaa-notes.txt'))->toBe('noise');
});

it('defaults to the first file when several .cloning.yaml files exist', function (): void {
    Storage::fake('local');
    Storage::disk('local')->put('a.cloning.yaml', makeTestCloningYaml());
    Storage::disk('local')->put('b.cloning.yaml', makeTestCloningYaml());

    [$exitCode] = g5_runColumnEdit(['--table' => 'users', '--column' => 'email', '--strategy' => 'keep']);

    expect($exitCode)->toBe(ExitCode::Success->value)
        ->and(g5_columnConfig('users', 'email', 'a.cloning.yaml'))->toBe(['strategy' => 'keep'])
        ->and(Storage::disk('local')->get('b.cloning.yaml'))->toBe(makeTestCloningYaml());
});

it('prints only the not-found error for a missing file', function (): void {
    Storage::fake('local');
    $this->withoutMockingConsoleOutput();

    [$exitCode, $output] = g5_runColumnEdit(['file' => 'missing.cloning.yaml', '--table' => 'users', '--column' => 'email', '--strategy' => 'keep']);

    expect($exitCode)->toBe(ExitCode::IoError->value)
        ->and($output)->toBe("File not found: missing.cloning.yaml\n");
});

it('treats an empty --table as a prompt that defaults to the first table', function (): void {
    Storage::fake('local');
    Storage::disk('local')->put('test.cloning.yaml', g5_twoTableYaml());

    [$exitCode] = g5_runColumnEdit(['file' => 'test.cloning.yaml', '--table' => '', '--column' => 'email', '--strategy' => 'keep']);

    $data = Yaml::parse((string) Storage::disk('local')->get('test.cloning.yaml'));

    expect($exitCode)->toBe(ExitCode::Success->value)
        ->and($data['tables']['users']['columns']['email'])->toBe(['strategy' => 'keep'])
        ->and($data['tables']['orders']['columns'])->toBe(['total' => ['strategy' => 'keep']]);
});

it('edits the table picked interactively', function (): void {
    Storage::fake('local');
    Storage::disk('local')->put('test.cloning.yaml', g5_twoTableYaml());

    $this->artisan('cloning:column:edit', ['file' => 'test.cloning.yaml', '--column' => 'total', '--strategy' => 'exclude'])
        ->expectsChoice('Select a table', 'orders', ['users', 'orders'])
        ->expectsConfirmation('Apply this change?', 'yes')
        ->assertExitCode(ExitCode::Success->value);

    $data = Yaml::parse((string) Storage::disk('local')->get('test.cloning.yaml'));

    expect($data['tables']['orders']['columns']['total'])->toBe(['strategy' => 'exclude'])
        ->and($data['tables']['users']['columns'])->not->toHaveKey('total');
});

it('treats an empty --column as a prompt that defaults to the first column', function (): void {
    Storage::fake('local');
    Storage::disk('local')->put('test.cloning.yaml', makeTestCloningYaml());

    [$exitCode] = g5_runColumnEdit(['file' => 'test.cloning.yaml', '--table' => 'users', '--column' => '', '--strategy' => 'exclude']);

    expect($exitCode)->toBe(ExitCode::Success->value)
        ->and(g5_columnConfig('users', 'email'))->toBe(['strategy' => 'exclude'])
        ->and(g5_columnConfig('users', 'password'))->toBe(['strategy' => 'hash', 'algorithm' => 'sha256', 'salt' => '']);
});

it('rejects an unanswered column-name prompt for a table without columns', function (): void {
    Storage::fake('local');
    $yaml = "version: \"1\"\ntables:\n  empty_table:\n    rows:\n      strategy: full\n";
    Storage::disk('local')->put('test.cloning.yaml', $yaml);
    $this->withoutMockingConsoleOutput();

    [$exitCode, $output] = g5_runColumnEdit(['file' => 'test.cloning.yaml', '--table' => 'empty_table', '--strategy' => 'keep']);

    expect($exitCode)->toBe(ExitCode::ValidationError->value)
        ->and($output)->toBe("Column name cannot be empty.\n")
        ->and(Storage::disk('local')->get('test.cloning.yaml'))->toBe($yaml);
});

it('rejects an unanswered "+ Add new column" name prompt', function (): void {
    Storage::fake('local');
    Storage::disk('local')->put('test.cloning.yaml', makeTestCloningYaml());

    $this->artisan('cloning:column:edit', ['file' => 'test.cloning.yaml', '--table' => 'users', '--strategy' => 'keep'])
        ->expectsChoice('Select a column', '+ Add new column', ['email', 'password', '+ Add new column'])
        ->expectsQuestion('Column name', null)
        ->expectsOutput('Column name cannot be empty.')
        ->assertExitCode(ExitCode::ValidationError->value);

    expect(Storage::disk('local')->get('test.cloning.yaml'))->toBe(makeTestCloningYaml());
});

it('shows "keep" as the current strategy for a column configured without one', function (): void {
    Storage::fake('local');
    Storage::disk('local')->put('test.cloning.yaml', g5_twoTableYaml());
    $this->withoutMockingConsoleOutput();

    [$exitCode, $output] = g5_runColumnEdit(['file' => 'test.cloning.yaml', '--table' => 'users', '--column' => 'legacy', '--strategy' => 'exclude']);

    expect($exitCode)->toBe(ExitCode::Success->value)
        ->and($output)->toStartWith("  Current strategy for users.legacy: keep\n\n");
});

it('treats an empty --strategy as a prompt that defaults to keep', function (): void {
    Storage::fake('local');
    Storage::disk('local')->put('test.cloning.yaml', makeTestCloningYaml());

    [$exitCode] = g5_runColumnEdit(['file' => 'test.cloning.yaml', '--table' => 'users', '--column' => 'email', '--strategy' => '']);

    expect($exitCode)->toBe(ExitCode::Success->value)
        ->and(g5_columnConfig('users', 'email'))->toBe(['strategy' => 'keep']);
});

it('applies a non-default strategy picked interactively', function (): void {
    Storage::fake('local');
    Storage::disk('local')->put('test.cloning.yaml', makeTestCloningYaml());

    $this->artisan('cloning:column:edit', ['file' => 'test.cloning.yaml', '--table' => 'users', '--column' => 'email'])
        ->expectsChoice(
            'Select a strategy',
            'Exclude — Leave the column out of the transfer. The target column must be nullable or have a default.',
            [
                'Keep — Copy the original value as-is — no transformation applied.',
                'Fake — Replace with a realistic fake value generated by FakerPHP.',
                'Hash — Replace with a one-way hash of the original value. Useful for passwords and secrets.',
                'Mask — Partially mask the value, preserving a configurable number of visible characters.',
                'Static — Replace every value with a fixed string.',
                'Template — Build a string by mixing literal text with {fakerMethod} placeholders. Example: {userName}@acme.test',
                'Remapping — Replace primary key values; foreign keys auto-detected from the source schema.',
                'Exclude — Leave the column out of the transfer. The target column must be nullable or have a default.',
            ],
        )
        ->expectsConfirmation('Apply this change?', 'yes')
        ->assertExitCode(ExitCode::Success->value);

    expect(g5_columnConfig('users', 'email'))->toBe(['strategy' => 'exclude']);
});

it('prints the current config, faker hint, summary and result for default fake parameters', function (): void {
    Storage::fake('local');
    Storage::disk('local')->put('test.cloning.yaml', makeTestCloningYaml());
    $this->withoutMockingConsoleOutput();

    [$exitCode, $output] = g5_runColumnEdit(['file' => 'test.cloning.yaml', '--table' => 'users', '--column' => 'email', '--strategy' => 'fake']);

    expect($exitCode)->toBe(ExitCode::Success->value)
        ->and($output)->toBe(<<<'TXT'
  Current strategy for users.email: fake

  ℹ  safeEmail() takes no arguments — leave faker arguments blank.

+-----------------------------------+-----------+
| Field                             | Value     |
+-----------------------------------+-----------+
| Table                             | users     |
| Column                            | email     |
| Strategy                          | Fake      |
| Faker method                      | safeEmail |
| Faker arguments (comma-separated) |           |
+-----------------------------------+-----------+
Updated users.email → strategy: fake in test.cloning.yaml
TXT."\n")
        ->and(Storage::disk('local')->get('test.cloning.yaml'))->toBe(<<<'TXT'
version: '1'
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
        salt: ''
TXT."\n");
});

it('leaves faker_arguments unset when an arg-taking method gets no arguments', function (): void {
    Storage::fake('local');
    Storage::disk('local')->put('test.cloning.yaml', makeTestCloningYaml());
    $this->withoutMockingConsoleOutput();

    [$exitCode, $output] = g5_runColumnEdit(['file' => 'test.cloning.yaml', '--table' => 'users', '--column' => 'email', '--strategy' => 'fake', '--faker-method' => 'numberBetween']);

    expect($exitCode)->toBe(ExitCode::Success->value)
        ->and($output)->toBe(<<<'TXT'
  Current strategy for users.email: fake

  ℹ  numberBetween() arguments: min (int, default 0), max (int, default 2147483647)

+--------------+---------------+
| Field        | Value         |
+--------------+---------------+
| Table        | users         |
| Column       | email         |
| Strategy     | Fake          |
| Faker method | numberBetween |
+--------------+---------------+
Updated users.email → strategy: fake in test.cloning.yaml
TXT."\n")
        ->and(g5_columnConfig('users', 'email'))->toBe(['strategy' => 'fake', 'faker_method' => 'numberBetween']);
});

it('trims and type-casts comma-separated faker arguments', function (): void {
    Storage::fake('local');
    Storage::disk('local')->put('test.cloning.yaml', makeTestCloningYaml());

    [$exitCode] = g5_runColumnEdit([
        'file' => 'test.cloning.yaml', '--table' => 'users', '--column' => 'email', '--strategy' => 'fake',
        '--faker-method' => 'randomFloat', '--faker-arguments' => ' 2 , 1.5 , x ',
    ]);

    expect($exitCode)->toBe(ExitCode::Success->value)
        ->and(g5_columnConfig('users', 'email')['faker_arguments'])->toBe([2, 1.5, 'x']);
});

it('defaults hash to sha256 without a salt', function (): void {
    Storage::fake('local');
    Storage::disk('local')->put('test.cloning.yaml', makeTestCloningYaml());
    $this->withoutMockingConsoleOutput();

    [$exitCode, $output] = g5_runColumnEdit(['file' => 'test.cloning.yaml', '--table' => 'users', '--column' => 'email', '--strategy' => 'hash']);

    expect($exitCode)->toBe(ExitCode::Success->value)
        ->and($output)->toBe(<<<'TXT'
  Current strategy for users.email: fake


+-----------+--------+
| Field     | Value  |
+-----------+--------+
| Table     | users  |
| Column    | email  |
| Strategy  | Hash   |
| Algorithm | sha256 |
+-----------+--------+
Updated users.email → strategy: hash in test.cloning.yaml
TXT."\n")
        ->and(g5_columnConfig('users', 'email'))->toBe(['strategy' => 'hash', 'algorithm' => 'sha256']);
});

it('defaults mask parameters and coerces non-numeric visible chars to zero', function (): void {
    Storage::fake('local');
    Storage::disk('local')->put('test.cloning.yaml', makeTestCloningYaml());
    $this->withoutMockingConsoleOutput();

    [$exitCode, $output] = g5_runColumnEdit(['file' => 'test.cloning.yaml', '--table' => 'users', '--column' => 'email', '--strategy' => 'mask', '--visible-chars' => 'abc']);

    expect($exitCode)->toBe(ExitCode::Success->value)
        ->and($output)->toBe(<<<'TXT'
  Current strategy for users.email: fake


+--------------------+-------+
| Field              | Value |
+--------------------+-------+
| Table              | users |
| Column             | email |
| Strategy           | Mask  |
| Visible characters | abc   |
| Mask character     | *     |
| Preserve format    | false |
+--------------------+-------+
Updated users.email → strategy: mask in test.cloning.yaml
TXT."\n")
        ->and(g5_columnConfig('users', 'email'))->toBe(['strategy' => 'mask', 'visible_chars' => 0, 'mask_char' => '*', 'preserve_format' => false]);
});

it('shows preserve format as true in the summary when the flag is set', function (): void {
    Storage::fake('local');
    Storage::disk('local')->put('test.cloning.yaml', makeTestCloningYaml());
    $this->withoutMockingConsoleOutput();

    [$exitCode, $output] = g5_runColumnEdit(['file' => 'test.cloning.yaml', '--table' => 'users', '--column' => 'email', '--strategy' => 'mask', '--visible-chars' => '2', '--mask-char' => '#', '--preserve-format' => true]);

    expect($exitCode)->toBe(ExitCode::Success->value)
        ->and($output)->toBe(<<<'TXT'
  Current strategy for users.email: fake


+--------------------+-------+
| Field              | Value |
+--------------------+-------+
| Table              | users |
| Column             | email |
| Strategy           | Mask  |
| Visible characters | 2     |
| Mask character     | #     |
| Preserve format    | true  |
+--------------------+-------+
Updated users.email → strategy: mask in test.cloning.yaml
TXT."\n")
        ->and(g5_columnConfig('users', 'email'))->toBe(['strategy' => 'mask', 'visible_chars' => 2, 'mask_char' => '#', 'preserve_format' => true]);
});

it('omits the static value when none is given', function (): void {
    Storage::fake('local');
    Storage::disk('local')->put('test.cloning.yaml', makeTestCloningYaml());

    [$exitCode] = g5_runColumnEdit(['file' => 'test.cloning.yaml', '--table' => 'users', '--column' => 'email', '--strategy' => 'static']);

    expect($exitCode)->toBe(ExitCode::Success->value)
        ->and(g5_columnConfig('users', 'email'))->toBe(['strategy' => 'static']);
});

it('omits the template when none is given', function (): void {
    Storage::fake('local');
    Storage::disk('local')->put('test.cloning.yaml', makeTestCloningYaml());

    [$exitCode] = g5_runColumnEdit(['file' => 'test.cloning.yaml', '--table' => 'users', '--column' => 'email', '--strategy' => 'template']);

    expect($exitCode)->toBe(ExitCode::Success->value)
        ->and(g5_columnConfig('users', 'email'))->toBe(['strategy' => 'template']);
});

it('prints the template summary row with its label', function (): void {
    Storage::fake('local');
    Storage::disk('local')->put('test.cloning.yaml', makeTestCloningYaml());
    $this->withoutMockingConsoleOutput();

    [$exitCode, $output] = g5_runColumnEdit(['file' => 'test.cloning.yaml', '--table' => 'users', '--column' => 'email', '--strategy' => 'template', '--template' => '{userName}@acme.test']);

    expect($exitCode)->toBe(ExitCode::Success->value)
        ->and($output)->toBe(<<<'TXT'
  Current strategy for users.email: fake


+-----------------------------------------------+----------------------+
| Field                                         | Value                |
+-----------------------------------------------+----------------------+
| Table                                         | users                |
| Column                                        | email                |
| Strategy                                      | Template             |
| Template (use {fakerMethod} for placeholders) | {userName}@acme.test |
+-----------------------------------------------+----------------------+
Updated users.email → strategy: template in test.cloning.yaml
TXT."\n")
        ->and(g5_columnConfig('users', 'email'))->toBe(['strategy' => 'template', 'template' => '{userName}@acme.test']);
});

it('rejects an empty --remapping-use and leaves the YAML untouched', function (): void {
    Storage::fake('local');
    Storage::disk('local')->put('test.cloning.yaml', "version: \"1\"\ntables:\n  users:\n    columns: {}\n");

    [$exitCode, $output] = g5_runColumnEdit(['file' => 'test.cloning.yaml', '--table' => 'users', '--column' => 'id', '--strategy' => 'remapping', '--remapping-use' => '']);

    expect($exitCode)->toBe(ExitCode::ValidationError->value)
        ->and($output)->toContain("Unknown remapping use: ''. Valid: random_integer, new_uuid")
        ->and(Storage::disk('local')->get('test.cloning.yaml'))->toBe("version: \"1\"\ntables:\n  users:\n    columns: {}\n");
});

it('prints a single warning and "— none —" when the YAML has no connection', function (): void {
    Storage::fake('local');
    Storage::disk('local')->put('test.cloning.yaml', "version: \"1\"\ntables:\n  users:\n    columns: {}\n");
    $this->withoutMockingConsoleOutput();

    [$exitCode, $output] = g5_runColumnEdit(['file' => 'test.cloning.yaml', '--table' => 'users', '--column' => 'id', '--strategy' => 'remapping']);

    expect($exitCode)->toBe(ExitCode::Success->value)
        ->and($output)->toBe(<<<'TXT'
  Source connection unknown (YAML missing top-level "connection") — writing foreign_keys: [] and skipping primary-key validation.

+------------------------------+----------------+
| Field                        | Value          |
+------------------------------+----------------+
| Table                        | users          |
| Column                       | id             |
| Strategy                     | Remapping      |
| Remapping mode               | random_integer |
| Foreign keys (auto-detected) | — none —       |
+------------------------------+----------------+
Updated users.id → strategy: remapping in test.cloning.yaml
TXT."\n")
        ->and(Storage::disk('local')->get('test.cloning.yaml'))->toBe(<<<'TXT'
version: '1'
tables:
  users:
    columns:
      id:
        strategy: remapping
        arguments:
          - { use: random_integer }
          - { foreign_keys: [] }
TXT."\n");
});

it('prints a single warning when the YAML connection is unknown', function (): void {
    Storage::fake('local');
    Storage::disk('local')->put('test.cloning.yaml', "version: \"1\"\nconnection: ghost\ntables:\n  users:\n    columns: {}\n");
    bindRemappingDeps([]);
    $this->withoutMockingConsoleOutput();

    [$exitCode, $output] = g5_runColumnEdit(['file' => 'test.cloning.yaml', '--table' => 'users', '--column' => 'id', '--strategy' => 'remapping']);

    expect($exitCode)->toBe(ExitCode::Success->value)
        ->and($output)->toBe(<<<'TXT'
  Source connection 'ghost' not found in clonio.json — writing foreign_keys: [] and skipping primary-key validation.

+------------------------------+----------------+
| Field                        | Value          |
+------------------------------+----------------+
| Table                        | users          |
| Column                       | id             |
| Strategy                     | Remapping      |
| Remapping mode               | random_integer |
| Foreign keys (auto-detected) | — none —       |
+------------------------------+----------------+
Updated users.id → strategy: remapping in test.cloning.yaml
TXT."\n");
});

it('detects every matching foreign key in order, purges the source connection and writes nested YAML', function (): void {
    Storage::fake('local');
    Storage::disk('local')->put('test.cloning.yaml', makeTestCloningYaml());
    bindRemappingDeps([
        makeRemappingTable('users', [
            ['name' => 'id', 'type' => 'int', 'isPrimary' => true],
            ['name' => 'email', 'type' => 'varchar', 'isPrimary' => false],
            ['name' => 'parent_id', 'type' => 'int', 'isPrimary' => false],
        ], [
            ['columnName' => 'parent_id', 'referencedTable' => 'users', 'referencedColumn' => 'id'],
        ]),
        makeRemappingTable('posts', [
            ['name' => 'id', 'type' => 'int', 'isPrimary' => true],
        ], [
            ['columnName' => 'org_id', 'referencedTable' => 'orgs', 'referencedColumn' => 'id'],
            ['columnName' => 'user_email', 'referencedTable' => 'users', 'referencedColumn' => 'email'],
            ['columnName' => 'author_id', 'referencedTable' => 'users', 'referencedColumn' => 'id'],
            ['columnName' => 'editor_id', 'referencedTable' => 'users', 'referencedColumn' => 'id'],
        ]),
    ]);
    config(['database.connections.source' => ['driver' => 'sqlite', 'database' => ':memory:']]);
    DB::connection('source');
    $this->withoutMockingConsoleOutput();

    [$exitCode, $output] = g5_runColumnEdit(['file' => 'test.cloning.yaml', '--table' => 'users', '--column' => 'id', '--strategy' => 'remapping']);

    expect($exitCode)->toBe(ExitCode::Success->value)
        ->and(DB::getConnections())->not->toHaveKey('source')
        ->and($output)->toBe(<<<'TXT'

+------------------------------+----------------------------------------------------------+
| Field                        | Value                                                    |
+------------------------------+----------------------------------------------------------+
| Table                        | users                                                    |
| Column                       | id                                                       |
| Strategy                     | Remapping                                                |
| Remapping mode               | random_integer                                           |
| Foreign keys (auto-detected) | users.parent_id (self), posts.author_id, posts.editor_id |
+------------------------------+----------------------------------------------------------+
Updated users.id → strategy: remapping in test.cloning.yaml
TXT."\n")
        ->and(Storage::disk('local')->get('test.cloning.yaml'))->toBe(<<<'TXT'
version: '1'
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
        salt: ''
      id:
        strategy: remapping
        arguments:
          - { use: random_integer }
          - { foreign_keys: [{ table: users, column: parent_id, self_referential: true }, { table: posts, column: author_id, self_referential: false }, { table: posts, column: editor_id, self_referential: false }] }
TXT."\n");
});
