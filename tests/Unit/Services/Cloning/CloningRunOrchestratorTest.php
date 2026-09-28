<?php

declare(strict_types=1);

use App\Data\Cloning\CloningConfigData;
use App\Data\Cloning\CloningOptionsData;
use App\Data\Cloning\StatsLoopData;
use App\Data\Cloning\StatsTableTransferData;
use App\Data\Cloning\TableCloningConfigData;
use App\Data\Cloning\TableRowConfigData;
use App\Data\Cloning\TableRunPhase;
use App\Data\Cloning\TableRunResultData;
use App\Data\Cloning\TableRunStatus;
use App\Data\ConnectionData;
use App\Data\Schema\ColumnSchemaData;
use App\Data\Schema\DatabaseSchemaData;
use App\Data\Schema\TableSchemaData;
use App\Enums\ClearMode;
use App\Enums\DatabaseConnectionType;
use App\Logging\AuditBuffer;
use App\Services\Cloning\CloningRunOrchestrator;
use App\Services\Cloning\DependencyResolver;
use App\Services\Cloning\SchemaReplicator;
use App\Services\Cloning\SkippedRow;
use App\Services\Database\DatabaseConnectionService;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    app(AuditBuffer::class)->clear();
});

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

    return new CloningRunOrchestrator($connector, $replicator, $resolver);
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
    $result = $orchestrator->run($config, $source, $target, $schema, true, [], [], static fn (string $t, TableRunStatus $status, int $rows, int $skipped, array $skippedRows): null => null);

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
    $result = $orchestrator->run($config, $source, $target, $schema, true, [], [], static fn (string $t, TableRunStatus $status, int $rows, int $skipped, array $skippedRows): null => null);

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
    $result = $orchestrator->run($config, $source, $target, $schema, true, [], [], static fn (string $t, TableRunStatus $status, int $rows, int $skipped, array $skippedRows): null => null);

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
    $orchestrator->run($config, $source, $target, $schema, true, [], [], static fn (string $t, TableRunStatus $status, int $rows, int $skipped, array $skippedRows): null => null);

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
    $orchestrator->run($config, $source, $target, $schema, true, [], [], static fn (string $t, TableRunStatus $status, int $rows, int $skipped, array $skippedRows): null => null);

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
    $result = $orchestrator->run($config, $source, $target, $schema, true, ['users'], [], static fn (string $t, TableRunStatus $status, int $rows, int $skipped, array $skippedRows): null => null);

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
    $result = $orchestrator->run($config, $source, $target, $schema, true, [], [], static fn (string $t, TableRunStatus $status, int $rows, int $skipped, array $skippedRows): null => null);

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
    $result = $orchestrator->run($config, $source, $target, $schema, true, [], [], static fn (string $t, TableRunStatus $status, int $rows, int $skipped, array $skippedRows): null => null);

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
    $result = $orchestrator->run($config, $source, $target, $schema, true, [], [], static fn (string $t, TableRunStatus $status, int $rows, int $skipped, array $skippedRows): null => null);

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
    $result = $orchestrator->run($config, $source, $target, $schema, true, [], [], static fn (string $t, TableRunStatus $status, int $rows, int $skipped, array $skippedRows): null => null);

    expect($result->success)->toBeFalse();
    expect($result->failureReason)->not->toBeNull();
});

function makeOrchestratorWithSchemaFailures(array $schemaFailures): CloningRunOrchestrator
{
    $connector = Mockery::mock(DatabaseConnectionService::class);
    $connector->shouldReceive('open')->andReturnUsing(static fn (ConnectionData $c): string => $c->name.'_conn');

    $replicator = Mockery::mock(SchemaReplicator::class);
    $replicator->shouldReceive('replicate')->andReturn($schemaFailures);
    $replicator->shouldReceive('correctAutoIncrement')->andReturnNull();

    $resolver = Mockery::mock(DependencyResolver::class);
    $resolver->shouldReceive('computeCascadeExclusions')->andReturn([]);
    $resolver->shouldReceive('sort')->andReturnUsing(static fn ($schema, array $tables): array => $tables);

    return new CloningRunOrchestrator($connector, $replicator, $resolver);
}

it('marks table as skipped_by_schema_failure when schema could not be created', function (): void {
    $source = makeOrchestratorConnection('source');
    $target = makeOrchestratorConnection('target');
    $schema = makeOrchestratorSchema();
    $config = makeOrchestratorConfig();

    DB::shouldReceive('connection')->andReturnSelf();
    DB::shouldReceive('purge')->andReturnNull();

    $orchestrator = makeOrchestratorWithSchemaFailures(['users' => 'syntax error']);
    $result = $orchestrator->run($config, $source, $target, $schema, false, [], [], static fn (string $t, TableRunStatus $status, int $rows, int $skipped, array $skippedRows): null => null);

    expect($result->tables)->toHaveCount(1);
    expect($result->tables[0]->status->value)->toBe('skipped_by_schema_failure');
    expect($result->success)->toBeFalse();
});

it('continues with other tables after a schema failure', function (): void {
    $source = makeOrchestratorConnection('source');
    $target = makeOrchestratorConnection('target');

    $schema = new DatabaseSchemaData(
        databaseName: 'testdb',
        tables: [
            new TableSchemaData(
                name: 'orders',
                columns: [new ColumnSchemaData(name: 'id', type: 'int', nullable: false, default: null, isPrimary: true)],
                foreignKeys: [],
            ),
            new TableSchemaData(
                name: 'users',
                columns: [new ColumnSchemaData(name: 'id', type: 'int', nullable: false, default: null, isPrimary: true)],
                foreignKeys: [],
            ),
        ],
    );

    $config = new CloningConfigData(
        version: '1',
        connectionName: 'source',
        options: new CloningOptionsData(
            chunkSize: 1000,
            enforceColumnTypes: false,
            dropUnknownTables: false,
            dropExtraColumns: false,
            disableForeignKeyChecks: false,
            fakerLocale: 'en_US',
        ),
        tables: [
            new TableCloningConfigData(
                tableName: 'orders',
                rows: new TableRowConfigData(strategy: 'full', limit: null, sortBy: null, clear: ClearMode::None),
                columns: [],
            ),
            new TableCloningConfigData(
                tableName: 'users',
                rows: new TableRowConfigData(strategy: 'full', limit: null, sortBy: null, clear: ClearMode::None),
                columns: [],
            ),
        ],
    );

    DB::shouldReceive('connection')->andReturnSelf();
    DB::shouldReceive('select')->andReturn([(object) ['id' => 1]], []);
    DB::shouldReceive('table')->andReturnSelf();
    DB::shouldReceive('insert')->andReturnTrue();
    DB::shouldReceive('purge')->andReturnNull();

    // orders fails schema; users succeeds
    $orchestrator = makeOrchestratorWithSchemaFailures(['orders' => 'syntax error']);
    $result = $orchestrator->run($config, $source, $target, $schema, false, [], [], static fn (string $t, TableRunStatus $status, int $rows, int $skipped, array $skippedRows): null => null);

    $statusByTable = [];
    foreach ($result->tables as $tableResult) {
        $statusByTable[$tableResult->tableName] = $tableResult->status->value;
    }

    expect($statusByTable['orders'])->toBe('skipped_by_schema_failure');
    expect($statusByTable['users'])->toBe('transferred');
    expect($result->success)->toBeFalse();
});

it('aborts after first schema failure when breakOnFailure is true', function (): void {
    $source = makeOrchestratorConnection('source');
    $target = makeOrchestratorConnection('target');

    $schema = new DatabaseSchemaData(
        databaseName: 'testdb',
        tables: [
            new TableSchemaData(
                name: 'orders',
                columns: [new ColumnSchemaData(name: 'id', type: 'int', nullable: false, default: null, isPrimary: true)],
                foreignKeys: [],
            ),
            new TableSchemaData(
                name: 'users',
                columns: [new ColumnSchemaData(name: 'id', type: 'int', nullable: false, default: null, isPrimary: true)],
                foreignKeys: [],
            ),
        ],
    );

    $config = new CloningConfigData(
        version: '1',
        connectionName: 'source',
        options: new CloningOptionsData(
            chunkSize: 1000,
            enforceColumnTypes: false,
            dropUnknownTables: false,
            dropExtraColumns: false,
            disableForeignKeyChecks: false,
            fakerLocale: 'en_US',
        ),
        tables: [
            new TableCloningConfigData(
                tableName: 'orders',
                rows: new TableRowConfigData(strategy: 'full', limit: null, sortBy: null, clear: ClearMode::None),
                columns: [],
            ),
            new TableCloningConfigData(
                tableName: 'users',
                rows: new TableRowConfigData(strategy: 'full', limit: null, sortBy: null, clear: ClearMode::None),
                columns: [],
            ),
        ],
    );

    DB::shouldReceive('connection')->andReturnSelf();
    DB::shouldReceive('purge')->andReturnNull();

    $orchestrator = makeOrchestratorWithSchemaFailures(['orders' => 'syntax error']);
    $result = $orchestrator->run($config, $source, $target, $schema, false, [], [], static fn (string $t, TableRunStatus $status, int $rows, int $skipped, array $skippedRows): null => null, breakOnFailure: true);

    $tableNames = array_map(static fn (TableRunResultData $t): string => $t->tableName, $result->tables);
    expect($tableNames)->toContain('orders');
    expect($tableNames)->not->toContain('users');
    expect($result->success)->toBeFalse();
});

it('captures per-row skip details when bulk insert fails and row-by-row fallback also fails', function (): void {
    $source = makeOrchestratorConnection('source');
    $target = makeOrchestratorConnection('target');
    $schema = makeOrchestratorSchema();
    $config = makeOrchestratorConfig();

    $sourceRows = [
        (object) ['id' => 1],
        (object) ['id' => 2],
        (object) ['id' => 3],
    ];

    DB::shouldReceive('connection')->andReturnSelf();
    DB::shouldReceive('select')->andReturn($sourceRows, []);
    DB::shouldReceive('table')->andReturnSelf();

    $insertCalls = 0;
    DB::shouldReceive('insert')->andReturnUsing(static function ($payload) use (&$insertCalls): bool {
        $insertCalls++;

        if ($insertCalls === 1) {
            // Bulk insert: throw to force row-by-row fallback
            throw new RuntimeException('SQLSTATE[23000]: Duplicate entry for key PRIMARY (bulk)');
        }

        // Row-by-row: succeed for id=2, fail for id=1 and id=3 with distinct messages
        if (is_array($payload) && array_key_exists('id', $payload)) {
            if ($payload['id'] === 1) {
                throw new RuntimeException("SQLSTATE[23000]: Duplicate entry '1' for key 'PRIMARY'");
            }

            if ($payload['id'] === 3) {
                throw new RuntimeException("SQLSTATE[22001]: Data too long for column 'name'");
            }
        }

        return true;
    });
    DB::shouldReceive('purge')->andReturnNull();

    $orchestratorWithLog = new CloningRunOrchestrator(
        Mockery::mock(DatabaseConnectionService::class)
            ->shouldReceive('open')
            ->andReturnUsing(static fn (ConnectionData $c): string => $c->name.'_conn')
            ->getMock(),
        Mockery::mock(SchemaReplicator::class)
            ->shouldReceive('replicate')
            ->andReturn([])
            ->getMock(),
        Mockery::mock(DependencyResolver::class)
            ->shouldReceive('computeCascadeExclusions')
            ->andReturn([])
            ->shouldReceive('sort')
            ->andReturnUsing(static fn ($s, array $tables): array => $tables)
            ->getMock(),
    );

    $orchestratorWithLog->run($config, $source, $target, $schema, true, [], [], static fn (string $t, TableRunStatus $status, int $rows, int $skipped, array $skippedRows): null => null);

    $skipEvents = array_values(array_filter(
        app(AuditBuffer::class)->records(),
        static fn (array $e): bool => $e['event'] === 'row_skipped',
    ));
    expect($skipEvents)->toHaveCount(2);

    $errorMessages = array_column($skipEvents, 'error');
    expect($errorMessages)->toContain("SQLSTATE[23000]: Duplicate entry '1' for key 'PRIMARY'");
    expect($errorMessages)->toContain("SQLSTATE[22001]: Data too long for column 'name'");

    foreach ($skipEvents as $event) {
        expect($event)->toHaveKeys(['table', 'chunk_offset', 'row_index', 'pk', 'error']);
        expect($event['table'])->toBe('users');
    }

    $pkIds = array_column(array_column($skipEvents, 'pk'), 'id');
    expect($pkIds)->toContain(1);
    expect($pkIds)->toContain(3);
});

it('falls back to null pk snapshot when source schema has no primary key column', function (): void {
    $source = makeOrchestratorConnection('source');
    $target = makeOrchestratorConnection('target');

    $schema = new DatabaseSchemaData(
        databaseName: 'testdb',
        tables: [
            new TableSchemaData(
                name: 'users',
                columns: [
                    new ColumnSchemaData(name: 'email', type: 'varchar', nullable: false, default: null, isPrimary: false),
                ],
                foreignKeys: [],
            ),
        ],
    );
    $config = makeOrchestratorConfig();

    DB::shouldReceive('connection')->andReturnSelf();
    DB::shouldReceive('select')->andReturn([(object) ['email' => 'a@b.c']], []);
    DB::shouldReceive('table')->andReturnSelf();

    DB::shouldReceive('insert')->andReturnUsing(static function (): never {
        throw new RuntimeException('Some error');
    });
    DB::shouldReceive('purge')->andReturnNull();

    $orchestrator = new CloningRunOrchestrator(
        Mockery::mock(DatabaseConnectionService::class)
            ->shouldReceive('open')
            ->andReturnUsing(static fn (ConnectionData $c): string => $c->name.'_conn')
            ->getMock(),
        Mockery::mock(SchemaReplicator::class)
            ->shouldReceive('replicate')
            ->andReturn([])
            ->getMock(),
        Mockery::mock(DependencyResolver::class)
            ->shouldReceive('computeCascadeExclusions')
            ->andReturn([])
            ->shouldReceive('sort')
            ->andReturnUsing(static fn ($s, array $tables): array => $tables)
            ->getMock(),
    );

    $orchestrator->run($config, $source, $target, $schema, true, [], [], static fn (string $t, TableRunStatus $status, int $rows, int $skipped, array $skippedRows): null => null);

    $skipEvents = array_values(array_filter(
        app(AuditBuffer::class)->records(),
        static fn (array $e): bool => $e['event'] === 'row_skipped',
    ));

    expect($skipEvents)->toHaveCount(1);
    expect($skipEvents[0]['pk'])->toBeNull();
});

it('fires onTableStart exactly once before onProgress for transferred tables', function (): void {
    $source = makeOrchestratorConnection('source');
    $target = makeOrchestratorConnection('target');
    $schema = makeOrchestratorSchema();
    $config = makeOrchestratorConfig();

    DB::shouldReceive('connection')->andReturnSelf();
    DB::shouldReceive('select')->andReturn([(object) ['id' => 1]], []);
    DB::shouldReceive('table')->andReturnSelf();
    DB::shouldReceive('insert')->andReturnTrue();
    DB::shouldReceive('purge')->andReturnNull();

    $events = [];
    $orchestrator = makeOrchestrator();
    $orchestrator->run(
        $config,
        $source,
        $target,
        $schema,
        true,
        [],
        [],
        static function (string $tbl, TableRunStatus $status, int $rows, int $skipped, array $skippedRows) use (&$events): void {
            $events[] = ['progress', $tbl];
        },
        onTableStart: static function (string $tbl) use (&$events): void {
            $events[] = ['start', $tbl];
        },
    );

    // Row totals are not tracked by default, so no counting-rows event: start,
    // then InProgress for the one chunk, then the terminal Transferred event.
    expect($events)->toBe([
        ['start', 'users'],
        ['progress', 'users'],
        ['progress', 'users'],
    ]);
});

it('fires onStart exactly once with the planned table count before the transfer loop', function (): void {
    $source = makeOrchestratorConnection('source');
    $target = makeOrchestratorConnection('target');
    $schema = makeOrchestratorSchema();
    $config = makeOrchestratorConfig();

    DB::shouldReceive('connection')->andReturnSelf();
    DB::shouldReceive('select')->andReturn([(object) ['id' => 1]], []);
    DB::shouldReceive('table')->andReturnSelf();
    DB::shouldReceive('insert')->andReturnTrue();
    DB::shouldReceive('purge')->andReturnNull();

    $events = [];
    $orchestrator = makeOrchestrator();
    $orchestrator->run(
        $config,
        $source,
        $target,
        $schema,
        true,
        [],
        [],
        static function (string $tbl, TableRunStatus $status) use (&$events): void {
            $events[] = ['progress', $tbl];
        },
        onStart: static function (int $total) use (&$events): void {
            $events[] = ['start', $total];
        },
    );

    // onStart fires first (before any progress), exactly once, with the number
    // of tables that will be attempted.
    expect($events[0])->toBe(['start', 1]);
    expect(array_values(array_filter($events, static fn (array $e): bool => $e[0] === 'start')))
        ->toBe([['start', 1]]);
});

it('announces the one-shot phases via the timings status on InProgress before the row loop', function (): void {
    $source = makeOrchestratorConnection('source');
    $target = makeOrchestratorConnection('target');
    $schema = makeOrchestratorSchema();
    $config = makeOrchestratorConfig(clear: ClearMode::Truncate);

    DB::shouldReceive('connection')->andReturnSelf();
    DB::shouldReceive('selectOne')->andReturn((object) ['c' => 1]);
    DB::shouldReceive('select')->andReturn([(object) ['id' => 1]], []);
    DB::shouldReceive('statement')->andReturnTrue();
    DB::shouldReceive('table')->andReturnSelf();
    DB::shouldReceive('insert')->andReturnTrue();
    DB::shouldReceive('purge')->andReturnNull();

    /** @var list<TableRunPhase> $phases */
    $phases = [];
    $orchestrator = makeOrchestrator();
    $orchestrator->run(
        $config,
        $source,
        $target,
        $schema,
        true,
        [],
        [],
        static function (string $tbl, TableRunStatus $status, int $rows, int $skipped, array $skippedRows, ?StatsTableTransferData $timings = null) use (&$phases): void {
            // Read the phase at emit time (the stats object is mutated in place).
            if ($status === TableRunStatus::InProgress && $timings?->status instanceof TableRunPhase) {
                $phases[] = $timings->status;
            }
        },
        trackRowTotals: true,
    );

    // Counting rows is announced first (before the count), then clearing the target,
    // both ahead of the per-chunk loop phase (Insert). No FK-disable phase here.
    expect($phases[0])->toBe(TableRunPhase::CountingRows);
    expect($phases[1])->toBe(TableRunPhase::Clear);
    expect($phases[0]->isOneShot())->toBeTrue();
    expect($phases[2])->toBe(TableRunPhase::Insert);
});

it('does not fire onTableStart for tables skipped by --skip flag', function (): void {
    $source = makeOrchestratorConnection('source');
    $target = makeOrchestratorConnection('target');
    $schema = makeOrchestratorSchema();
    $config = makeOrchestratorConfig();

    DB::shouldReceive('connection')->andReturnSelf();
    DB::shouldReceive('select')->andReturn([]);
    DB::shouldReceive('purge')->andReturnNull();

    $startCalls = [];
    $orchestrator = makeOrchestrator();
    $orchestrator->run(
        $config,
        $source,
        $target,
        $schema,
        true,
        ['users'],
        [],
        static fn (string $t, TableRunStatus $status, int $rows, int $skipped, array $skippedRows): null => null,
        onTableStart: static function (string $tbl) use (&$startCalls): void {
            $startCalls[] = $tbl;
        },
    );

    expect($startCalls)->toBe([]);
});

it('does not fire onTableStart for tables not found in source schema', function (): void {
    $source = makeOrchestratorConnection('source');
    $target = makeOrchestratorConnection('target');
    $schema = new DatabaseSchemaData(databaseName: 'testdb', tables: []);
    $config = makeOrchestratorConfig();

    DB::shouldReceive('connection')->andReturnSelf();
    DB::shouldReceive('select')->andReturn([]);
    DB::shouldReceive('purge')->andReturnNull();

    $startCalls = [];
    $orchestrator = makeOrchestrator();
    $orchestrator->run(
        $config,
        $source,
        $target,
        $schema,
        true,
        [],
        [],
        static fn (string $t, TableRunStatus $status, int $rows, int $skipped, array $skippedRows): null => null,
        onTableStart: static function (string $tbl) use (&$startCalls): void {
            $startCalls[] = $tbl;
        },
    );

    expect($startCalls)->toBe([]);
});

it('does not fire onTableStart for tables excluded by cascade dependency', function (): void {
    $source = makeOrchestratorConnection('source');
    $target = makeOrchestratorConnection('target');
    $schema = makeOrchestratorSchema();
    $config = makeOrchestratorConfig();

    DB::shouldReceive('connection')->andReturnSelf();
    DB::shouldReceive('select')->andReturn([]);
    DB::shouldReceive('purge')->andReturnNull();

    $connector = Mockery::mock(DatabaseConnectionService::class);
    $connector->shouldReceive('open')->andReturnUsing(static fn (ConnectionData $c): string => $c->name.'_conn');

    $replicator = Mockery::mock(SchemaReplicator::class);
    $replicator->shouldReceive('replicate')->andReturn([]);

    $resolver = Mockery::mock(DependencyResolver::class);
    $resolver->shouldReceive('computeCascadeExclusions')->andReturn(['users']);
    $resolver->shouldReceive('sort')->andReturnUsing(static fn ($s, array $tables): array => $tables);

    $startCalls = [];
    $orchestrator = new CloningRunOrchestrator(
        $connector,
        $replicator,
        $resolver,
    );

    $orchestrator->run(
        $config,
        $source,
        $target,
        $schema,
        true,
        [],
        [],
        static fn (string $t, TableRunStatus $status, int $rows, int $skipped, array $skippedRows): null => null,
        onTableStart: static function (string $tbl) use (&$startCalls): void {
            $startCalls[] = $tbl;
        },
    );

    expect($startCalls)->toBe([]);
});

it('passes skipped rows list to onProgress for transferred tables with row failures', function (): void {
    $source = makeOrchestratorConnection('source');
    $target = makeOrchestratorConnection('target');
    $schema = makeOrchestratorSchema();
    $config = makeOrchestratorConfig();

    DB::shouldReceive('connection')->andReturnSelf();
    DB::shouldReceive('select')->andReturn([(object) ['id' => 1], (object) ['id' => 2]], []);
    DB::shouldReceive('table')->andReturnSelf();

    $insertCalls = 0;
    DB::shouldReceive('insert')->andReturnUsing(static function ($payload) use (&$insertCalls): bool {
        $insertCalls++;

        if ($insertCalls === 1) {
            throw new RuntimeException('bulk fail');
        }

        if (is_array($payload) && ($payload['id'] ?? null) === 1) {
            throw new RuntimeException('error A');
        }

        return true;
    });
    DB::shouldReceive('purge')->andReturnNull();

    $progressArgs = null;
    $orchestrator = makeOrchestrator();
    $orchestrator->run(
        $config,
        $source,
        $target,
        $schema,
        true,
        [],
        [],
        static function (string $tbl, TableRunStatus $status, int $rows, int $skipped, array $skippedRows) use (&$progressArgs): void {
            $progressArgs = compact('tbl', 'rows', 'skipped', 'skippedRows');
        },
    );

    expect($progressArgs['tbl'])->toBe('users');
    expect($progressArgs['rows'])->toBe(1);
    expect($progressArgs['skipped'])->toBe(1);
    expect($progressArgs['skippedRows'])->toHaveCount(1);
    expect($progressArgs['skippedRows'][0])->toBeInstanceOf(SkippedRow::class);
    expect($progressArgs['skippedRows'][0]->sqlError)->toBe('error A');
});

it('preserves captured skip rows when a later chunk fetch throws', function (): void {
    $source = makeOrchestratorConnection('source');
    $target = makeOrchestratorConnection('target');
    $schema = makeOrchestratorSchema();

    // Use chunk size 1 so we can sequence chunk-1 (with a row failure) then chunk-2 (select throws)
    $config = new CloningConfigData(
        version: '1',
        connectionName: 'source',
        options: new CloningOptionsData(
            chunkSize: 1,
            enforceColumnTypes: false,
            dropUnknownTables: false,
            dropExtraColumns: false,
            disableForeignKeyChecks: false,
            fakerLocale: 'en_US',
        ),
        tables: [
            new TableCloningConfigData(
                tableName: 'users',
                rows: new TableRowConfigData(strategy: 'full', limit: null, sortBy: null, clear: ClearMode::None),
                columns: [],
            ),
        ],
    );

    $selectCalls = 0;
    $insertCalls = 0;

    DB::shouldReceive('connection')->andReturnSelf();
    DB::shouldReceive('select')->andReturnUsing(static function () use (&$selectCalls): array {
        $selectCalls++;

        if ($selectCalls === 1) {
            return [(object) ['id' => 1]];
        }

        // Second select throws — simulating a connection-level failure mid-run
        throw new RuntimeException('Connection lost mid-run');
    });
    DB::shouldReceive('table')->andReturnSelf();
    DB::shouldReceive('insert')->andReturnUsing(static function () use (&$insertCalls): bool {
        $insertCalls++;

        // Bulk insert: throw to force row-by-row, then row-by-row throws too
        throw new RuntimeException('SQLSTATE[23000]: row failure on chunk 1');
    });
    DB::shouldReceive('purge')->andReturnNull();

    $progressArgs = null;
    $orchestrator = makeOrchestrator();
    $orchestrator->run(
        $config,
        $source,
        $target,
        $schema,
        true,
        [],
        [],
        static function (string $tbl, TableRunStatus $status, int $rows, int $skipped, array $skippedRows) use (&$progressArgs): void {
            $progressArgs = compact('tbl', 'rows', 'skipped', 'skippedRows');
        },
    );

    expect($progressArgs['tbl'])->toBe('users');
    expect($progressArgs['skippedRows'])->toHaveCount(1);
    expect($progressArgs['skippedRows'][0]->sqlError)->toBe('SQLSTATE[23000]: row failure on chunk 1');
});

it('provides TableTransferTimingsData with per-loop entries, stats-over-time and throughput to onProgress', function (): void {
    $source = makeOrchestratorConnection('source');
    $target = makeOrchestratorConnection('target');
    $schema = makeOrchestratorSchema();
    $config = new CloningConfigData(
        version: '1',
        connectionName: 'source',
        options: new CloningOptionsData(
            chunkSize: 2,
            enforceColumnTypes: false,
            dropUnknownTables: false,
            dropExtraColumns: false,
            disableForeignKeyChecks: false,
            fakerLocale: 'en_US',
        ),
        tables: [
            new TableCloningConfigData(
                tableName: 'users',
                rows: new TableRowConfigData(strategy: 'full', limit: null, sortBy: null, clear: ClearMode::None),
                columns: [],
            ),
        ],
    );

    DB::shouldReceive('connection')->andReturnSelf();
    DB::shouldReceive('select')->andReturn(
        [(object) ['id' => 1], (object) ['id' => 2]],
        [(object) ['id' => 3]],
    );
    DB::shouldReceive('selectOne')->andReturn((object) ['c' => 3]);
    DB::shouldReceive('table')->andReturnSelf();
    DB::shouldReceive('insert')->andReturnTrue();
    DB::shouldReceive('purge')->andReturnNull();

    /** @var list<array{status: TableRunStatus, rows: int, timings: ?StatsTableTransferData}> $events */
    $events = [];
    $orchestrator = makeOrchestrator();
    $orchestrator->run(
        $config,
        $source,
        $target,
        $schema,
        true,
        [],
        [],
        static function (string $tbl, TableRunStatus $status, int $rows, int $skipped, array $skippedRows, ?StatsTableTransferData $timings = null) use (&$events): void {
            $events[] = ['status' => $status, 'rows' => $rows, 'timings' => $timings];
        },
        trackRowTotals: true,
    );

    $pending = array_values(array_filter($events, static fn (array $e): bool => $e['status'] === TableRunStatus::InProgress));
    $final = array_values(array_filter($events, static fn (array $e): bool => $e['status'] === TableRunStatus::Transferred));

    // One InProgress for the counting-rows phase plus one per chunk (2 chunks).
    expect($pending)->toHaveCount(3);
    expect($final)->toHaveCount(1);

    $timings = $final[0]['timings'];
    expect($timings)->toBeInstanceOf(StatsTableTransferData::class);
    expect($timings->totalRows)->toBe(3);
    expect($timings->rowsDone)->toBe(3);
    expect($timings->rowsSkipped)->toBe(0);
    expect($timings->rowsRemaining)->toBe(0);
    expect($timings->percentComplete)->toBe(100.0);

    expect($timings->loops->count())->toBe(2);
    expect($timings->statsOverTime->count())->toBe(2);

    /** @var StatsLoopData $loop0 */
    $loop0 = $timings->loops->get(0);
    /** @var StatsLoopData $loop1 */
    $loop1 = $timings->loops->get(1);
    expect($loop0->loopIndex)->toBe(0);
    expect($loop0->chunkRows)->toBe(2);
    expect($loop0->rowsDone)->toBe(2);
    expect($loop0->rowsSkipped)->toBe(0);
    expect($loop0->totalRows)->toBe(3);
    expect($loop1->loopIndex)->toBe(1);
    expect($loop1->chunkRows)->toBe(1);
    expect($loop1->rowsDone)->toBe(1);

    $snap0 = $timings->statsOverTime->get(0);
    $snap1 = $timings->statsOverTime->get(1);
    expect($snap0->rowsDoneCumulative)->toBe(2);
    expect($snap1->rowsDoneCumulative)->toBe(3);
    expect($snap0->loopsRecorded)->toBe(1);
    expect($snap1->loopsRecorded)->toBe(2);
    expect($snap1->percentComplete)->toBe(100.0);
    // Snapshot holds immutable scalars captured at record time, unaffected by later loops.
    expect($snap0->insertPacePerMillion)->not->toBeNull();

    $insertAgg = $timings->aggregate(TableRunPhase::Insert);
    expect($insertAgg->count)->toBe(2);
    expect($insertAgg->min)->toBeLessThanOrEqual($insertAgg->max);
    expect($insertAgg->averageSeconds)->toBeGreaterThanOrEqual(0.0);

    expect($insertAgg->pacePerMillion)->not->toBeNull();
    expect($insertAgg->latestPacePerMillion)->not->toBeNull();
});

it('returns null throughput and percent when total rows is zero on StatsTableTransferData', function (): void {
    $timings = new StatsTableTransferData;
    expect($timings->aggregate(TableRunPhase::Insert)->pacePerMillion)->toBeNull();
    expect($timings->percentComplete)->toBeNull();
});
