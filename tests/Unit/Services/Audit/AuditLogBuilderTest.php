<?php

declare(strict_types=1);

use App\Data\Audit\AuditRecordData;
use App\Data\Cloning\CloningConfigData;
use App\Data\Cloning\CloningOptionsData;
use App\Data\Cloning\RunResultData;
use App\Data\Cloning\TableCloningConfigData;
use App\Data\Cloning\TableRowConfigData;
use App\Data\Cloning\TableRunResultData;
use App\Data\Cloning\TableRunStatus;
use App\Data\ConnectionData;
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
