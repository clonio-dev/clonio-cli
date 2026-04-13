<?php

declare(strict_types=1);

use App\Data\Cloning\CloningConfigData;
use App\Data\Cloning\CloningOptionsData;
use App\Data\Cloning\TableCloningConfigData;
use App\Data\Cloning\TableRowConfigData;
use App\Data\ConnectionData;
use App\Data\Schema\ColumnSchemaData;
use App\Data\Schema\DatabaseSchemaData;
use App\Data\Schema\TableSchemaData;
use App\Enums\ClearMode;
use App\Enums\DatabaseConnectionType;
use App\Services\Cloning\CloningRunOrchestrator;
use App\Services\Cloning\DependencyResolver;
use App\Services\Cloning\RunLogWriter;
use App\Services\Cloning\SchemaReplicator;
use App\Services\Database\DatabaseConnectionService;
use Illuminate\Support\Facades\DB;

function makeOrchestratorConnection(string $name, DatabaseConnectionType $type = DatabaseConnectionType::Mysql): ConnectionData
{
    return new ConnectionData(
        name: $name,
        type: $type,
        host: 'localhost',
        port: 3306,
        database: 'testdb',
        schema: null,
        username: 'root',
        password: 'secret',
        isProduction: false,
    );
}

function makeOrchestratorSchema(string $tableName = 'users'): DatabaseSchemaData
{
    return new DatabaseSchemaData(
        databaseName: 'testdb',
        tables: [
            new TableSchemaData(
                name: $tableName,
                columns: [
                    new ColumnSchemaData(name: 'id', type: 'int', nullable: false, default: null, isPrimary: true),
                ],
                foreignKeys: [],
            ),
        ],
    );
}

function makeOrchestratorConfig(string $tableName = 'users', ClearMode $clear = ClearMode::None, bool $disableFk = false): CloningConfigData
{
    return new CloningConfigData(
        version: '1',
        connectionName: 'source',
        options: new CloningOptionsData(
            chunkSize: 1000,
            enforceColumnTypes: false,
            dropUnknownTables: false,
            dropExtraColumns: false,
            disableForeignKeyChecks: $disableFk,
            fakerLocale: 'en_US',
        ),
        tables: [
            new TableCloningConfigData(
                tableName: $tableName,
                rows: new TableRowConfigData(strategy: 'full', limit: null, sortBy: null, clear: $clear),
                columns: [],
            ),
        ],
    );
}

function makeOrchestrator(): CloningRunOrchestrator
{
    $connector = Mockery::mock(DatabaseConnectionService::class);
    $connector->shouldReceive('open')->andReturnUsing(static fn (ConnectionData $c): string => $c->name.'_conn');

    $replicator = Mockery::mock(SchemaReplicator::class);
    $replicator->shouldReceive('replicate')->andReturn([]);

    $resolver = Mockery::mock(DependencyResolver::class);
    $resolver->shouldReceive('computeCascadeExclusions')->andReturn([]);
    $resolver->shouldReceive('sort')->andReturnUsing(static fn ($schema, array $tables): array => $tables);

    $runLog = new RunLogWriter;

    return new CloningRunOrchestrator($connector, $replicator, $resolver, $runLog);
}

it('calls TRUNCATE TABLE for mysql when clear is truncate', function (): void {
    $source = makeOrchestratorConnection('source', DatabaseConnectionType::Mysql);
    $target = makeOrchestratorConnection('target', DatabaseConnectionType::Mysql);
    $schema = makeOrchestratorSchema();
    $config = makeOrchestratorConfig(clear: ClearMode::Truncate);

    $truncateCalled = false;

    DB::shouldReceive('connection')->andReturnSelf();
    DB::shouldReceive('select')->andReturn([]);
    DB::shouldReceive('statement')->withArgs(static function (string $sql) use (&$truncateCalled): bool {
        if (str_contains($sql, 'TRUNCATE TABLE')) {
            $truncateCalled = true;
        }

        return true;
    })->andReturnTrue();
    DB::shouldReceive('table')->andReturnSelf();
    DB::shouldReceive('delete')->andReturn(0);
    DB::shouldReceive('purge')->andReturnNull();

    $orchestrator = makeOrchestrator();
    $result = $orchestrator->run($config, $source, $target, $schema, true, [], [], static fn (): null => null);

    expect($truncateCalled)->toBeTrue();
    expect($result->success)->toBeTrue();
});

it('calls DELETE instead of TRUNCATE on sqlite when clear is truncate', function (): void {
    $source = makeOrchestratorConnection('source', DatabaseConnectionType::Sqlite);
    $target = makeOrchestratorConnection('target', DatabaseConnectionType::Sqlite);
    $schema = makeOrchestratorSchema();
    $config = makeOrchestratorConfig(clear: ClearMode::Truncate);

    $truncateCalled = false;
    $deleteCalled = false;

    DB::shouldReceive('connection')->andReturnSelf();
    DB::shouldReceive('select')->andReturn([]);
    DB::shouldReceive('statement')->withArgs(static function (string $sql) use (&$truncateCalled): bool {
        if (str_contains($sql, 'TRUNCATE TABLE')) {
            $truncateCalled = true;
        }

        return true;
    })->andReturnTrue();
    DB::shouldReceive('table')->andReturnSelf();
    DB::shouldReceive('delete')->withNoArgs()->andReturnUsing(static function () use (&$deleteCalled): int {
        $deleteCalled = true;

        return 0;
    });
    DB::shouldReceive('purge')->andReturnNull();

    $orchestrator = makeOrchestrator();
    $result = $orchestrator->run($config, $source, $target, $schema, true, [], [], static fn (): null => null);

    expect($truncateCalled)->toBeFalse();
    expect($deleteCalled)->toBeTrue();
    expect($result->success)->toBeTrue();
});

it('calls DELETE when clear is delete', function (): void {
    $source = makeOrchestratorConnection('source', DatabaseConnectionType::Mysql);
    $target = makeOrchestratorConnection('target', DatabaseConnectionType::Mysql);
    $schema = makeOrchestratorSchema();
    $config = makeOrchestratorConfig(clear: ClearMode::Delete);

    $deleteCalled = false;
    $truncateCalled = false;

    DB::shouldReceive('connection')->andReturnSelf();
    DB::shouldReceive('select')->andReturn([]);
    DB::shouldReceive('statement')->withArgs(static function (string $sql) use (&$truncateCalled): bool {
        if (str_contains($sql, 'TRUNCATE')) {
            $truncateCalled = true;
        }

        return true;
    })->andReturnTrue();
    DB::shouldReceive('table')->andReturnSelf();
    DB::shouldReceive('delete')->withNoArgs()->andReturnUsing(static function () use (&$deleteCalled): int {
        $deleteCalled = true;

        return 0;
    });
    DB::shouldReceive('purge')->andReturnNull();

    $orchestrator = makeOrchestrator();
    $result = $orchestrator->run($config, $source, $target, $schema, true, [], [], static fn (): null => null);

    expect($truncateCalled)->toBeFalse();
    expect($deleteCalled)->toBeTrue();
    expect($result->success)->toBeTrue();
});

it('does not clear table when clear is false', function (): void {
    $source = makeOrchestratorConnection('source', DatabaseConnectionType::Mysql);
    $target = makeOrchestratorConnection('target', DatabaseConnectionType::Mysql);
    $schema = makeOrchestratorSchema();
    $config = makeOrchestratorConfig(clear: ClearMode::None);

    $clearCalled = false;

    DB::shouldReceive('connection')->andReturnSelf();
    DB::shouldReceive('select')->andReturn([]);
    DB::shouldReceive('statement')->withArgs(static function (string $sql) use (&$clearCalled): bool {
        if (str_contains($sql, 'TRUNCATE') || str_contains($sql, 'DELETE')) {
            $clearCalled = true;
        }

        return true;
    })->andReturnTrue();
    DB::shouldReceive('table')->andReturnSelf();
    DB::shouldReceive('delete')->withNoArgs()->andReturnUsing(static function () use (&$clearCalled): int {
        $clearCalled = true;

        return 0;
    });
    DB::shouldReceive('purge')->andReturnNull();

    $orchestrator = makeOrchestrator();
    $orchestrator->run($config, $source, $target, $schema, true, [], [], static fn (): null => null);

    expect($clearCalled)->toBeFalse();
});

it('disables and re-enables FK checks on mysql when disableForeignKeyChecks is true', function (): void {
    $source = makeOrchestratorConnection('source', DatabaseConnectionType::Mysql);
    $target = makeOrchestratorConnection('target', DatabaseConnectionType::Mysql);
    $schema = makeOrchestratorSchema();
    $config = makeOrchestratorConfig(disableFk: true);

    $fkDisabled = false;
    $fkEnabled = false;

    DB::shouldReceive('connection')->andReturnSelf();
    DB::shouldReceive('select')->andReturn([]);
    DB::shouldReceive('statement')->withArgs(static function (string $sql) use (&$fkDisabled, &$fkEnabled): bool {
        if (str_contains($sql, 'FOREIGN_KEY_CHECKS=0')) {
            $fkDisabled = true;
        }

        if (str_contains($sql, 'FOREIGN_KEY_CHECKS=1')) {
            $fkEnabled = true;
        }

        return true;
    })->andReturnTrue();
    DB::shouldReceive('table')->andReturnSelf();
    DB::shouldReceive('insert')->andReturnTrue();
    DB::shouldReceive('purge')->andReturnNull();

    $orchestrator = makeOrchestrator();
    $orchestrator->run($config, $source, $target, $schema, true, [], [], static fn (): null => null);

    expect($fkDisabled)->toBeTrue();
    expect($fkEnabled)->toBeTrue();
});

it('marks table as skipped when in skip-tables list', function (): void {
    $source = makeOrchestratorConnection('source');
    $target = makeOrchestratorConnection('target');
    $schema = makeOrchestratorSchema();
    $config = makeOrchestratorConfig();

    DB::shouldReceive('connection')->andReturnSelf();
    DB::shouldReceive('select')->andReturn([]);
    DB::shouldReceive('purge')->andReturnNull();

    $orchestrator = makeOrchestrator();
    $result = $orchestrator->run($config, $source, $target, $schema, true, ['users'], [], static fn (): null => null);

    expect($result->tables)->toHaveCount(1);
    expect($result->tables[0]->status->value)->toBe('skipped_by_flag');
});

it('marks table as not-found when missing from schema', function (): void {
    $source = makeOrchestratorConnection('source');
    $target = makeOrchestratorConnection('target');
    $schema = new DatabaseSchemaData(databaseName: 'testdb', tables: []);
    $config = makeOrchestratorConfig();

    DB::shouldReceive('connection')->andReturnSelf();
    DB::shouldReceive('select')->andReturn([]);
    DB::shouldReceive('purge')->andReturnNull();

    $orchestrator = makeOrchestrator();
    $result = $orchestrator->run($config, $source, $target, $schema, true, [], [], static fn (): null => null);

    expect($result->tables)->toHaveCount(1);
    expect($result->tables[0]->status->value)->toBe('not_found');
});

it('transfers rows and counts correctly', function (): void {
    $source = makeOrchestratorConnection('source');
    $target = makeOrchestratorConnection('target');
    $schema = makeOrchestratorSchema();
    $config = makeOrchestratorConfig();

    $rows = [(object) ['id' => 1], (object) ['id' => 2]];

    DB::shouldReceive('connection')->andReturnSelf();
    // First call returns 2 rows, second returns [] (end of chunking)
    DB::shouldReceive('select')->andReturn($rows, []);
    DB::shouldReceive('table')->andReturnSelf();
    DB::shouldReceive('insert')->andReturnTrue();
    DB::shouldReceive('purge')->andReturnNull();

    $orchestrator = makeOrchestrator();
    $result = $orchestrator->run($config, $source, $target, $schema, true, [], [], static fn (): null => null);

    expect($result->totalRows)->toBe(2);
    expect($result->success)->toBeTrue();
});

it('marks run as failed when all rows are skipped during insert', function (): void {
    $source = makeOrchestratorConnection('source');
    $target = makeOrchestratorConnection('target');
    $schema = makeOrchestratorSchema();
    $config = makeOrchestratorConfig();

    $rows = [(object) ['id' => 1], (object) ['id' => 2]];

    DB::shouldReceive('connection')->andReturnSelf();
    // Return rows once, then empty to stop the loop
    DB::shouldReceive('select')->andReturn($rows, []);
    DB::shouldReceive('table')->andReturnSelf();
    // Both bulk and per-row inserts throw — all rows get skipped
    DB::shouldReceive('insert')->andThrow(new RuntimeException('Disk full'));
    DB::shouldReceive('purge')->andReturnNull();

    $orchestrator = makeOrchestrator();
    $result = $orchestrator->run($config, $source, $target, $schema, true, [], [], static fn (): null => null);

    expect($result->tables[0]->rowsSkipped)->toBe(2);
    expect($result->tables[0]->rowsTransferred)->toBe(0);
    expect($result->tables[0]->status->value)->toBe('failed');
    expect($result->tables[0]->failureReason)->toContain('Disk full');
    expect($result->success)->toBeFalse();
    expect($result->failureReason)->not->toBeNull();
});

it('reports table failure when SELECT throws', function (): void {
    $source = makeOrchestratorConnection('source');
    $target = makeOrchestratorConnection('target');
    $schema = makeOrchestratorSchema();
    $config = makeOrchestratorConfig();

    DB::shouldReceive('connection')->andReturnSelf();
    DB::shouldReceive('select')->andThrow(new RuntimeException('Query failed'));
    DB::shouldReceive('purge')->andReturnNull();

    $orchestrator = makeOrchestrator();
    $result = $orchestrator->run($config, $source, $target, $schema, true, [], [], static fn (): null => null);

    expect($result->success)->toBeFalse();
    expect($result->failureReason)->not->toBeNull();
});
