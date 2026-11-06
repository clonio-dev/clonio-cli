<?php

declare(strict_types=1);

use App\Data\Cloning\KeyRemappingForeignKeyData;
use App\Data\Cloning\PolymorphicDetectionData;
use App\Data\ConnectionData;
use App\Data\Schema\ColumnSchemaData;
use App\Data\Schema\DatabaseSchemaData;
use App\Data\Schema\ForeignKeyData;
use App\Data\Schema\TableSchemaData;
use App\Enums\DatabaseConnectionType;
use App\Enums\ExitCode;
use App\Services\Cloning\PolymorphicRelationDetector;
use App\Services\Config\ConfigService;
use App\Services\Database\DatabaseConnectionService;
use App\Services\Schema\SchemaInspector;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Yaml\Yaml;

// Issue #270: cloning:column:edit --strategy=remapping adds the polymorphic
// foreign keys whose type values name the edited table.

function cpolyBind(PolymorphicRelationDetector $detector): void
{
    $connection = new ConnectionData('production-db', DatabaseConnectionType::Sqlite, null, null, ':memory:', null, null, '', true);
    $column = static fn (string $name, string $type, bool $primary = false): ColumnSchemaData => new ColumnSchemaData($name, $type, false, null, $primary);

    $config = Mockery::mock(ConfigService::class);
    $config->shouldReceive('getConnection')->with('production-db')->andReturn($connection);

    $connector = Mockery::mock(DatabaseConnectionService::class);
    $connector->shouldReceive('open')->andReturn('source');

    $inspector = Mockery::mock(SchemaInspector::class);
    $inspector->shouldReceive('inspect')->andReturn(new DatabaseSchemaData('db', [
        new TableSchemaData('users', [$column('id', 'integer', true)], []),
        new TableSchemaData('posts', [$column('id', 'integer', true), $column('user_id', 'integer')], [
            new ForeignKeyData('user_id', 'users', 'id'),
        ]),
    ]));

    app()->instance(ConfigService::class, $config);
    app()->instance(DatabaseConnectionService::class, $connector);
    app()->instance(SchemaInspector::class, $inspector);
    app()->instance(PolymorphicRelationDetector::class, $detector);

    Storage::fake('local');
    Storage::disk('local')->put('test.cloning.yaml', Yaml::dump([
        'version' => '1',
        'connection' => 'production-db',
        'tables' => ['users' => ['columns' => []]],
    ]));
}

function cpolyEdit(mixed $test): mixed
{
    return $test->artisan('cloning:column:edit', [
        'file' => 'test.cloning.yaml',
        '--table' => 'users',
        '--column' => 'id',
        '--strategy' => 'remapping',
        '--remapping-use' => 'random_integer',
    ]);
}

/**
 * @return list<array<string, mixed>>
 */
function cpolyForeignKeys(): array
{
    $yaml = Yaml::parse((string) Storage::disk('local')->get('test.cloning.yaml'));

    return $yaml['tables']['users']['columns']['id']['arguments'][1]['foreign_keys'];
}

it('adds the polymorphic foreign keys that point at the edited table', function (): void {
    $detector = Mockery::mock(PolymorphicRelationDetector::class);
    $detector->shouldReceive('detect')
        ->withArgs(static fn (ConnectionData $connection, DatabaseSchemaData $schema, array $remapped): bool => $connection->name === 'production-db'
            && $remapped === ['users' => 'id'])
        ->once()
        ->andReturn(new PolymorphicDetectionData([
            'users' => [
                new KeyRemappingForeignKeyData('addresses', 'addressable_id', false, 'addressable_type', 'App\Models\User'),
                new KeyRemappingForeignKeyData('users', 'parent_id', true, 'parent_type', 'user'),
            ],
            'companies' => [new KeyRemappingForeignKeyData('addresses', 'addressable_id', false, 'addressable_type', 'company')],
        ]));
    cpolyBind($detector);

    cpolyEdit($this)
        ->expectsOutputToContain('posts.user_id, addresses.addressable_id [addressable_type = App\Models\User], users.parent_id [parent_type = user] (self)')
        ->expectsConfirmation('Apply this change?', 'yes')
        ->assertExitCode(ExitCode::Success->value)
        ->run();

    expect(cpolyForeignKeys())->toBe([
        ['table' => 'posts', 'column' => 'user_id', 'self_referential' => false],
        ['table' => 'addresses', 'column' => 'addressable_id', 'self_referential' => false, 'type_column' => 'addressable_type', 'type_value' => 'App\Models\User'],
        ['table' => 'users', 'column' => 'parent_id', 'self_referential' => true, 'type_column' => 'parent_type', 'type_value' => 'user'],
    ]);
});

it('keeps the constraint foreign keys when the type values cannot be read', function (): void {
    $detector = Mockery::mock(PolymorphicRelationDetector::class);
    $detector->shouldReceive('detect')->andThrow(new RuntimeException('connection lost'));
    cpolyBind($detector);

    cpolyEdit($this)
        ->expectsOutputToContain('Could not read polymorphic type values (connection lost) — no polymorphic foreign keys added.')
        ->expectsConfirmation('Apply this change?', 'yes')
        ->assertExitCode(ExitCode::Success->value)
        ->run();

    expect(cpolyForeignKeys())->toBe([
        ['table' => 'posts', 'column' => 'user_id', 'self_referential' => false],
    ]);
});

it('writes no polymorphic foreign keys when none point at the edited table', function (): void {
    $detector = Mockery::mock(PolymorphicRelationDetector::class);
    $detector->shouldReceive('detect')->andReturn(new PolymorphicDetectionData([
        'companies' => [new KeyRemappingForeignKeyData('addresses', 'addressable_id', false, 'addressable_type', 'company')],
    ]));
    cpolyBind($detector);

    cpolyEdit($this)
        ->expectsConfirmation('Apply this change?', 'yes')
        ->assertExitCode(ExitCode::Success->value)
        ->run();

    expect(cpolyForeignKeys())->toBe([
        ['table' => 'posts', 'column' => 'user_id', 'self_referential' => false],
    ]);
});
