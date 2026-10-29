<?php

declare(strict_types=1);

use App\Data\Cloning\CloningConfigData;
use App\Data\Cloning\CloningOptionsData;
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
use App\Services\Schema\SchemaInspector;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Monolog\Handler\AbstractProcessingHandler;
use Monolog\LogRecord;

/** Target inspector stub: reports an empty target so no generated columns are found there. */
function fakeTargetInspector(): SchemaInspector
{
    $inspector = Mockery::mock(SchemaInspector::class);
    $inspector->shouldReceive('inspect')->andReturn(new DatabaseSchemaData('target', []));

    return $inspector;
}

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

    $resolver = Mockery::mock(DependencyResolver::class)->makePartial();
    $resolver->shouldReceive('computeCascadeExclusions')->andReturn([]);
    $resolver->shouldReceive('sort')->andReturnUsing(static fn ($schema, array $tables): array => $tables);

    return new CloningRunOrchestrator($connector, $replicator, $resolver, fakeTargetInspector());
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

    $resolver = Mockery::mock(DependencyResolver::class)->makePartial();
    $resolver->shouldReceive('computeCascadeExclusions')->andReturn([]);
    $resolver->shouldReceive('sort')->andReturnUsing(static fn ($schema, array $tables): array => $tables);

    return new CloningRunOrchestrator($connector, $replicator, $resolver, fakeTargetInspector());
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
        Mockery::mock(DependencyResolver::class)->makePartial()
            ->shouldReceive('computeCascadeExclusions')
            ->andReturn([])
            ->shouldReceive('sort')
            ->andReturnUsing(static fn ($s, array $tables): array => $tables)
            ->getMock(),
        fakeTargetInspector(),
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
        Mockery::mock(DependencyResolver::class)->makePartial()
            ->shouldReceive('computeCascadeExclusions')
            ->andReturn([])
            ->shouldReceive('sort')
            ->andReturnUsing(static fn ($s, array $tables): array => $tables)
            ->getMock(),
        fakeTargetInspector(),
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

    expect($events)->toBe([
        ['start', 'users'],
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

it('announces the one-shot phases via onTableProgress before the row loop', function (): void {
    $source = makeOrchestratorConnection('source');
    $target = makeOrchestratorConnection('target');
    $schema = makeOrchestratorSchema();
    $config = makeOrchestratorConfig(clear: ClearMode::Truncate, disableFk: true);

    DB::shouldReceive('connection')->andReturnSelf();
    DB::shouldReceive('selectOne')->andReturn((object) ['c' => 1]);
    DB::shouldReceive('select')->andReturn([(object) ['id' => 1]], []);
    DB::shouldReceive('statement')->andReturnTrue();
    DB::shouldReceive('table')->andReturnSelf();
    DB::shouldReceive('insert')->andReturnTrue();
    DB::shouldReceive('purge')->andReturnNull();

    /** @var list<TableRunPhase> $phases */
    $phases = [];
    $stats = null;
    $orchestrator = makeOrchestrator();
    $orchestrator->run(
        $config,
        $source,
        $target,
        $schema,
        true,
        [],
        [],
        static function (): void {},
        trackRowTotals: true,
        onTableProgress: static function (string $tbl, StatsTableTransferData $s) use (&$phases, &$stats): void {
            // Read the phase at emit time (the stats object is mutated in place).
            $phases[] = $s->status;
            $stats = $s;
        },
    );

    // Each one-shot phase is announced as it starts, then the loop phase once
    // before the first chunk and again after each chunk.
    expect($phases)->toBe([
        TableRunPhase::EstimatingRows,
        TableRunPhase::DisableFkChecks,
        TableRunPhase::Clear,
        TableRunPhase::Loop,
        TableRunPhase::Loop,
    ]);

    // Every phase that ran recorded one plausible wall-clock sample.
    foreach (TableRunPhase::cases() as $phase) {
        $aggregate = $stats->aggregate($phase);
        expect($aggregate->count)->toBe(1)
            ->and($aggregate->sum)->toBeGreaterThanOrEqual(0.0)
            ->and($aggregate->sum)->toBeLessThan(60.0);
    }
});

it('skips the row estimate for schema-only tables', function (): void {
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
                tableName: 'users',
                rows: new TableRowConfigData(strategy: 'schema', limit: null, sortBy: null, clear: ClearMode::None),
                columns: [],
            ),
        ],
    );

    DB::shouldReceive('connection')->andReturnSelf();
    DB::shouldReceive('selectOne')->never();
    DB::shouldReceive('purge')->andReturnNull();

    $events = 0;
    makeOrchestrator()->run(
        $config,
        makeOrchestratorConnection('source'),
        makeOrchestratorConnection('target'),
        makeOrchestratorSchema(),
        true,
        [],
        [],
        static function (): void {},
        trackRowTotals: true,
        onTableProgress: static function () use (&$events): void {
            $events++;
        },
    );

    expect($events)->toBe(0);
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

    $resolver = Mockery::mock(DependencyResolver::class)->makePartial();
    $resolver->shouldReceive('computeCascadeExclusions')->andReturn(['users']);
    $resolver->shouldReceive('sort')->andReturnUsing(static fn ($s, array $tables): array => $tables);

    $startCalls = [];
    $orchestrator = new CloningRunOrchestrator(
        $connector,
        $replicator,
        $resolver,
        fakeTargetInspector(),
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

it('reports per-table timings and throughput through onTableProgress', function (): void {
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

    /** @var list<StatsTableTransferData> $events */
    $events = [];
    $rowsAtEvent = [];
    $terminal = [];
    $orchestrator = makeOrchestrator();
    $orchestrator->run(
        $config,
        $source,
        $target,
        $schema,
        true,
        [],
        [],
        static function (string $tbl, TableRunStatus $status) use (&$terminal): void {
            $terminal[] = $status;
        },
        trackRowTotals: true,
        onTableProgress: static function (string $tbl, StatsTableTransferData $stats) use (&$events, &$rowsAtEvent): void {
            $events[] = $stats;
            $rowsAtEvent[] = $stats->rowsProcessed;
        },
    );

    // One event for the row estimate, one as the loop starts and one per chunk
    // (2 chunks), then the terminal Transferred event on onProgress.
    expect($events)->toHaveCount(4);
    expect($terminal)->toBe([TableRunStatus::Transferred]);

    // The same live object travels through every event of the table.
    $timings = $events[0];
    expect($events[3])->toBe($timings);
    expect($timings->totalRows)->toBe(3);
    expect($timings->rowsDone)->toBe(3);
    expect($timings->rowsSkipped)->toBe(0);
    expect($timings->rowsRemaining)->toBe(0);

    // Cumulative progress read at emit time: before the first chunk, then per chunk.
    expect($rowsAtEvent)->toBe([0, 0, 2, 3]);

    $loopAgg = $timings->aggregate(TableRunPhase::Loop);
    expect($loopAgg->count)->toBe(2);
    expect($loopAgg->rowsProcessed)->toBe(3);
    expect($loopAgg->lastRows)->toBe(1);

    $insertAgg = $timings->aggregate(TableRunPhase::Insert);
    expect($insertAgg->count)->toBe(2);
    expect($insertAgg->min)->toBeLessThanOrEqual($insertAgg->max);
    expect($insertAgg->averageSeconds)->toBeGreaterThanOrEqual(0.0);

    expect($insertAgg->pacePerMillion)->not->toBeNull();
    expect($insertAgg->latestPacePerMillion)->not->toBeNull();
});

it('returns null throughput when no rows were processed on StatsTableTransferData', function (): void {
    $timings = new StatsTableTransferData;
    expect($timings->aggregate(TableRunPhase::Insert)->pacePerMillion)->toBeNull();
    expect($timings->hasKnownTotal)->toBeFalse();
});

/**
 * Transfer one table with row totals tracked and return its live stats.
 */
function runTrackingRowTotals(DatabaseConnectionType $sourceType, ?int $limit = null): StatsTableTransferData
{
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
                tableName: 'users',
                rows: new TableRowConfigData(strategy: 'full', limit: $limit, sortBy: 'id', clear: ClearMode::None),
                columns: [],
            ),
        ],
    );

    $stats = null;
    makeOrchestrator()->run(
        $config,
        makeOrchestratorConnection('source', $sourceType),
        makeOrchestratorConnection('target'),
        makeOrchestratorSchema(),
        true,
        [],
        [],
        static function (): void {},
        trackRowTotals: true,
        onTableProgress: static function (string $tbl, StatsTableTransferData $s) use (&$stats): void {
            $stats = $s;
        },
    );

    expect($stats)->toBeInstanceOf(StatsTableTransferData::class);

    return $stats;
}

it('sizes the table from catalog statistics on server databases and counts on SQLite', function (DatabaseConnectionType $type, string $sql, array $bindings, bool $estimated): void {
    DB::shouldReceive('connection')->andReturnSelf();
    DB::shouldReceive('selectOne')->once()->with($sql, $bindings)->andReturn((object) ['c' => '42']);
    DB::shouldReceive('select')->andReturn([]);
    DB::shouldReceive('purge')->andReturnNull();

    $stats = runTrackingRowTotals($type);

    expect($stats->totalRows)->toBe(42);
    expect($stats->totalRowsEstimated)->toBe($estimated);
    expect($stats->aggregate(TableRunPhase::EstimatingRows)->count)->toBe(1);
})->with([
    'mysql' => [DatabaseConnectionType::Mysql, 'SELECT TABLE_ROWS AS c FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', ['users'], true],
    'mariadb' => [DatabaseConnectionType::MariaDB, 'SELECT TABLE_ROWS AS c FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', ['users'], true],
    'pgsql' => [DatabaseConnectionType::PostgreSQL, 'SELECT c.reltuples::bigint AS c FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace WHERE n.nspname = current_schema() AND c.relname = ?', ['users'], true],
    'sqlsrv' => [DatabaseConnectionType::SqlServer, 'SELECT SUM(p.rows) AS c FROM sys.partitions p WHERE p.object_id = OBJECT_ID(?) AND p.index_id IN (0, 1)', ['users'], true],
    'sqlite' => [DatabaseConnectionType::Sqlite, 'SELECT COUNT(*) AS c FROM "users"', [], false],
]);

it('reads the row total from an associative result row', function (): void {
    DB::shouldReceive('connection')->andReturnSelf();
    DB::shouldReceive('selectOne')->andReturn(['c' => 7]);
    DB::shouldReceive('select')->andReturn([]);
    DB::shouldReceive('purge')->andReturnNull();

    expect(runTrackingRowTotals(DatabaseConnectionType::Mysql)->totalRows)->toBe(7);
});

it('caps the row total at the row strategy limit', function (int $estimate, ?int $limit, int $expected): void {
    DB::shouldReceive('connection')->andReturnSelf();
    DB::shouldReceive('selectOne')->andReturn((object) ['c' => $estimate]);

    $table = new TableCloningConfigData(
        tableName: 'users',
        rows: new TableRowConfigData(strategy: 'first', limit: $limit, sortBy: 'id', clear: ClearMode::None),
        columns: [],
    );

    // Called directly: a whole transfer would also run the 'first' chunk query.
    $estimate = (fn (): array => $this->estimateSourceRows('source_conn', $table, makeOrchestratorConnection('source')))
        ->call(makeOrchestrator());

    expect($estimate)->toBe([$expected, true]);
})->with([
    'below limit' => [50, 100, 50],
    'at limit' => [100, 100, 100],
    'above limit' => [5000, 100, 100],
    'zero limit' => [5000, 0, 0],
    'no limit' => [5000, null, 5000],
]);

it('ignores a row limit on the full strategy, which copies every row', function (): void {
    DB::shouldReceive('connection')->andReturnSelf();
    DB::shouldReceive('selectOne')->andReturn((object) ['c' => 5000]);
    DB::shouldReceive('select')->andReturn([]);
    DB::shouldReceive('purge')->andReturnNull();

    expect(runTrackingRowTotals(DatabaseConnectionType::Mysql, 100)->totalRows)->toBe(5000);
});

it('treats a never-analyzed PostgreSQL table (-1) or an unreadable result as an unknown total', function (mixed $result): void {
    DB::shouldReceive('connection')->andReturnSelf();
    DB::shouldReceive('selectOne')->andReturn($result);
    DB::shouldReceive('select')->andReturn([]);
    DB::shouldReceive('purge')->andReturnNull();

    expect(runTrackingRowTotals(DatabaseConnectionType::PostgreSQL)->totalRows)->toBe(0);
})->with([
    'never analyzed' => [(object) ['c' => -1]],
    'no row' => [null],
    'non-numeric' => [(object) ['c' => 'n/a']],
    'missing column' => [(object) ['rows' => 5]],
]);

it('keeps transferring with an unknown total when the row estimate fails', function (): void {
    DB::shouldReceive('connection')->andReturnSelf();
    DB::shouldReceive('selectOne')->andThrow(new RuntimeException('permission denied for pg_class'));
    DB::shouldReceive('select')->andReturn([]);
    DB::shouldReceive('purge')->andReturnNull();

    $stats = runTrackingRowTotals(DatabaseConnectionType::PostgreSQL);

    expect($stats->totalRows)->toBe(0);
    expect($stats->totalRowsEstimated)->toBeFalse();

    $events = array_values(array_filter(
        app(AuditBuffer::class)->records(),
        static fn (array $e): bool => $e['event'] === 'source_row_estimate_failed',
    ));
    expect($events)->toHaveCount(1);
    expect($events[0]['table'])->toBe('users');
    expect($events[0]['error'])->toBe('permission denied for pg_class');
});

it('keeps the rows already inserted when logging a skipped row throws mid-chunk', function (): void {
    DB::shouldReceive('connection')->andReturnSelf();
    DB::shouldReceive('select')->andReturn([(object) ['id' => 1], (object) ['id' => 2], (object) ['id' => 3]], []);
    DB::shouldReceive('table')->andReturnSelf();
    DB::shouldReceive('insert')->andReturnUsing(static function (array $payload): bool {
        if (! array_key_exists('id', $payload) || $payload['id'] === 2) {
            throw new RuntimeException('insert failed'); // the bulk insert, then row 2
        }

        return true;
    });
    DB::shouldReceive('purge')->andReturnNull();

    // A log handler that breaks on the first skipped row, e.g. a closed output.
    $monolog = Log::driver()->getLogger();
    $monolog->pushHandler(new class extends AbstractProcessingHandler
    {
        protected function write(LogRecord $record): void
        {
            if ($record->message === 'row_skipped') {
                throw new RuntimeException('log output closed');
            }
        }
    });

    try {
        $result = makeOrchestrator()->run(
            makeOrchestratorConfig(),
            makeOrchestratorConnection('source'),
            makeOrchestratorConnection('target'),
            makeOrchestratorSchema(),
            true,
            [],
            [],
            static function (): void {},
        );
    } finally {
        $monolog->popHandler();
    }

    // Row 1 landed and row 2 was skipped before the log call threw.
    expect($result->tables[0]->status)->toBe(TableRunStatus::Failed)
        ->and($result->tables[0]->failureReason)->toBe('log output closed')
        ->and($result->tables[0]->rowsTransferred)->toBe(1)
        ->and($result->tables[0]->rowsSkipped)->toBe(1);
});

it('closes both connections when reporting the row estimate throws', function (): void {
    DB::shouldReceive('connection')->andReturnSelf();
    DB::shouldReceive('selectOne')->never();
    DB::shouldReceive('purge')->once()->with('source_conn');
    DB::shouldReceive('purge')->once()->with('target_conn');

    $result = makeOrchestrator()->run(
        makeOrchestratorConfig(),
        makeOrchestratorConnection('source'),
        makeOrchestratorConnection('target'),
        makeOrchestratorSchema(),
        true,
        [],
        [],
        static function (): void {},
        trackRowTotals: true,
        onTableProgress: static function (): void {
            throw new RuntimeException('display broke');
        },
    );

    expect($result->tables[0]->status)->toBe(TableRunStatus::Failed)
        ->and($result->tables[0]->failureReason)->toBe('display broke');
});

it('bounds the SQLite count by the row limit of a first or last table', function (string $strategy): void {
    DB::shouldReceive('connection')->andReturnSelf();
    DB::shouldReceive('selectOne')->once()->with('SELECT COUNT(*) AS c FROM (SELECT 1 FROM "users" LIMIT 100)', [])->andReturn((object) ['c' => 100]);

    $table = new TableCloningConfigData(
        tableName: 'users',
        rows: new TableRowConfigData(strategy: $strategy, limit: 100, sortBy: 'id', clear: ClearMode::None),
        columns: [],
    );

    $estimate = (fn (): array => $this->estimateSourceRows('source_conn', $table, makeOrchestratorConnection('source', DatabaseConnectionType::Sqlite)))
        ->call(makeOrchestrator());

    expect($estimate)->toBe([100, false]);
})->with(['first', 'last']);

it('counts skipped rows per chunk in the table stats', function (): void {
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
    DB::shouldReceive('select')->andReturn([(object) ['id' => 1], (object) ['id' => 2]], [(object) ['id' => 3]], []);
    DB::shouldReceive('table')->andReturnSelf();
    DB::shouldReceive('insert')->andReturnUsing(static function (array $payload): bool {
        // Bulk inserts fail; rows 1 and 3 fail on their own as well.
        if (! array_key_exists('id', $payload) || $payload['id'] !== 2) {
            throw new RuntimeException('insert failed');
        }

        return true;
    });
    DB::shouldReceive('purge')->andReturnNull();

    $stats = null;
    makeOrchestrator()->run(
        $config,
        makeOrchestratorConnection('source'),
        makeOrchestratorConnection('target'),
        makeOrchestratorSchema(),
        true,
        [],
        [],
        static function (): void {},
        onTableProgress: static function (string $tbl, StatsTableTransferData $s) use (&$stats): void {
            $stats = $s;
        },
    );

    expect($stats->rowsDone)->toBe(1)
        ->and($stats->rowsSkipped)->toBe(2);
});

it('counts a SQLite table in full when its strategy copies every row', function (): void {
    DB::shouldReceive('connection')->andReturnSelf();
    DB::shouldReceive('selectOne')->once()->with('SELECT COUNT(*) AS c FROM "users"', [])->andReturn((object) ['c' => 500]);

    $table = new TableCloningConfigData(
        tableName: 'users',
        rows: new TableRowConfigData(strategy: 'full', limit: 100, sortBy: null, clear: ClearMode::None),
        columns: [],
    );

    $estimate = (fn (): array => $this->estimateSourceRows('source_conn', $table, makeOrchestratorConnection('source', DatabaseConnectionType::Sqlite)))
        ->call(makeOrchestrator());

    expect($estimate)->toBe([500, false]);
});

it('uses keyset (seek) pagination instead of OFFSET when a primary key exists', function (): void {
    $source = makeOrchestratorConnection('source');
    $target = makeOrchestratorConnection('target');
    $schema = makeOrchestratorSchema(); // users, PK id
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

    /** @var list<array{sql: string, bindings: array<int, mixed>}> $queries */
    $queries = [];
    DB::shouldReceive('connection')->andReturnSelf();
    DB::shouldReceive('select')->andReturnUsing(function (string $sql, array $bindings = []) use (&$queries): array {
        $queries[] = ['sql' => $sql, 'bindings' => $bindings];

        return match (count($queries)) {
            1 => [(object) ['id' => 1], (object) ['id' => 2]],
            2 => [(object) ['id' => 3]],
            default => [],
        };
    });
    DB::shouldReceive('table')->andReturnSelf();
    DB::shouldReceive('insert')->andReturnTrue();
    DB::shouldReceive('purge')->andReturnNull();

    makeOrchestrator()->run($config, $source, $target, $schema, true, [], [], static function (): void {});

    // Two chunks: full window (2), then the trailing short window (1) which ends the loop.
    expect($queries)->toHaveCount(2);

    // First chunk: ordered + limited, no OFFSET, no WHERE, no bindings.
    expect($queries[0]['sql'])->toContain('ORDER BY')->toContain('LIMIT')
        ->not->toContain('OFFSET')
        ->not->toContain('WHERE');
    expect($queries[0]['bindings'])->toBe([]);

    // Second chunk: seek past the last fetched id via a bound row-value comparison.
    expect($queries[1]['sql'])->toContain('WHERE (`id` > ?)')->toContain('ORDER BY')
        ->not->toContain('OFFSET');
    expect($queries[1]['bindings'])->toBe([2]);
});
