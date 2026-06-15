<?php

declare(strict_types=1);

use App\Data\Audit\AuditColumnRecordData;
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
        sourceConnectionDetails: ['name' => 'production-db', 'type' => 'mysql', 'host' => 'db.prod.io', 'port' => 3306, 'database' => 'mydb', 'schema' => null, 'username' => 'root'],
        targetConnectionDetails: ['name' => 'staging', 'type' => 'mysql', 'host' => 'db.staging.io', 'port' => 3306, 'database' => 'stagingdb', 'schema' => null, 'username' => 'root'],
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

it('renders the Summary & Integrity section with connection details', function (): void {
    $renderer = new AuditLogRenderer;
    $record = makeFullAuditRecord();

    $signer = new AuditLogSigner;
    [$canonicalJson] = $signer->sign($record);

    $html = $renderer->render($record, $canonicalJson);

    expect($html)->toContain('Summary &amp; Integrity');
    expect($html)->toContain('class="conn-grid"');
    expect($html)->toContain('class="conn-eyebrow">Source<');
    expect($html)->toContain('class="conn-eyebrow">Target<');
    expect($html)->toContain('db.prod.io:3306');
    expect($html)->toContain('db.staging.io:3306');
    expect($html)->toContain('mydb');
    expect($html)->toContain('stagingdb');
    expect($html)->toContain('class="kpi kpi-3"');
    expect($html)->not->toContain('secret'); // password must never leak
});

it('forces a page break before the PII transformations heading', function (): void {
    $renderer = new AuditLogRenderer;
    $record = makeFullAuditRecord();

    $signer = new AuditLogSigner;
    [$canonicalJson] = $signer->sign($record);

    $html = $renderer->render($record, $canonicalJson);

    expect($html)->toContain('<h2 class="page-break">PII transformations');
});

it('renders a failed run with transformed columns, a skipped table and sparse connection details', function (): void {
    $options = new CloningOptionsData(1000, false, false, false, true, 'en_US');

    $tables = [
        new AuditTableRecordData(
            tableName: 'users',
            existed: true,
            skippedByFlag: false,
            rowStrategy: 'full',
            rowLimit: null,
            rowsTransferred: 10,
            rowsSkipped: 2,
            durationSeconds: 0.4,
            transformedColumns: [
                new AuditColumnRecordData(columnName: 'email', strategy: 'fake'),
            ],
            keptColumnCount: 1,
        ),
        new AuditTableRecordData(
            tableName: 'sessions',
            existed: true,
            skippedByFlag: true,
            rowStrategy: 'skip',
            rowLimit: null,
            rowsTransferred: 0,
            rowsSkipped: 0,
            durationSeconds: 0.0,
            transformedColumns: [],
            keptColumnCount: 0,
        ),
    ];

    $record = new AuditRecordData(
        clonioVersion: '1.0.0',
        sourceConnection: 'src',
        targetConnection: 'tgt',
        yamlFileName: 'x.cloning.yaml',
        startedAt: new DateTimeImmutable('2026-04-01T14:32:00', new DateTimeZone('UTC')),
        finishedAt: new DateTimeImmutable('2026-04-01T14:34:14', new DateTimeZone('UTC')),
        success: false,
        options: $options,
        tables: $tables,
        totalRowsTransferred: 10,
        totalRowsSkipped: 2,
        channels: [],
        contentHash: 'h',
        hmacSignature: 's',
        // Sparse source (— fallbacks) + target with a schema row
        sourceConnectionDetails: ['name' => 'src', 'type' => '', 'host' => null, 'port' => null, 'database' => null, 'schema' => null, 'username' => null],
        targetConnectionDetails: ['name' => 'tgt', 'type' => 'pgsql', 'host' => 'h', 'port' => 5432, 'database' => 'd', 'schema' => 'reporting', 'username' => 'u'],
    );

    $signer = new AuditLogSigner;
    [$canonicalJson] = $signer->sign($record);
    $html = (new AuditLogRenderer)->render($record, $canonicalJson);

    expect($html)
        ->toContain('Run failed')
        ->toContain('Failed')
        ->toContain('email')
        ->toContain('Skipped')
        ->toContain('Schema')
        ->toContain('reporting');
});
