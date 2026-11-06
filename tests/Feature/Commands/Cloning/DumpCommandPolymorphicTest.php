<?php

declare(strict_types=1);

use App\Data\Cloning\KeyRemappingForeignKeyData;
use App\Data\Cloning\PolymorphicDetectionData;
use App\Data\ConnectionData;
use App\Data\Pii\PiiMatcherSetData;
use App\Data\Schema\ColumnSchemaData;
use App\Data\Schema\DatabaseSchemaData;
use App\Data\Schema\ForeignKeyData;
use App\Data\Schema\TableSchemaData;
use App\Enums\DatabaseConnectionType;
use App\Enums\ExitCode;
use App\Services\Cloning\PolymorphicRelationDetector;
use App\Services\Config\ConfigService;
use App\Services\Pii\PiiMatcherLoader;
use App\Services\Schema\SchemaInspector;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Yaml\Yaml;

// Issue #270: cloning:dump adds polymorphic foreign keys (type_column +
// type_value) to the remapped tables' foreign_keys.

function dpolyConnection(): ConnectionData
{
    return new ConnectionData('production-db', DatabaseConnectionType::Sqlite, null, null, ':memory:', null, null, '', true);
}

function dpolySchema(): DatabaseSchemaData
{
    $column = static fn (string $name, string $type, bool $primary = false): ColumnSchemaData => new ColumnSchemaData($name, $type, false, null, $primary);

    return new DatabaseSchemaData('db', [
        new TableSchemaData('users', [$column('id', 'integer', true)], []),
        new TableSchemaData('orders', [$column('id', 'integer', true), $column('user_id', 'integer')], [
            new ForeignKeyData('user_id', 'users', 'id'),
        ]),
        new TableSchemaData('addresses', [$column('id', 'integer', true), $column('addressable_type', 'varchar'), $column('addressable_id', 'integer')], []),
    ]);
}

function dpolyBind(PolymorphicRelationDetector $detector): void
{
    $config = Mockery::mock(ConfigService::class);
    $config->shouldReceive('exists')->andReturn(true);
    $config->shouldReceive('getConnection')->with('production-db')->andReturn(dpolyConnection());

    $inspector = Mockery::mock(SchemaInspector::class);
    $inspector->shouldReceive('inspect')->andReturn(dpolySchema());

    $piiLoader = Mockery::mock(PiiMatcherLoader::class);
    $piiLoader->shouldReceive('load')->andReturn(new PiiMatcherSetData([]));

    app()->instance(ConfigService::class, $config);
    app()->instance(SchemaInspector::class, $inspector);
    app()->instance(PiiMatcherLoader::class, $piiLoader);
    app()->instance(PolymorphicRelationDetector::class, $detector);
}

function dpolyDetection(): PolymorphicDetectionData
{
    return new PolymorphicDetectionData(
        foreignKeysByTable: [
            'users' => [new KeyRemappingForeignKeyData('addresses', 'addressable_id', false, 'addressable_type', 'App\Models\User')],
            'orders' => [new KeyRemappingForeignKeyData('addresses', 'addressable_id', false, 'addressable_type', 'order')],
        ],
        unresolved: ["addresses.addressable_type = 'team'", "addresses.addressable_type = 'club'"],
    );
}

/**
 * @return list<array<string, mixed>>
 */
function dpolyForeignKeys(string $table): array
{
    $yaml = Yaml::parse((string) Storage::disk('local')->get('production-db.cloning.yaml'));

    return $yaml['tables'][$table]['columns']['id']['arguments'][1]['foreign_keys'];
}

function dpolyInteractive(mixed $pending): mixed
{
    return $pending
        ->expectsConfirmation('    Enforce column types on target?', 'no')
        ->expectsConfirmation('    Drop unknown tables on target?', 'no')
        ->expectsConfirmation('    Drop extra columns on target?', 'no')
        ->expectsConfirmation('    Drop and re-create existing tables on target?', 'no')
        ->expectsConfirmation('    Disable foreign key checks?', 'yes')
        ->expectsConfirmation('    Remap keys?', 'yes');
}

it('adds detected polymorphic foreign keys next to the constraint ones', function (): void {
    Storage::fake('local');

    $detector = Mockery::mock(PolymorphicRelationDetector::class);
    $detector->shouldReceive('detect')
        ->withArgs(static fn (ConnectionData $connection, DatabaseSchemaData $schema, array $remapped): bool => $connection->name === 'production-db'
            && $remapped === ['users' => 'id', 'orders' => 'id', 'addresses' => 'id'])
        ->once()
        ->andReturn(dpolyDetection());
    dpolyBind($detector);

    $this->artisan('cloning:dump', ['--connection' => 'production-db', '--ci' => true])
        ->doesntExpectOutputToContain('Polymorphic type values')
        ->assertExitCode(ExitCode::Success->value);

    expect(dpolyForeignKeys('users'))->toBe([
        ['table' => 'orders', 'column' => 'user_id', 'self_referential' => false],
        ['table' => 'addresses', 'column' => 'addressable_id', 'self_referential' => false, 'type_column' => 'addressable_type', 'type_value' => 'App\Models\User'],
    ])
        ->and(dpolyForeignKeys('orders'))->toBe([
            ['table' => 'addresses', 'column' => 'addressable_id', 'self_referential' => false, 'type_column' => 'addressable_type', 'type_value' => 'order'],
        ]);
});

it('warns about type values without a remapped table', function (): void {
    Storage::fake('local');

    $detector = Mockery::mock(PolymorphicRelationDetector::class);
    $detector->shouldReceive('detect')->andReturn(dpolyDetection());
    dpolyBind($detector);

    dpolyInteractive($this->artisan('cloning:dump', ['--connection' => 'production-db', '--force' => true]))
        ->expectsOutputToContain('Polymorphic type values without a remapped table — add their foreign_keys entries by hand:')
        ->expectsOutputToContain("    addresses.addressable_type = 'team'")
        ->expectsOutputToContain("    addresses.addressable_type = 'club'")
        ->assertExitCode(ExitCode::Success->value);
});

it('stays quiet when every type value matched', function (): void {
    Storage::fake('local');

    $detector = Mockery::mock(PolymorphicRelationDetector::class);
    $detector->shouldReceive('detect')->andReturn(new PolymorphicDetectionData);
    dpolyBind($detector);

    dpolyInteractive($this->artisan('cloning:dump', ['--connection' => 'production-db', '--force' => true]))
        ->doesntExpectOutputToContain('Polymorphic type values')
        ->assertExitCode(ExitCode::Success->value);
});

it('writes the config without polymorphic foreign keys when the type values cannot be read', function (bool $ci): void {
    Storage::fake('local');

    $detector = Mockery::mock(PolymorphicRelationDetector::class);
    $detector->shouldReceive('detect')->andThrow(new RuntimeException('connection lost'));
    dpolyBind($detector);

    $pending = $this->artisan('cloning:dump', ['--connection' => 'production-db', '--force' => true, '--ci' => $ci]);
    $warning = 'Could not read polymorphic type values (connection lost) — no polymorphic foreign keys added.';

    ($ci ? $pending->doesntExpectOutputToContain($warning) : dpolyInteractive($pending)->expectsOutputToContain($warning))
        ->assertExitCode(ExitCode::Success->value)
        ->run();

    expect(dpolyForeignKeys('users'))->toBe([
        ['table' => 'orders', 'column' => 'user_id', 'self_referential' => false],
    ]);
})->with(['interactive' => false, 'ci' => true]);

it('does not detect polymorphic relations with --only-pii', function (): void {
    Storage::fake('local');

    $detector = Mockery::mock(PolymorphicRelationDetector::class);
    $detector->shouldNotReceive('detect');
    dpolyBind($detector);

    $this->artisan('cloning:dump', ['--connection' => 'production-db', '--ci' => true, '--only-pii' => true])
        ->assertExitCode(ExitCode::Success->value);
});
