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
use App\Services\Audit\AuditLogBuilder;
use App\Services\Audit\AuditLogSigner;
use DateTimeImmutable;
use DateTimeZone;

function makeBuilderCloningConfig(): CloningConfigData
{
    return new CloningConfigData(
        version: '1',
        connectionName: 'production-db',
        options: new CloningOptionsData(
            chunkSize: 1000,
            enforceColumnTypes: false,
            dropUnknownTables: false,
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
    );

    expect($record)->toBeInstanceOf(AuditRecordData::class);
    expect($record->sourceConnection)->toBe('production-db');
    expect($record->targetConnection)->toBe('staging');
    expect($record->success)->toBeTrue();
    expect($record->totalRowsTransferred)->toBe(100);
    expect($record->tables)->toHaveCount(1);
    expect($record->contentHash)->not->toBeEmpty();
    expect($record->hmacSignature)->not->toBeEmpty();
});
