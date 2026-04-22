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

it('formats duration as HH:MM:SS,mmm', function (): void {
    expect(AuditLogRenderer::formatDuration(0.0))->toBe('00:00:00,000');
    expect(AuditLogRenderer::formatDuration(0.5))->toBe('00:00:00,500');
    expect(AuditLogRenderer::formatDuration(445.757))->toBe('00:07:25,757');
    expect(AuditLogRenderer::formatDuration(3661.042))->toBe('01:01:01,042');
    expect(AuditLogRenderer::formatDuration(59.999))->toBe('00:00:59,999');
});

it('renders duration in human-friendly format in HTML', function (): void {
    $renderer = new AuditLogRenderer;
    $record = makeFullAuditRecord();

    $signer = new AuditLogSigner;
    [$canonicalJson] = $signer->sign($record);

    $html = $renderer->render($record, $canonicalJson);

    expect($html)->toContain('00:00:00,500');
    expect($html)->not->toContain('>0.500<');
});

it('renders a PDF document', function (): void {
    $renderer = new AuditLogRenderer;
    $record = makeFullAuditRecord();

    $signer = new AuditLogSigner;
    [$canonicalJson] = $signer->sign($record);

    $pdf = $renderer->renderPdf($record, $canonicalJson);

    expect($pdf)->toStartWith('%PDF-')
        ->and(strlen($pdf))->toBeGreaterThan(1000);
});
