<?php

declare(strict_types=1);

use App\Data\Audit\AuditRecordData;
use App\Data\Audit\AuditTableRecordData;
use App\Data\Cloning\CloningOptionsData;
use App\Services\Audit\AuditLogRenderer;
use App\Services\Audit\AuditLogSigner;

function makeFullAuditRecord(): AuditRecordData
{
    $options = new CloningOptionsData(
        chunkSize: 1000,
        enforceColumnTypes: false,
        dropUnknownTables: false,
        dropExtraColumns: false,
        disableForeignKeyChecks: true,
        fakerLocale: 'en_US',
    );

    $tables = [
        new AuditTableRecordData(
            tableName: 'users',
            existed: true,
            skippedByFlag: false,
            rowStrategy: 'full',
            rowLimit: null,
            rowsTransferred: 100,
            rowsSkipped: 0,
            durationSeconds: 0.5,
            transformedColumns: [],
            keptColumnCount: 3,
        ),
    ];

    return new AuditRecordData(
        clonioVersion: '1.0.0',
        sourceConnection: 'production-db',
        targetConnection: 'staging',
        yamlFileName: 'test.cloning.yaml',
        startedAt: new DateTimeImmutable('2026-04-01T14:32:00', new DateTimeZone('UTC')),
        finishedAt: new DateTimeImmutable('2026-04-01T14:34:14', new DateTimeZone('UTC')),
        success: true,
        options: $options,
        tables: $tables,
        totalRowsTransferred: 100,
        totalRowsSkipped: 0,
        channels: [],
        contentHash: 'abc123',
        hmacSignature: 'sig456',
    );
}

it('renders an HTML document', function (): void {
    $renderer = new AuditLogRenderer;
    $record = makeFullAuditRecord();

    $signer = new AuditLogSigner;
    [$canonicalJson] = $signer->sign($record);

    $html = $renderer->render($record, $canonicalJson);

    expect($html)->toContain('<!DOCTYPE html>');
    expect($html)->toContain('Clonio Audit Log');
    expect($html)->toContain('production-db');
    expect($html)->toContain('staging');
});

it('embeds canonical JSON in audit-data script tag', function (): void {
    $renderer = new AuditLogRenderer;
    $record = makeFullAuditRecord();

    $signer = new AuditLogSigner;
    [$canonicalJson] = $signer->sign($record);

    $html = $renderer->render($record, $canonicalJson);

    expect($html)->toContain('id="audit-data"');
    expect($html)->toContain('application/json');
});
