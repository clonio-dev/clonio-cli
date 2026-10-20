<?php

declare(strict_types=1);

use App\Data\Cloning\CloningConfigData;
use App\Data\Cloning\CloningOptionsData;
use App\Data\Cloning\ColumnCloningConfigData;
use App\Data\Cloning\KeyRemappingConfigData;
use App\Data\Cloning\RunResultData;
use App\Data\Cloning\TableCloningConfigData;
use App\Data\Cloning\TableRowConfigData;
use App\Data\Cloning\TableRunResultData;
use App\Data\Cloning\TableRunStatus;
use App\Data\ConnectionData;
use App\Data\Schema\ColumnSchemaData;
use App\Data\Schema\DatabaseSchemaData;
use App\Data\Schema\ForeignKeyData;
use App\Data\Schema\TableSchemaData;
use App\Enums\DatabaseConnectionType;
use App\Logging\AuditBuffer;
use App\Services\Cloning\CloningRunOrchestrator;
use App\Services\Cloning\DependencyResolver;
use App\Services\Cloning\KeyRemappingService;
use App\Services\Cloning\SchemaReplicator;
use App\Services\Database\DatabaseConnectionService;
use App\Services\Schema\SchemaInspector;
use App\Services\SqlDump\SqlDumpService;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    app(AuditBuffer::class)->clear();
});

function g3_orch_conn(string $name, DatabaseConnectionType $type = DatabaseConnectionType::Mysql): ConnectionData
{
    return new ConnectionData(
        name: $name,
        type: $type,
        host: 'localhost',
        port: 3306,
        database: 'db',
        schema: null,
        username: 'u',
        password: 'p',
        isProduction: false,
    );
}

/**
 * @param  list<ColumnCloningConfigData>  $columns
 */
function g3_orch_table(string $name, string $strategy = 'full', array $columns = []): TableCloningConfigData
{
    return new TableCloningConfigData($name, new TableRowConfigData($strategy, null, null), $columns);
}

/**
 * @param  list<TableCloningConfigData>  $tables
 */
function g3_orch_config(array $tables, int $chunkSize = 1000, ?KeyRemappingConfigData $keyRemapping = null): CloningConfigData
{
    return new CloningConfigData(
        version: '1',
        connectionName: 'source',
        options: new CloningOptionsData(
            chunkSize: $chunkSize,
            enforceColumnTypes: false,
            dropUnknownTables: false,
            dropExtraColumns: false,
            disableForeignKeyChecks: false,
            fakerLocale: 'en_US',
        ),
        tables: $tables,
        keyRemapping: $keyRemapping,
    );
}

/**
 * @param  list<ColumnSchemaData>|null  $columns
 * @param  list<ForeignKeyData>  $foreignKeys
 */
function g3_orch_schema_table(string $name, ?array $columns = null, array $foreignKeys = []): TableSchemaData
{
    return new TableSchemaData(
        $name,
        $columns ?? [new ColumnSchemaData('id', 'int', false, null, true)],
        $foreignKeys,
    );
}

function g3_orch_make(
    ?SchemaReplicator $replicator = null,
    ?DependencyResolver $resolver = null,
    ?SchemaInspector $inspector = null,
): CloningRunOrchestrator {
    $connector = Mockery::mock(DatabaseConnectionService::class);
    $connector->shouldReceive('open')->andReturnUsing(static fn (ConnectionData $c): string => $c->name.'_conn');

    if (! $replicator instanceof SchemaReplicator) {
        $replicator = Mockery::mock(SchemaReplicator::class);
        $replicator->shouldReceive('replicate')->andReturn([]);
        $replicator->shouldReceive('correctAutoIncrement')->andReturnNull();
    }

    if (! $resolver instanceof DependencyResolver) {
        $resolver = Mockery::mock(DependencyResolver::class);
        $resolver->shouldReceive('computeCascadeExclusions')->andReturn([]);
        $resolver->shouldReceive('sort')->andReturnUsing(static fn ($schema, array $tables): array => $tables);
    }

    if (! $inspector instanceof SchemaInspector) {
        $inspector = Mockery::mock(SchemaInspector::class);
        $inspector->shouldReceive('inspect')->andReturn(new DatabaseSchemaData('target', []));
    }

    return new CloningRunOrchestrator($connector, $replicator, $resolver, $inspector);
}

/**
 * Mock the DB facade. Each select() returns the next chunk from $selectQueue.
 * $onInsert may throw to simulate an insert failure.
 */
function g3_orch_db(array $selectQueue = [], ?Closure $onInsert = null, ?array $purges = null): stdClass
{
    $log = new stdClass;
    $log->selects = [];
    $log->inserts = [];

    DB::shouldReceive('connection')->andReturnSelf();
    DB::shouldReceive('select')->andReturnUsing(static function (string $sql) use ($log, &$selectQueue): array {
        $log->selects[] = $sql;

        return array_shift($selectQueue) ?? [];
    });
    DB::shouldReceive('table')->andReturnSelf();
    DB::shouldReceive('insert')->andReturnUsing(static function (array $payload) use ($log, $onInsert): bool {
        $log->inserts[] = $payload;
        if ($onInsert instanceof Closure) {
            $onInsert($payload);
        }

        return true;
    });
    DB::shouldReceive('statement')->andReturnTrue();
    DB::shouldReceive('delete')->andReturn(0);
    if ($purges === null) {
        DB::shouldReceive('purge')->andReturnNull();
    } else {
        foreach ($purges as $connection) {
            DB::shouldReceive('purge')->once()->with($connection)->andReturnNull();
        }
    }

    return $log;
}

/**
 * @param  list<string>  $skip
 * @param  list<string>  $only
 * @return array{RunResultData, list<array<int, mixed>>}
 */
function g3_orch_run(
    CloningRunOrchestrator $orchestrator,
    CloningConfigData $config,
    DatabaseSchemaData $schema,
    ?ConnectionData $source = null,
    ?ConnectionData $target = null,
    bool $skipSchema = true,
    array $skip = [],
    array $only = [],
    ?KeyRemappingService $keyRemapping = null,
    bool $breakOnFailure = false,
    ?SqlDumpService $dumpSink = null,
): array {
    $progress = [];
    $result = $orchestrator->run(
        $config,
        $source ?? g3_orch_conn('source'),
        $target ?? g3_orch_conn('target'),
        $schema,
        $skipSchema,
        $skip,
        $only,
        static function (string $table, TableRunStatus $status, int $rows, int $skipped, array $skippedRows) use (&$progress): void {
            $progress[] = [$table, $status, $rows, $skipped, $skippedRows];
        },
        $keyRemapping,
        $breakOnFailure,
        null,
        $dumpSink,
    );

    return [$result, $progress];
}

/** @return list<array<string, mixed>> audit events with the given name, without timestamps */
function g3_orch_events(string $event): array
{
    return array_values(array_map(
        static function (array $record): array {
            unset($record['ts']);

            return $record;
        },
        array_filter(app(AuditBuffer::class)->records(), static fn (array $r): bool => $r['event'] === $event),
    ));
}

function g3_orch_resolver(array $cascade = [], ?Closure $sort = null): DependencyResolver
{
    $resolver = Mockery::mock(DependencyResolver::class);
    $resolver->shouldReceive('computeCascadeExclusions')->andReturn($cascade);
    $resolver->shouldReceive('sort')->andReturnUsing($sort ?? static fn ($schema, array $tables): array => $tables);

    return $resolver;
}

it('passes a re-indexed list of remaining tables to the sorter', function (): void {
    $received = null;
    $resolver = g3_orch_resolver(sort: static function ($schema, array $tables) use (&$received): array {
        $received = $tables;

        return $tables;
    });
    g3_orch_db();

    g3_orch_run(
        g3_orch_make(resolver: $resolver),
        g3_orch_config([g3_orch_table('a'), g3_orch_table('b')]),
        new DatabaseSchemaData('src', [g3_orch_schema_table('a'), g3_orch_schema_table('b')]),
        skip: ['a'],
    );

    expect($received)->toBe(['b']);
});

it('records tables skipped via --skip with zero counts and a log event', function (): void {
    g3_orch_db();

    [$result] = g3_orch_run(
        g3_orch_make(),
        g3_orch_config([g3_orch_table('a'), g3_orch_table('b')]),
        new DatabaseSchemaData('src', [g3_orch_schema_table('a'), g3_orch_schema_table('b')]),
        skip: ['a'],
    );

    expect($result->tables[0])->toEqual(new TableRunResultData('a', TableRunStatus::SkippedByFlag, 0, 0, 0.0, 'explicitly excluded via --skip'))
        ->and(g3_orch_events('table_skipped_by_flag'))->toBe([
            ['level' => 'info', 'event' => 'table_skipped_by_flag', 'table' => 'a', 'reason' => 'explicitly excluded via --skip'],
        ]);
});

it('records tables left out by --only with the only-filter reason', function (): void {
    g3_orch_db();

    [$result] = g3_orch_run(
        g3_orch_make(),
        g3_orch_config([g3_orch_table('a'), g3_orch_table('b')]),
        new DatabaseSchemaData('src', [g3_orch_schema_table('a'), g3_orch_schema_table('b')]),
        only: ['b'],
    );

    expect($result->tables[0])->toEqual(new TableRunResultData('a', TableRunStatus::SkippedByFlag, 0, 0, 0.0, 'excluded by --only filter'))
        ->and($result->tables[1]->tableName)->toBe('b')
        ->and($result->tables[1]->status)->toBe(TableRunStatus::Transferred);
});

it('records cascade exclusions with zero counts and a log event', function (): void {
    g3_orch_db();

    [$result] = g3_orch_run(
        g3_orch_make(resolver: g3_orch_resolver(cascade: ['c'])),
        g3_orch_config([g3_orch_table('a'), g3_orch_table('c')]),
        new DatabaseSchemaData('src', [g3_orch_schema_table('a'), g3_orch_schema_table('c')]),
    );

    expect($result->tables[0])->toEqual(new TableRunResultData('c', TableRunStatus::SkippedByCascade, 0, 0, 0.0, 'excluded due to foreign key dependency'))
        ->and(g3_orch_events('table_skipped_by_cascade'))->toBe([
            ['level' => 'info', 'event' => 'table_skipped_by_cascade', 'table' => 'c', 'reason' => 'excluded due to foreign key dependency'],
        ])
        ->and(array_map(static fn (TableRunResultData $t): string => $t->tableName, $result->tables))->toBe(['c', 'a']);
});

it('logs a successful schema replication with the sorted tables', function (): void {
    g3_orch_db();

    g3_orch_run(
        g3_orch_make(),
        g3_orch_config([g3_orch_table('users')]),
        new DatabaseSchemaData('src', [g3_orch_schema_table('users')]),
        skipSchema: false,
    );

    expect(g3_orch_events('schema_replicated'))->toBe([
        ['level' => 'info', 'event' => 'schema_replicated', 'tables' => ['users']],
    ])->and(g3_orch_events('schema_table_failed'))->toBe([]);
});

it('records schema failures with zero counts, logs and progress', function (): void {
    $replicator = Mockery::mock(SchemaReplicator::class);
    $replicator->shouldReceive('replicate')->andReturn(['a' => 'boom']);
    $replicator->shouldReceive('correctAutoIncrement')->andReturnNull();
    g3_orch_db();

    [$result, $progress] = g3_orch_run(
        g3_orch_make(replicator: $replicator),
        g3_orch_config([g3_orch_table('a')]),
        new DatabaseSchemaData('src', [g3_orch_schema_table('a')]),
        skipSchema: false,
    );

    expect($result->tables)->toEqual([new TableRunResultData('a', TableRunStatus::SkippedBySchemaFailure, 0, 0, 0.0, 'boom')])
        ->and($result->success)->toBeFalse()
        ->and($progress)->toBe([['a', TableRunStatus::SkippedBySchemaFailure, 0, 0, []]])
        ->and(g3_orch_events('schema_replicated'))->toBe([])
        ->and(g3_orch_events('schema_table_failed'))->toBe([
            ['level' => 'error', 'event' => 'schema_table_failed', 'table' => 'a', 'error' => 'boom'],
        ])
        ->and(g3_orch_events('table_skipped_schema_failure'))->toBe([
            ['level' => 'warning', 'event' => 'table_skipped_schema_failure', 'table' => 'a', 'reason' => 'boom'],
        ]);
});

it('records a table missing from the source and continues with the next one', function (): void {
    g3_orch_db([[(object) ['id' => 1]]]);

    [$result, $progress] = g3_orch_run(
        g3_orch_make(),
        g3_orch_config([g3_orch_table('missing'), g3_orch_table('users')]),
        new DatabaseSchemaData('src', [g3_orch_schema_table('users')]),
    );

    expect($result->tables[0])->toEqual(new TableRunResultData('missing', TableRunStatus::NotFound, 0, 0, 0.0, null))
        ->and($result->tables[1]->status)->toBe(TableRunStatus::Transferred)
        ->and($result->tables[1]->rowsTransferred)->toBe(1)
        ->and($progress[0])->toBe(['missing', TableRunStatus::NotFound, 0, 0, []])
        ->and(g3_orch_events('table_not_found'))->toBe([
            ['level' => 'warning', 'event' => 'table_not_found', 'table' => 'missing'],
        ]);
});

it('ignores sorted tables that have no table config', function (): void {
    $resolver = g3_orch_resolver(sort: static fn ($schema, array $tables): array => ['ghost', ...$tables]);
    g3_orch_db();

    [$result] = g3_orch_run(
        g3_orch_make(resolver: $resolver),
        g3_orch_config([g3_orch_table('users')]),
        new DatabaseSchemaData('src', [g3_orch_schema_table('users')]),
    );

    expect(array_map(static fn (TableRunResultData $t): string => $t->tableName, $result->tables))->toBe(['users']);
});

it('reads all chunks with advancing offsets and reports totals', function (): void {
    $log = g3_orch_db([
        [(object) ['id' => 1], (object) ['id' => 2]],
        [(object) ['id' => 3]],
    ]);

    [$result, $progress] = g3_orch_run(
        g3_orch_make(),
        g3_orch_config([g3_orch_table('users')], chunkSize: 2),
        new DatabaseSchemaData('src', [g3_orch_schema_table('users')]),
    );

    expect($log->selects)->toBe([
        'SELECT * FROM `users` LIMIT 2 OFFSET 0',
        'SELECT * FROM `users` LIMIT 2 OFFSET 2',
    ])
        ->and($result->totalRows)->toBe(3)
        ->and($result->skippedRows)->toBe(0)
        ->and($result->success)->toBeTrue()
        ->and($result->failureReason)->toBeNull()
        ->and($result->durationSeconds)->toBeLessThan(60.0)
        ->and($result->tables[0]->durationSeconds)->toBeLessThan(60.0)
        ->and($result->tables[0]->rowsSkipped)->toBe(0)
        ->and($progress)->toBe([['users', TableRunStatus::Transferred, 3, 0, []]])
        ->and(g3_orch_events('table_transferred'))->toBe([
            ['level' => 'info', 'event' => 'table_transferred', 'table' => 'users', 'rows' => 3, 'skipped' => 0],
        ]);
});

it('adds skipped rows to the run total', function (): void {
    g3_orch_db(
        [[(object) ['id' => 1], (object) ['id' => 2]]],
        static function (array $payload): void {
            if (array_is_list($payload) || $payload['id'] === 2) {
                throw new RuntimeException('nope');
            }
        },
    );

    [$result] = g3_orch_run(
        g3_orch_make(),
        g3_orch_config([g3_orch_table('users')]),
        new DatabaseSchemaData('src', [g3_orch_schema_table('users')]),
    );

    expect($result->totalRows)->toBe(1)
        ->and($result->skippedRows)->toBe(1)
        ->and($result->tables[0]->status)->toBe(TableRunStatus::Transferred);
});

it('reports the first bulk insert error when every row fails', function (): void {
    $bulk = 0;
    g3_orch_db(
        [[(object) ['id' => 1]], [(object) ['id' => 2]]],
        static function (array $payload) use (&$bulk): void {
            if (array_is_list($payload)) {
                $bulk++;

                throw new RuntimeException('bulk '.$bulk);
            }

            throw new RuntimeException('row failed');
        },
    );

    [$result] = g3_orch_run(
        g3_orch_make(),
        g3_orch_config([g3_orch_table('users')], chunkSize: 1),
        new DatabaseSchemaData('src', [g3_orch_schema_table('users')]),
    );

    expect($result->tables[0]->failureReason)->toBe('All 2 rows failed to insert: bulk 1')
        ->and($result->tables[0]->rowsSkipped)->toBe(2);
});

it('fails a table whose only row cannot be inserted', function (): void {
    g3_orch_db(
        [[(object) ['id' => 1]]],
        static function (array $payload): void {
            throw new RuntimeException(array_is_list($payload) ? 'bulk broke' : 'row broke');
        },
    );

    [$result] = g3_orch_run(
        g3_orch_make(),
        g3_orch_config([g3_orch_table('users')]),
        new DatabaseSchemaData('src', [g3_orch_schema_table('users')]),
    );

    expect($result->success)->toBeFalse()
        ->and($result->failureReason)->toBe('One or more tables failed to transfer')
        ->and($result->tables[0]->status)->toBe(TableRunStatus::Failed)
        ->and($result->tables[0]->failureReason)->toBe('All 1 rows failed to insert: bulk broke')
        ->and(g3_orch_events('table_transfer_failed'))->toBe([
            ['level' => 'error', 'event' => 'table_transfer_failed', 'table' => 'users', 'reason' => 'All 1 rows failed to insert: bulk broke'],
        ]);
});

it('keeps going after successful tables when breakOnFailure is set', function (): void {
    g3_orch_db();

    [$result] = g3_orch_run(
        g3_orch_make(),
        g3_orch_config([g3_orch_table('a'), g3_orch_table('b')]),
        new DatabaseSchemaData('src', [g3_orch_schema_table('a'), g3_orch_schema_table('b')]),
        breakOnFailure: true,
    );

    expect(array_map(static fn (TableRunResultData $t): string => $t->tableName, $result->tables))->toBe(['a', 'b']);
});

it('returns zero counts for a schema-only table on a live target', function (): void {
    $log = g3_orch_db();

    [$result] = g3_orch_run(
        g3_orch_make(),
        g3_orch_config([g3_orch_table('users', 'schema')]),
        new DatabaseSchemaData('src', [g3_orch_schema_table('users')]),
    );

    expect($log->selects)->toBe([])
        ->and($result->tables[0]->status)->toBe(TableRunStatus::Transferred)
        ->and($result->tables[0]->rowsTransferred)->toBe(0)
        ->and($result->tables[0]->rowsSkipped)->toBe(0)
        ->and($result->skippedRows)->toBe(0);
});

/**
 * @param  list<ColumnSchemaData>  $columns
 */
function g3_orch_auto_increment(
    array $columns,
    DatabaseConnectionType $targetType = DatabaseConnectionType::Mysql,
    bool $expectCall = true,
    ?Closure $onInsert = null,
): RunResultData {
    $target = g3_orch_conn('target', $targetType);

    $replicator = Mockery::mock(SchemaReplicator::class);
    $replicator->shouldReceive('replicate')->andReturn([]);
    if ($expectCall) {
        $replicator->shouldReceive('correctAutoIncrement')->once()->with($target, 'users', 'id')->andReturnNull();
    } else {
        $replicator->shouldReceive('correctAutoIncrement')->never();
    }

    g3_orch_db([[(object) ['id' => 1]]], $onInsert);

    [$result] = g3_orch_run(
        g3_orch_make(replicator: $replicator),
        g3_orch_config([g3_orch_table('users')]),
        new DatabaseSchemaData('src', [g3_orch_schema_table('users', $columns)]),
        target: $target,
    );

    return $result;
}

it('corrects AUTO_INCREMENT for a single integer primary key', function (string $type, DatabaseConnectionType $targetType): void {
    $result = g3_orch_auto_increment([
        new ColumnSchemaData('name', 'varchar', true, null, false),
        new ColumnSchemaData('id', $type, false, null, true),
    ], $targetType);

    expect($result->success)->toBeTrue();
})->with([
    'int' => ['int', DatabaseConnectionType::Mysql],
    'bigint' => ['bigint', DatabaseConnectionType::Mysql],
    'mediumint' => ['mediumint', DatabaseConnectionType::Mysql],
    'smallint' => ['smallint', DatabaseConnectionType::Mysql],
    'tinyint' => ['tinyint', DatabaseConnectionType::Mysql],
    'integer' => ['integer', DatabaseConnectionType::Mysql],
    'upper-case INT' => ['INT', DatabaseConnectionType::Mysql],
    'mariadb' => ['int', DatabaseConnectionType::MariaDB],
]);

it('does not correct AUTO_INCREMENT when it does not apply', function (array $columns, DatabaseConnectionType $targetType): void {
    g3_orch_auto_increment($columns, $targetType, expectCall: false);
})->with([
    'postgres target' => [[new ColumnSchemaData('id', 'int', false, null, true)], DatabaseConnectionType::PostgreSQL],
    'varchar key' => [[new ColumnSchemaData('id', 'varchar', false, null, true)], DatabaseConnectionType::Mysql],
    'no primary key' => [[new ColumnSchemaData('id', 'int', false, null, false)], DatabaseConnectionType::Mysql],
    'composite key' => [[
        new ColumnSchemaData('id', 'int', false, null, true),
        new ColumnSchemaData('tenant', 'int', false, null, true),
    ], DatabaseConnectionType::Mysql],
]);

it('does not correct AUTO_INCREMENT for a failed table', function (): void {
    $result = g3_orch_auto_increment(
        [new ColumnSchemaData('id', 'int', false, null, true)],
        expectCall: false,
        onInsert: static function (): void {
            throw new RuntimeException('down');
        },
    );

    expect($result->success)->toBeFalse();
});

it('logs a failed AUTO_INCREMENT correction without failing the run', function (): void {
    $replicator = Mockery::mock(SchemaReplicator::class);
    $replicator->shouldReceive('replicate')->andReturn([]);
    $replicator->shouldReceive('correctAutoIncrement')->once()->andThrow(new RuntimeException('no alter'));
    g3_orch_db([[(object) ['id' => 1]]]);

    [$result] = g3_orch_run(
        g3_orch_make(replicator: $replicator),
        g3_orch_config([g3_orch_table('users')]),
        new DatabaseSchemaData('src', [g3_orch_schema_table('users')]),
    );

    expect($result->success)->toBeTrue()
        ->and(g3_orch_events('auto_increment_correction_failed'))->toBe([
            ['level' => 'warning', 'event' => 'auto_increment_correction_failed', 'table' => 'users', 'error' => 'no alter'],
        ]);
});

it('omits a generated source column that is unknown on a live target', function (): void {
    $log = g3_orch_db([[(object) ['id' => 1]]]);

    [$result] = g3_orch_run(
        g3_orch_make(),
        g3_orch_config([g3_orch_table('users')]),
        new DatabaseSchemaData('src', [g3_orch_schema_table('users', [
            new ColumnSchemaData('id', 'int', false, null, true),
            new ColumnSchemaData('g', 'int', true, null, false, generated: true),
        ])]),
    );

    expect($result->tables[0]->notes)->toBe(['Generated columns not copied: g'])
        ->and($log->selects[0])->toBe('SELECT `id` FROM `users` LIMIT 1000 OFFSET 0')
        ->and(g3_orch_events('table_column_note'))->toBe([
            ['level' => 'warning', 'event' => 'table_column_note', 'table' => 'users', 'note' => 'Generated columns not copied: g'],
        ]);
});

it('adds no empty-parent note to a schema-only table', function (): void {
    g3_orch_db();

    [$result] = g3_orch_run(
        g3_orch_make(),
        g3_orch_config([g3_orch_table('users', 'schema'), g3_orch_table('orders', 'schema')]),
        new DatabaseSchemaData('src', [
            g3_orch_schema_table('users'),
            g3_orch_schema_table('orders', null, [new ForeignKeyData('user_id', 'users', 'id')]),
        ]),
    );

    expect($result->tables[1]->tableName)->toBe('orders')
        ->and($result->tables[1]->notes)->toBe([]);
});

it('notes each schema-only parent once and ignores data and self references', function (): void {
    g3_orch_db();

    [$result] = g3_orch_run(
        g3_orch_make(),
        g3_orch_config([g3_orch_table('users'), g3_orch_table('tags', 'schema'), g3_orch_table('orders')]),
        new DatabaseSchemaData('src', [
            g3_orch_schema_table('users'),
            g3_orch_schema_table('tags'),
            g3_orch_schema_table('orders', null, [
                new ForeignKeyData('user_id', 'users', 'id'),
                new ForeignKeyData('tag_id', 'tags', 'id'),
                new ForeignKeyData('alt_tag_id', 'tags', 'id'),
                new ForeignKeyData('parent_id', 'orders', 'id'),
            ]),
        ]),
    );

    expect($result->tables[0]->notes)->toBe([])
        ->and($result->tables[2]->notes)->toBe([
            "References tables copied without data ('schema' strategy): tags. Foreign key values may point to missing rows, or inserts fail when foreign key checks are enabled.",
        ]);
});

it('adds no note to a self-referencing table', function (): void {
    g3_orch_db();

    [$result] = g3_orch_run(
        g3_orch_make(),
        g3_orch_config([g3_orch_table('nodes'), g3_orch_table('tags', 'schema')]),
        new DatabaseSchemaData('src', [
            g3_orch_schema_table('nodes', null, [new ForeignKeyData('parent_id', 'nodes', 'id')]),
            g3_orch_schema_table('tags'),
        ]),
    );

    expect($result->tables[0]->notes)->toBe([]);
});

function g3_orch_exclude(string $column): ColumnCloningConfigData
{
    return new ColumnCloningConfigData($column, 'exclude', null, [], null, null, null, null, null, null);
}

it('selects quoted columns and drops excluded values from the inserted rows', function (DatabaseConnectionType $type, string $sql): void {
    $log = g3_orch_db([[(object) ['id' => 1, 'name' => 'secret']]]);

    g3_orch_run(
        g3_orch_make(),
        g3_orch_config([g3_orch_table('users', 'full', [g3_orch_exclude('secret'), g3_orch_exclude('name')])]),
        new DatabaseSchemaData('src', [g3_orch_schema_table('users', [
            new ColumnSchemaData('id', 'int', false, null, true),
            new ColumnSchemaData('nick', 'varchar', true, null, false),
            new ColumnSchemaData('name', 'varchar', true, null, false),
        ])]),
        source: g3_orch_conn('source', $type),
    );

    expect($log->selects[0])->toBe($sql)
        ->and($log->inserts[0])->toBe([['id' => 1]]);
})->with([
    'mysql' => [DatabaseConnectionType::Mysql, 'SELECT `id`, `nick` FROM `users` LIMIT 1000 OFFSET 0'],
    'mariadb' => [DatabaseConnectionType::MariaDB, 'SELECT `id`, `nick` FROM `users` LIMIT 1000 OFFSET 0'],
    'sqlsrv' => [DatabaseConnectionType::SqlServer, 'SELECT [id], [nick] FROM [users] ORDER BY (SELECT NULL) OFFSET 0 ROWS FETCH NEXT 1000 ROWS ONLY'],
    'pgsql' => [DatabaseConnectionType::PostgreSQL, 'SELECT "id", "nick" FROM "users" LIMIT 1000 OFFSET 0'],
]);

it('applies key remapping only when both the service and the config are present', function (bool $withService, bool $withConfig, array $expectedRow): void {
    $service = null;
    if ($withService) {
        $service = Mockery::mock(KeyRemappingService::class);
        if ($withConfig) {
            $service->shouldReceive('applyToRow')->once()->andReturnUsing(static fn (array $row): array => ['id' => $row['id'] + 100]);
        } else {
            $service->shouldReceive('applyToRow')->never();
        }
    }

    $log = g3_orch_db([[(object) ['id' => 1]]]);

    [$result] = g3_orch_run(
        g3_orch_make(),
        g3_orch_config([g3_orch_table('users')], keyRemapping: $withConfig ? new KeyRemappingConfigData([]) : null),
        new DatabaseSchemaData('src', [g3_orch_schema_table('users')]),
        keyRemapping: $service,
    );

    expect($result->success)->toBeTrue()
        ->and($log->inserts[0])->toBe([$expectedRow]);
})->with([
    'service and config' => [true, true, ['id' => 101]],
    'service only' => [true, false, ['id' => 1]],
    'config only' => [false, true, ['id' => 1]],
]);

function g3_orch_dump_sink(): SqlDumpService
{
    $sink = Mockery::mock(SqlDumpService::class);
    $sink->shouldReceive('begin')->once();

    return $sink;
}

it('writes a dump without inspecting the target or correcting AUTO_INCREMENT', function (): void {
    $sink = g3_orch_dump_sink();
    $sink->shouldReceive('writeRows')->once()->with('users', [['id' => 1]]);

    $inspector = Mockery::mock(SchemaInspector::class);
    $inspector->shouldReceive('inspect')->never();

    $replicator = Mockery::mock(SchemaReplicator::class);
    $replicator->shouldReceive('replicate')->never();
    $replicator->shouldReceive('correctAutoIncrement')->never();

    g3_orch_db([[(object) ['id' => 1]]]);

    [$result, $progress] = g3_orch_run(
        g3_orch_make(replicator: $replicator, inspector: $inspector),
        g3_orch_config([g3_orch_table('users')]),
        new DatabaseSchemaData('src', [g3_orch_schema_table('users')]),
        dumpSink: $sink,
    );

    expect($result->success)->toBeTrue()
        ->and($result->tables[0]->rowsTransferred)->toBe(1)
        ->and($result->tables[0]->rowsSkipped)->toBe(0)
        ->and($progress)->toBe([['users', TableRunStatus::Transferred, 1, 0, []]])
        ->and(g3_orch_events('dump_schema_written'))->toBe([
            ['level' => 'info', 'event' => 'dump_schema_written', 'tables' => ['users']],
        ]);
});

it('writes no rows to a dump for a schema-only table', function (): void {
    $sink = g3_orch_dump_sink();
    $sink->shouldReceive('writeRows')->never();
    $log = g3_orch_db();

    [$result] = g3_orch_run(
        g3_orch_make(),
        g3_orch_config([g3_orch_table('users', 'schema')]),
        new DatabaseSchemaData('src', [g3_orch_schema_table('users')]),
        dumpSink: $sink,
    );

    expect($log->selects)->toBe([])
        ->and($result->tables[0]->rowsTransferred)->toBe(0)
        ->and($result->tables[0]->rowsSkipped)->toBe(0)
        ->and($result->tables[0]->status)->toBe(TableRunStatus::Transferred)
        ->and($result->success)->toBeTrue();
});

it('passes a re-indexed list of --only exclusions to the cascade resolver', function (): void {
    $received = null;
    $resolver = Mockery::mock(DependencyResolver::class);
    $resolver->shouldReceive('computeCascadeExclusions')->andReturnUsing(static function ($schema, array $excluded) use (&$received): array {
        $received = $excluded;

        return [];
    });
    $resolver->shouldReceive('sort')->andReturnUsing(static fn ($schema, array $tables): array => $tables);
    g3_orch_db();

    g3_orch_run(
        g3_orch_make(resolver: $resolver),
        g3_orch_config([g3_orch_table('a'), g3_orch_table('b'), g3_orch_table('c')]),
        new DatabaseSchemaData('src', [g3_orch_schema_table('a'), g3_orch_schema_table('b'), g3_orch_schema_table('c')]),
        only: ['b'],
    );

    expect($received)->toBe(['a', 'c']);
});

it('purges both connections after a live table transfer', function (): void {
    g3_orch_db([[(object) ['id' => 1]]], purges: ['source_conn', 'target_conn']);

    [$result] = g3_orch_run(
        g3_orch_make(),
        g3_orch_config([g3_orch_table('users')]),
        new DatabaseSchemaData('src', [g3_orch_schema_table('users')]),
    );

    expect($result->success)->toBeTrue();
});

/**
 * @param  list<ColumnSchemaData>  $sourceColumns
 * @param  list<ColumnSchemaData>  $targetColumns
 * @param  list<ColumnCloningConfigData>  $columnConfigs
 * @return list<string>
 */
function g3_orch_notes(array $sourceColumns, array $targetColumns, array $columnConfigs = []): array
{
    $inspector = Mockery::mock(SchemaInspector::class);
    $inspector->shouldReceive('inspect')->once()->andReturn(new DatabaseSchemaData('target', [g3_orch_schema_table('users', $targetColumns)]));
    g3_orch_db();

    [$result] = g3_orch_run(
        g3_orch_make(inspector: $inspector),
        g3_orch_config([g3_orch_table('users', 'full', $columnConfigs)]),
        new DatabaseSchemaData('src', [g3_orch_schema_table('users', $sourceColumns)]),
    );

    return $result->tables[0]->notes;
}

it('notes columns generated by the inspected target', function (bool $sourceGenerated): void {
    $notes = g3_orch_notes(
        [new ColumnSchemaData('id', 'int', false, null, true), new ColumnSchemaData('g', 'int', true, null, false, generated: $sourceGenerated)],
        [new ColumnSchemaData('id', 'int', false, null, true), new ColumnSchemaData('g', 'int', true, null, false, generated: true)],
    );

    expect($notes)->toBe(['Generated columns not copied, computed by the target: g']);
})->with(['plain source column' => [false], 'generated source column' => [true]]);

it('notes a generated source column that is a regular column on the target', function (): void {
    $notes = g3_orch_notes(
        [new ColumnSchemaData('id', 'int', false, null, true), new ColumnSchemaData('g', 'int', true, null, false, generated: true)],
        [new ColumnSchemaData('id', 'int', false, null, true), new ColumnSchemaData('g', 'int', true, null, false)],
    );

    expect($notes)->toBe(['Generated columns not copied and left empty: g. The generation expression was not replicated to the target.']);
});

it('keeps checking columns after a generated one', function (): void {
    $notes = g3_orch_notes(
        [
            new ColumnSchemaData('id', 'int', false, null, true),
            new ColumnSchemaData('g', 'int', true, null, false, generated: true),
            new ColumnSchemaData('secret', 'varchar', true, null, false),
        ],
        [],
        [g3_orch_exclude('secret')],
    );

    expect($notes)->toBe([
        'Generated columns not copied: g',
        "Columns excluded by 'exclude' strategy: secret",
    ]);
});

it('warns when an excluded column is NOT NULL without a default on the target', function (ColumnSchemaData $targetColumn, bool $warns): void {
    $notes = g3_orch_notes(
        [new ColumnSchemaData('id', 'int', false, null, true), new ColumnSchemaData('secret', 'varchar', true, null, false)],
        [new ColumnSchemaData('id', 'int', false, null, true), $targetColumn],
        [g3_orch_exclude('secret')],
    );

    $expected = ["Columns excluded by 'exclude' strategy: secret"];
    if ($warns) {
        $expected[] = 'Excluded columns are NOT NULL without a default on the target, inserts may fail: secret';
    }

    expect($notes)->toBe($expected);
})->with([
    'not null without default' => [new ColumnSchemaData('secret', 'varchar', false, null, false), true],
    'nullable' => [new ColumnSchemaData('secret', 'varchar', true, null, false), false],
    'with default' => [new ColumnSchemaData('secret', 'varchar', false, 'x', false), false],
    'primary key' => [new ColumnSchemaData('secret', 'varchar', false, null, true), false],
]);

it('notes no NOT NULL warning when the excluded column is missing on the target', function (): void {
    $notes = g3_orch_notes(
        [new ColumnSchemaData('id', 'int', false, null, true), new ColumnSchemaData('secret', 'varchar', true, null, false)],
        [new ColumnSchemaData('id', 'int', false, null, true)],
        [g3_orch_exclude('secret')],
    );

    expect($notes)->toBe(["Columns excluded by 'exclude' strategy: secret"]);
});

it('keeps the columns after an excluded one and anonymizes configured columns', function (): void {
    $log = g3_orch_db([[(object) ['id' => 1, 'name' => 'secret', 'nick' => 'n', 'email' => 'a@b.c']]]);

    g3_orch_run(
        g3_orch_make(),
        g3_orch_config([g3_orch_table('users', 'full', [
            g3_orch_exclude('name'),
            new ColumnCloningConfigData('email', 'static', null, [], null, null, null, null, null, 'hidden@example.com'),
        ])]),
        new DatabaseSchemaData('src', [g3_orch_schema_table('users')]),
    );

    expect($log->inserts[0])->toBe([['id' => 1, 'nick' => 'n', 'email' => 'hidden@example.com']]);
});

it('notes generated columns as left empty when dumping', function (): void {
    $sink = g3_orch_dump_sink();
    $sink->shouldReceive('writeRows');
    g3_orch_db([[(object) ['id' => 1]]]);

    [$result] = g3_orch_run(
        g3_orch_make(),
        g3_orch_config([g3_orch_table('users')]),
        new DatabaseSchemaData('src', [g3_orch_schema_table('users', [
            new ColumnSchemaData('id', 'int', false, null, true),
            new ColumnSchemaData('g', 'int', true, null, false, generated: true),
        ])]),
        dumpSink: $sink,
    );

    expect($result->tables[0]->notes)->toBe(['Generated columns not copied and left empty: g. The generation expression was not replicated to the target.']);
});

it('tells the dump whether to emit the schema', function (bool $skipSchema): void {
    $sink = Mockery::mock(SqlDumpService::class);
    $sink->shouldReceive('begin')->once()->withArgs(
        static fn ($dumpConn, $source, $schema, array $tables, $options, $at, bool $emitSchema): bool => $tables === ['users'] && $emitSchema === ! $skipSchema,
    );
    $sink->shouldReceive('writeRows');
    g3_orch_db();

    [$result] = g3_orch_run(
        g3_orch_make(),
        g3_orch_config([g3_orch_table('users')]),
        new DatabaseSchemaData('src', [g3_orch_schema_table('users')]),
        skipSchema: $skipSchema,
        dumpSink: $sink,
    );

    expect($result->success)->toBeTrue();
})->with(['skip schema' => [true], 'emit schema' => [false]]);

it('dumps every chunk with advancing offsets and purges the source connection', function (): void {
    $sink = g3_orch_dump_sink();
    $sink->shouldReceive('writeRows')->once()->with('users', [['id' => 1]])->ordered();
    $sink->shouldReceive('writeRows')->once()->with('users', [['id' => 2]])->ordered();
    $log = g3_orch_db([[(object) ['id' => 1]], [(object) ['id' => 2]]], purges: ['source_conn']);

    [$result] = g3_orch_run(
        g3_orch_make(),
        g3_orch_config([g3_orch_table('users')], chunkSize: 1),
        new DatabaseSchemaData('src', [g3_orch_schema_table('users')]),
        dumpSink: $sink,
    );

    expect($log->selects)->toBe([
        'SELECT * FROM `users` LIMIT 1 OFFSET 0',
        'SELECT * FROM `users` LIMIT 1 OFFSET 1',
        'SELECT * FROM `users` LIMIT 1 OFFSET 2',
    ])
        ->and($result->success)->toBeTrue()
        ->and($result->tables[0]->rowsTransferred)->toBe(2);
});

it('stops dumping after a short chunk', function (): void {
    $sink = g3_orch_dump_sink();
    $sink->shouldReceive('writeRows')->once()->with('users', [['id' => 1]]);
    $log = g3_orch_db([[(object) ['id' => 1]], [(object) ['id' => 2]]]);

    [$result] = g3_orch_run(
        g3_orch_make(),
        g3_orch_config([g3_orch_table('users')], chunkSize: 2),
        new DatabaseSchemaData('src', [g3_orch_schema_table('users')]),
        dumpSink: $sink,
    );

    expect($log->selects)->toBe(['SELECT * FROM `users` LIMIT 2 OFFSET 0'])
        ->and($result->tables[0]->rowsTransferred)->toBe(1);
});

it('reports a failed dump read as a failed table', function (): void {
    $sink = g3_orch_dump_sink();
    DB::shouldReceive('connection')->andReturnSelf();
    DB::shouldReceive('select')->andThrow(new RuntimeException('read failed'));
    DB::shouldReceive('purge')->once()->with('source_conn');

    [$result] = g3_orch_run(
        g3_orch_make(),
        g3_orch_config([g3_orch_table('users')]),
        new DatabaseSchemaData('src', [g3_orch_schema_table('users')]),
        dumpSink: $sink,
    );

    expect($result->tables[0]->status)->toBe(TableRunStatus::Failed)
        ->and($result->tables[0]->failureReason)->toBe('read failed')
        ->and($result->tables[0]->rowsSkipped)->toBe(0)
        ->and($result->skippedRows)->toBe(0);
});
