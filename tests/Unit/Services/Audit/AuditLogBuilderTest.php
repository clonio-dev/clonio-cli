<?php

declare(strict_types=1);

use App\Data\Audit\AuditRecordData;
use App\Data\Cloning\CloningConfigData;
use App\Data\Cloning\CloningOptionsData;
use App\Data\Cloning\ColumnCloningConfigData;
use App\Data\Cloning\RunResultData;
use App\Data\Cloning\TableCloningConfigData;
use App\Data\Cloning\TableRowConfigData;
use App\Data\Cloning\TableRunResultData;
use App\Data\Cloning\TableRunStatus;
use App\Data\ConnectionData;
use App\Data\SqlDump\DumpResultData;
use App\Enums\DatabaseConnectionType;
use App\Services\Audit\AuditLogBuilder;
use App\Services\Audit\AuditLogSigner;

function makeBuilderSourceConnection(): ConnectionData
{
    return new ConnectionData(
        name: 'production-db',
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

function makeBuilderTargetConnection(): ConnectionData
{
    return new ConnectionData(
        name: 'staging',
        type: DatabaseConnectionType::Mysql,
        host: 'db.staging.io',
        port: 3306,
        database: 'stagingdb',
        schema: null,
        username: 'root',
        password: 'secret',
        isProduction: false,
    );
}

function makeBuilderCloningConfig(): CloningConfigData
{
    return new CloningConfigData(
        version: '1',
        connectionName: 'production-db',
        options: new CloningOptionsData(
            chunkSize: 1000,
            enforceColumnTypes: false,
            dropUnknownTables: false,
            dropExtraColumns: false,
            disableForeignKeyChecks: true,
            fakerLocale: 'en_US',
        ),
        tables: [
            new TableCloningConfigData(
                tableName: 'users',
                rows: new TableRowConfigData(strategy: 'full', limit: null, sortBy: null),
                columns: [],
            ),
        ],
    );
}

function makeBuilderRunResult(): RunResultData
{
    return new RunResultData(
        success: true,
        tables: [
            new TableRunResultData(
                tableName: 'users',
                status: TableRunStatus::Transferred,
                rowsTransferred: 100,
                rowsSkipped: 0,
                durationSeconds: 0.5,
                failureReason: null,
            ),
        ],
        totalRows: 100,
        skippedRows: 0,
        durationSeconds: 0.6,
        failureReason: null,
    );
}

it('builds an AuditRecordData from config and result', function (): void {
    $signer = new AuditLogSigner;
    $builder = new AuditLogBuilder($signer);

    $startedAt = new DateTimeImmutable('2026-04-01T14:32:00', new DateTimeZone('UTC'));
    $finishedAt = new DateTimeImmutable('2026-04-01T14:34:14', new DateTimeZone('UTC'));

    $record = $builder->build(
        config: makeBuilderCloningConfig(),
        result: makeBuilderRunResult(),
        targetConnection: 'staging',
        startedAt: $startedAt,
        finishedAt: $finishedAt,
        yamlFileName: 'test.cloning.yaml',
        sourceConnectionData: makeBuilderSourceConnection(),
        targetConnectionData: makeBuilderTargetConnection(),
    );

    expect($record)->toBeInstanceOf(AuditRecordData::class);
    expect($record->sourceConnection)->toBe('production-db');
    expect($record->targetConnection)->toBe('staging');
    expect($record->success)->toBeTrue();
    expect($record->totalRowsTransferred)->toBe(100);
    expect($record->tables)->toHaveCount(1);
    expect($record->contentHash)->not->toBeEmpty();
    expect($record->hmacSignature)->not->toBeEmpty();
    expect($record->sourceConnectionDetails)->toMatchArray([
        'name' => 'production-db',
        'type' => 'mysql',
        'host' => 'db.prod.io',
        'port' => 3306,
        'database' => 'mydb',
        'username' => 'root',
    ]);
    expect($record->targetConnectionDetails)->toMatchArray([
        'name' => 'staging',
        'type' => 'mysql',
        'host' => 'db.staging.io',
        'port' => 3306,
        'database' => 'stagingdb',
        'username' => 'root',
    ]);
    expect(json_encode($record->sourceConnectionDetails))->not->toContain('secret');
    expect(json_encode($record->targetConnectionDetails))->not->toContain('secret');
});

it('builds a record without ConnectionData (legacy callers) producing stub details', function (): void {
    $signer = new AuditLogSigner;
    $builder = new AuditLogBuilder($signer);

    $record = $builder->build(
        config: makeBuilderCloningConfig(),
        result: makeBuilderRunResult(),
        targetConnection: 'staging',
        startedAt: new DateTimeImmutable('2026-04-01T14:32:00', new DateTimeZone('UTC')),
        finishedAt: new DateTimeImmutable('2026-04-01T14:34:14', new DateTimeZone('UTC')),
        yamlFileName: 'test.cloning.yaml',
    );

    expect($record->sourceConnectionDetails['name'])->toBe('production-db');
    expect($record->sourceConnectionDetails['host'])->toBeNull();
    expect($record->targetConnectionDetails['name'])->toBe('staging');
});

it('records transformed (non-keep) columns and counts kept columns', function (): void {
    $signer = new AuditLogSigner;
    $builder = new AuditLogBuilder($signer);

    $config = new CloningConfigData(
        version: '1',
        connectionName: 'production-db',
        options: new CloningOptionsData(1000, false, false, false, true, 'en_US'),
        tables: [
            new TableCloningConfigData(
                tableName: 'users',
                rows: new TableRowConfigData(strategy: 'full', limit: null, sortBy: null),
                columns: [
                    new ColumnCloningConfigData('email', 'fake', 'safeEmail', [], null, null, null, null, null, null),
                    new ColumnCloningConfigData('id', 'keep', null, [], null, null, null, null, null, null),
                ],
            ),
        ],
    );

    $record = $builder->build(
        config: $config,
        result: makeBuilderRunResult(),
        targetConnection: 'staging',
        startedAt: new DateTimeImmutable('2026-04-01T14:32:00', new DateTimeZone('UTC')),
        finishedAt: new DateTimeImmutable('2026-04-01T14:34:14', new DateTimeZone('UTC')),
        yamlFileName: 'test.cloning.yaml',
        sourceConnectionData: makeBuilderSourceConnection(),
        targetConnectionData: makeBuilderTargetConnection(),
    );

    expect($record->tables[0]->transformedColumns)->toHaveCount(1)
        ->and($record->tables[0]->transformedColumns[0]->columnName)->toBe('email')
        ->and($record->tables[0]->transformedColumns[0]->strategy)->toBe('fake')
        ->and($record->tables[0]->keptColumnCount)->toBe(1);
});

it('carries table notes from the run result into the audit record', function (): void {
    $result = new RunResultData(
        success: true,
        tables: [
            new TableRunResultData('users', TableRunStatus::Transferred, 100, 0, 0.5, null, ['Generated columns not copied, computed by the target: full_name']),
        ],
        totalRows: 100,
        skippedRows: 0,
        durationSeconds: 0.6,
        failureReason: null,
    );

    $record = (new AuditLogBuilder(new AuditLogSigner))->build(
        config: makeBuilderCloningConfig(),
        result: $result,
        targetConnection: 'staging',
        startedAt: new DateTimeImmutable('2026-04-01T14:32:00', new DateTimeZone('UTC')),
        finishedAt: new DateTimeImmutable('2026-04-01T14:34:14', new DateTimeZone('UTC')),
        yamlFileName: 'test.cloning.yaml',
    );

    expect($record->tables[0]->notes)->toBe(['Generated columns not copied, computed by the target: full_name']);
});

it('maps statuses, row strategies and unconfigured tables onto audit table records', function (): void {
    $config = new CloningConfigData(
        version: '1',
        connectionName: 'production-db',
        options: new CloningOptionsData(1000, false, false, false, true, 'en_US'),
        tables: [
            new TableCloningConfigData(
                tableName: 'users',
                rows: new TableRowConfigData(strategy: 'limit', limit: 10, sortBy: null),
                columns: [],
            ),
        ],
    );

    $result = new RunResultData(
        success: false,
        tables: [
            new TableRunResultData('users', TableRunStatus::SkippedByFlag, 0, 0, 0.0, null),
            new TableRunResultData('ghost', TableRunStatus::NotFound, 0, 0, 0.0, null),
            new TableRunResultData('orders', TableRunStatus::Transferred, 7, 2, 1.25, null),
        ],
        totalRows: 7,
        skippedRows: 2,
        durationSeconds: 1.3,
        failureReason: null,
    );

    $record = (new AuditLogBuilder(new AuditLogSigner))->build(
        config: $config,
        result: $result,
        targetConnection: 'staging',
        startedAt: new DateTimeImmutable('2026-04-01T14:32:00', new DateTimeZone('UTC')),
        finishedAt: new DateTimeImmutable('2026-04-01T14:34:14', new DateTimeZone('UTC')),
        yamlFileName: 'test.cloning.yaml',
    );

    [$users, $ghost, $orders] = $record->tables;

    expect($users->skippedByFlag)->toBeTrue()
        ->and($users->existed)->toBeTrue()
        ->and($users->rowStrategy)->toBe('limit')
        ->and($users->rowLimit)->toBe(10)
        ->and($ghost->existed)->toBeFalse()
        ->and($ghost->skippedByFlag)->toBeFalse()
        ->and($ghost->rowStrategy)->toBe('full')
        ->and($ghost->rowLimit)->toBeNull()
        ->and($orders->existed)->toBeTrue()
        ->and($orders->skippedByFlag)->toBeFalse()
        ->and($orders->rowStrategy)->toBe('full')
        ->and($orders->rowLimit)->toBeNull()
        ->and($orders->transformedColumns)->toBe([])
        ->and($orders->keptColumnCount)->toBe(0)
        ->and($orders->rowsTransferred)->toBe(7)
        ->and($orders->rowsSkipped)->toBe(2)
        ->and($orders->durationSeconds)->toBe(1.25)
        ->and($record->success)->toBeFalse()
        ->and($record->totalRowsSkipped)->toBe(2);
});

it('uses the configured clonio version, falling back to 1.0.0 for non-string values', function (mixed $version, string $expected): void {
    config(['app.version' => $version]);

    $record = (new AuditLogBuilder(new AuditLogSigner))->build(
        config: makeBuilderCloningConfig(),
        result: makeBuilderRunResult(),
        targetConnection: 'staging',
        startedAt: new DateTimeImmutable('2026-04-01T14:32:00', new DateTimeZone('UTC')),
        finishedAt: new DateTimeImmutable('2026-04-01T14:34:14', new DateTimeZone('UTC')),
        yamlFileName: 'test.cloning.yaml',
    );

    expect($record->clonioVersion)->toBe($expected);
})->with([
    'string' => ['9.8.7', '9.8.7'],
    'non-string' => [42, '1.0.0'],
]);

it('produces exact stub connection details without ConnectionData', function (): void {
    $record = (new AuditLogBuilder(new AuditLogSigner))->build(
        config: makeBuilderCloningConfig(),
        result: makeBuilderRunResult(),
        targetConnection: 'staging',
        startedAt: new DateTimeImmutable('2026-04-01T14:32:00', new DateTimeZone('UTC')),
        finishedAt: new DateTimeImmutable('2026-04-01T14:34:14', new DateTimeZone('UTC')),
        yamlFileName: 'test.cloning.yaml',
    );

    expect($record->sourceConnectionDetails)->toBe([
        'name' => 'production-db',
        'type' => '',
        'host' => null,
        'port' => null,
        'database' => null,
        'schema' => null,
        'username' => null,
    ])->and($record->dumpDialect)->toBeNull()
        ->and($record->dumpFile)->toBeNull()
        ->and($record->dumpSha256)->toBeNull()
        ->and($record->dumpEncrypted)->toBeNull();
});

it('carries dump details and signs an unsigned placeholder record', function (): void {
    $signer = Mockery::mock(AuditLogSigner::class);
    $signer->shouldReceive('sign')->once()
        ->with(Mockery::on(fn (AuditRecordData $placeholder): bool => $placeholder->contentHash === ''
            && $placeholder->hmacSignature === ''
            && $placeholder->dumpFile === '/tmp/dump.zip'
            && $placeholder->dumpSha256 === 'abc123'
            && $placeholder->dumpEncrypted === true
            && $placeholder->dumpDialect === 'mysql'))
        ->andReturn(['{}', 'hash-value', 'sig-value']);

    $record = (new AuditLogBuilder($signer))->build(
        config: makeBuilderCloningConfig(),
        result: makeBuilderRunResult(),
        targetConnection: 'staging',
        startedAt: new DateTimeImmutable('2026-04-01T14:32:00', new DateTimeZone('UTC')),
        finishedAt: new DateTimeImmutable('2026-04-01T14:34:14', new DateTimeZone('UTC')),
        yamlFileName: 'test.cloning.yaml',
        dumpDialect: 'mysql',
        dumpResult: new DumpResultData('/tmp/dump.zip', 'dump.zip', 'abc123', true, 2048),
    );

    expect($record->contentHash)->toBe('hash-value')
        ->and($record->hmacSignature)->toBe('sig-value')
        ->and($record->dumpDialect)->toBe('mysql')
        ->and($record->dumpFile)->toBe('/tmp/dump.zip')
        ->and($record->dumpSha256)->toBe('abc123')
        ->and($record->dumpEncrypted)->toBeTrue();
});

it('copies exact connection details including the schema but never the password', function (): void {
    $source = new ConnectionData(
        name: 'warehouse',
        type: DatabaseConnectionType::PostgreSQL,
        host: 'pg.prod.io',
        port: 5432,
        database: 'dwh',
        schema: 'reporting',
        username: 'reader',
        password: 'secret',
        isProduction: true,
    );

    $record = (new AuditLogBuilder(new AuditLogSigner))->build(
        config: makeBuilderCloningConfig(),
        result: makeBuilderRunResult(),
        targetConnection: 'staging',
        startedAt: new DateTimeImmutable('2026-04-01T14:32:00', new DateTimeZone('UTC')),
        finishedAt: new DateTimeImmutable('2026-04-01T14:34:14', new DateTimeZone('UTC')),
        yamlFileName: 'test.cloning.yaml',
        sourceConnectionData: $source,
        targetConnectionData: makeBuilderTargetConnection(),
    );

    expect($record->sourceConnectionDetails)->toBe([
        'name' => 'warehouse',
        'type' => 'pgsql',
        'host' => 'pg.prod.io',
        'port' => 5432,
        'database' => 'dwh',
        'schema' => 'reporting',
        'username' => 'reader',
    ]);
});
