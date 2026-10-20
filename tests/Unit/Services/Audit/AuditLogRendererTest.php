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

it('renders a Notes section only when tables carry notes', function (): void {
    $renderer = new AuditLogRenderer;
    $plain = makeFullAuditRecord();

    expect($renderer->render($plain, '{}'))->not->toContain('<h2>Notes');

    $table = $plain->tables[0];
    $withNotes = new AuditRecordData(
        clonioVersion: $plain->clonioVersion,
        sourceConnection: $plain->sourceConnection,
        targetConnection: $plain->targetConnection,
        yamlFileName: $plain->yamlFileName,
        startedAt: $plain->startedAt,
        finishedAt: $plain->finishedAt,
        success: $plain->success,
        options: $plain->options,
        tables: [new AuditTableRecordData(
            tableName: $table->tableName,
            existed: true,
            skippedByFlag: false,
            rowStrategy: 'full',
            rowLimit: null,
            rowsTransferred: 100,
            rowsSkipped: 0,
            durationSeconds: 0.5,
            transformedColumns: [],
            keptColumnCount: 3,
            notes: ['Generated columns not copied and left empty: day. The generation expression was not replicated to the target.'],
        )],
        totalRowsTransferred: $plain->totalRowsTransferred,
        totalRowsSkipped: $plain->totalRowsSkipped,
        channels: [],
        contentHash: $plain->contentHash,
        hmacSignature: $plain->hmacSignature,
        sourceConnectionDetails: $plain->sourceConnectionDetails,
        targetConnectionDetails: $plain->targetConnectionDetails,
    );

    $html = $renderer->render($withNotes, '{}');

    expect($html)->toContain('<h2>Notes <span class="h2-count">· 1</span></h2>')
        ->and($html)->toContain('The generation expression was not replicated to the target.');
});

/**
 * @param  array<string, mixed>  $overrides  named AuditRecordData constructor arguments
 */
function g7_rendererRecord(array $overrides = []): AuditRecordData
{
    $table = static fn (string $name, bool $skipped, float $duration, array $columns = [], array $notes = []): AuditTableRecordData => new AuditTableRecordData(
        tableName: $name,
        existed: true,
        skippedByFlag: $skipped,
        rowStrategy: 'li<m>it',
        rowLimit: 5,
        rowsTransferred: 1234567,
        rowsSkipped: 4321,
        durationSeconds: $duration,
        transformedColumns: $columns,
        keptColumnCount: 0,
        notes: $notes,
    );

    return new AuditRecordData(...array_merge([
        'clonioVersion' => '1.<b>',
        'sourceConnection' => 'prod<&>',
        'targetConnection' => 'stage"x',
        'yamlFileName' => 'a&b.yaml',
        'startedAt' => new DateTimeImmutable('2026-04-01T14:32:00.250000', new DateTimeZone('UTC')),
        'finishedAt' => new DateTimeImmutable('2026-04-01T14:34:14.900000', new DateTimeZone('UTC')),
        'success' => true,
        'options' => new CloningOptionsData(1000, false, false, false, true, 'en_US'),
        'tables' => [
            $table('t<0>', false, 3599.0, [
                new AuditColumnRecordData('c<0>', 's<0>'),
                new AuditColumnRecordData('c1', 's1'),
                new AuditColumnRecordData('c2', 's2'),
                new AuditColumnRecordData('c3', 's3'),
            ], ['n<0>', 'n1']),
            $table('t1', true, 3600.0, [new AuditColumnRecordData('d0', 'x0')], ['n2', 'n3', 'n4']),
            $table('t2', false, 3659.25),
            $table('t3', false, 3660.0),
        ],
        'totalRowsTransferred' => 1234567,
        'totalRowsSkipped' => 9876,
        'channels' => [],
        'contentHash' => 'h<1>',
        'hmacSignature' => 's<2>',
        'sourceConnectionDetails' => ['name' => 'n<a>me', 'type' => 't<y>pe', 'host' => 'h<o>st', 'port' => 3306, 'database' => 'd<b>', 'schema' => 's<ch>', 'username' => 'u<s>er'],
        'targetConnectionDetails' => ['name' => 'tgt', 'type' => '', 'host' => '', 'port' => 5432, 'database' => '', 'schema' => '', 'username' => ''],
    ], $overrides));
}

/** The rendered <body>, with the embedded logo replaced by a placeholder. */
function g7_renderedBody(AuditRecordData $record, string $canonicalJson = '{"a":"<b>&\'"}'): string
{
    $html = (new AuditLogRenderer)->render($record, $canonicalJson);
    $logo = 'data:image/png;base64,'.base64_encode((string) file_get_contents(resource_path('assets/clonio-ghost.png')));

    expect($html)->toContain('<img src="'.$logo.'" alt="Clonio">');

    return substr(str_replace($logo, 'LOGO', $html), (int) strpos($html, '<body>'));
}

it('renders the exact escaped title and body for a rich successful run', function (): void {
    $record = g7_rendererRecord();
    $html = (new AuditLogRenderer)->render($record, '{}');

    expect($html)->toStartWith("<!DOCTYPE html>\n<html lang=\"en\">\n<head>\n<meta charset=\"UTF-8\">")
        ->and($html)->toContain('<title>Clonio Audit Log · prod&lt;&amp;&gt; → stage&quot;x</title>');

    $expected = <<<'HTML'
<body>

<table class="hero">
  <tr>
    <td class="left">
      <div class="eyebrow">Clonio Audit Log</div>
      <h1>Cloning Protocol</h1>
      <p class="lede">prod&lt;&amp;&gt; &nbsp;→&nbsp; stage&quot;x</p>
    </td>
    <td class="right"><img src="LOGO" alt="Clonio"></td>
  </tr>
</table>

<div class="status-band">
  <table><tr><td>
    <div class="status-text"><span class="badge success">Success</span>&nbsp; Run completed successfully</div>
    <div class="status-meta">2026-04-01 14:32:00 UTC &nbsp;→&nbsp; 2026-04-01 14:34:14 UTC &nbsp;·&nbsp; 00:02:14,650 duration</div>
  </td></tr></table>
</div>

<table class="kpi">
  <tr>
    <td>
      <div class="kpi-label">Rows transferred</div>
      <div class="kpi-value">1 234 567</div>
      <div class="kpi-sub">across 4 tables</div>
    </td>
    <td>
      <div class="kpi-label">Rows skipped</div>
      <div class="kpi-value">9 876</div>
      <div class="kpi-sub">filtered or errored</div>
    </td>
    <td>
      <div class="kpi-label">PII columns</div>
      <div class="kpi-value">5</div>
      <div class="kpi-sub">anonymised</div>
    </td>
    <td>
      <div class="kpi-label">Duration</div>
      <div class="kpi-value">00:02:14,650</div>
      <div class="kpi-sub">wall-clock</div>
    </td>
  </tr>
</table>

<h2>Run details</h2>
<table class="meta-table">
  <tr><td class="label">Clonio version</td><td class="value">1.&lt;b&gt;</td></tr>
  <tr><td class="label">Configuration</td><td class="value">a&amp;b.yaml</td></tr>
  <tr><td class="label">Started</td><td class="value">2026-04-01 14:32:00 UTC</td></tr>
  <tr><td class="label">Finished</td><td class="value">2026-04-01 14:34:14 UTC</td></tr>
</table>

<h2>Table results <span class="h2-count">· 4</span></h2>
<table class="data">
  <thead><tr><th>Table</th><th>State</th><th>Strategy</th><th class="num">Transferred</th><th class="num">Skipped</th><th class="num">Duration</th></tr></thead>
  <tbody><tr><td><strong>t&lt;0&gt;</strong></td><td><span class="dot dot-green"></span> Done</td><td><span class="chip">li&lt;m&gt;it</span></td><td class="num">1 234 567</td><td class="num">4 321</td><td class="num mono">00:59:59,000</td></tr><tr class="alt"><td><strong>t1</strong></td><td><span class="dot dot-amber"></span> Skipped</td><td><span class="chip">li&lt;m&gt;it</span></td><td class="num">1 234 567</td><td class="num">4 321</td><td class="num mono">01:00:00,000</td></tr><tr><td><strong>t2</strong></td><td><span class="dot dot-green"></span> Done</td><td><span class="chip">li&lt;m&gt;it</span></td><td class="num">1 234 567</td><td class="num">4 321</td><td class="num mono">01:00:59,250</td></tr><tr class="alt"><td><strong>t3</strong></td><td><span class="dot dot-green"></span> Done</td><td><span class="chip">li&lt;m&gt;it</span></td><td class="num">1 234 567</td><td class="num">4 321</td><td class="num mono">01:01:00,000</td></tr></tbody>
</table>

<h2>Notes <span class="h2-count">· 5</span></h2>
<table class="data">
  <thead><tr><th>Table</th><th>Note</th></tr></thead>
  <tbody><tr><td><strong>t&lt;0&gt;</strong></td><td>n&lt;0&gt;</td></tr><tr class="alt"><td><strong>t&lt;0&gt;</strong></td><td>n1</td></tr><tr><td><strong>t1</strong></td><td>n2</td></tr><tr class="alt"><td><strong>t1</strong></td><td>n3</td></tr><tr><td><strong>t1</strong></td><td>n4</td></tr></tbody>
</table>

<h2 class="page-break">PII transformations <span class="h2-count">· 5</span></h2>
<table class="data">
  <thead><tr><th>Table</th><th>Column</th><th>Strategy</th></tr></thead>
  <tbody><tr><td><strong>t&lt;0&gt;</strong></td><td>c&lt;0&gt;</td><td><span class="chip chip-indigo">s&lt;0&gt;</span></td></tr><tr class="alt"><td><strong>t&lt;0&gt;</strong></td><td>c1</td><td><span class="chip chip-indigo">s1</span></td></tr><tr><td><strong>t&lt;0&gt;</strong></td><td>c2</td><td><span class="chip chip-indigo">s2</span></td></tr><tr class="alt"><td><strong>t&lt;0&gt;</strong></td><td>c3</td><td><span class="chip chip-indigo">s3</span></td></tr><tr><td><strong>t1</strong></td><td>d0</td><td><span class="chip chip-indigo">x0</span></td></tr></tbody>
</table>

<h2 class="page-break">Summary &amp; Integrity</h2>

<table class="conn-grid">
  <tr>
    <td>
      <div class="conn-eyebrow">Source</div>
      <div class="conn-name">n&lt;a&gt;me</div>
      <table class="meta-table">
        <tr><td class="label">Type</td><td class="value">t&lt;y&gt;pe</td></tr>
        <tr><td class="label">Host</td><td class="value">h&lt;o&gt;st:3306</td></tr>
        <tr><td class="label">Database</td><td class="value">d&lt;b&gt;</td></tr><tr><td class="label">Schema</td><td class="value">s&lt;ch&gt;</td></tr>
        <tr><td class="label">Username</td><td class="value">u&lt;s&gt;er</td></tr>
      </table>
    </td>
    <td>
      <div class="conn-eyebrow">Target</div>
      <div class="conn-name">tgt</div>
      <table class="meta-table">
        <tr><td class="label">Type</td><td class="value">—</td></tr>
        <tr><td class="label">Host</td><td class="value">—</td></tr>
        <tr><td class="label">Database</td><td class="value">—</td></tr>
        <tr><td class="label">Username</td><td class="value">—</td></tr>
      </table>
    </td>
  </tr>
</table>

<table class="kpi kpi-3">
  <tr>
    <td>
      <div class="kpi-label">Rows transferred</div>
      <div class="kpi-value">1 234 567</div>
      <div class="kpi-sub">across 4 tables</div>
    </td>
    <td>
      <div class="kpi-label">PII columns</div>
      <div class="kpi-value">5</div>
      <div class="kpi-sub">anonymised</div>
    </td>
    <td>
      <div class="kpi-label">Duration</div>
      <div class="kpi-value">00:02:14,650</div>
      <div class="kpi-sub">wall-clock</div>
    </td>
  </tr>
</table>

<div class="integrity-card">
  <div class="label">SHA-256 content hash</div>
  <div class="hash">h&lt;1&gt;</div>
  <div class="label">HMAC signature</div>
  <div class="hash">s&lt;2&gt;</div>
</div>

<script type="application/json" id="audit-data">{"a":"&lt;b&gt;&amp;'"}</script>
</body>
</html>
HTML;

    expect(g7_renderedBody($record))->toBe($expected);
});

it('renders a failed run with empty table, note and PII sections', function (): void {
    $body = g7_renderedBody(g7_rendererRecord([
        'success' => false,
        'tables' => [],
        'sourceConnectionDetails' => ['name' => 'src', 'type' => 'pgsql', 'host' => 'db', 'port' => null, 'database' => null, 'schema' => null, 'username' => null],
    ]));

    expect($body)
        ->toContain("<div class=\"status-band failure\">\n  <table><tr><td>\n    <div class=\"status-text\"><span class=\"badge failure\">Failed</span>&nbsp; Run failed</div>")
        ->toContain("<h2>Table results <span class=\"h2-count\">· 0</span></h2>\n<table class=\"data\">\n  <thead><tr><th>Table</th><th>State</th><th>Strategy</th><th class=\"num\">Transferred</th><th class=\"num\">Skipped</th><th class=\"num\">Duration</th></tr></thead>\n  <tbody></tbody>\n</table>\n\n\n\n<h2 class=\"page-break\">PII transformations <span class=\"h2-count\">· 0</span></h2>\n<table class=\"data\">\n  <thead><tr><th>Table</th><th>Column</th><th>Strategy</th></tr></thead>\n  <tbody></tbody>")
        ->toContain('<tr><td class="label">Host</td><td class="value">db</td></tr>')
        ->toContain("<tr><td class=\"label\">Database</td><td class=\"value\">—</td></tr>\n        <tr><td class=\"label\">Username</td><td class=\"value\">—</td></tr>")
        ->not->toContain('<h2>Notes');
});

it('includes sub-second fractions of the run in the overall duration', function (string $started, string $finished, string $expected): void {
    $body = g7_renderedBody(g7_rendererRecord([
        'startedAt' => new DateTimeImmutable($started, new DateTimeZone('UTC')),
        'finishedAt' => new DateTimeImmutable($finished, new DateTimeZone('UTC')),
    ]));

    expect($body)->toContain(sprintf('&nbsp;·&nbsp; %s duration</div>', $expected))
        ->and($body)->toContain(sprintf('<div class="kpi-value">%s</div>', $expected));
})->with([
    'whole seconds only' => ['2026-04-01T10:00:00.000000', '2026-04-01T10:00:07.000000', '00:00:07,000'],
    'fraction carry' => ['2026-04-01T10:00:00.900000', '2026-04-01T10:00:01.100000', '00:00:00,200'],
    'just under one second' => ['2026-04-01T10:00:00.000000', '2026-04-01T10:00:00.999999', '00:00:00,1000'],
    'sub-millisecond rounding' => ['2026-04-01T10:00:00.000000', '2026-04-01T10:00:00.000500', '00:00:00,001'],
    'just under the half millisecond' => ['2026-04-01T10:00:00.000000', '2026-04-01T10:00:00.000499', '00:00:00,000'],
    'half a second' => ['2026-04-01T10:00:00.000000', '2026-04-01T10:00:00.500000', '00:00:00,500'],
    'finished before started is clamped to zero' => ['2026-04-01T10:00:00.500000', '2026-04-01T10:00:00.000000', '00:00:00,000'],
]);

it('renders an empty logo source when the logo asset is missing', function (): void {
    $base = sys_get_temp_dir().'/g7-renderer-'.bin2hex(random_bytes(6));
    app()->setBasePath($base);

    $html = (new AuditLogRenderer)->render(g7_rendererRecord(), '{}');

    expect($html)->toContain('<td class="right"><img src="" alt="Clonio"></td>');
});

it('writes the rendered HTML into a UTF-8 A4 PDF using the DejaVu Sans default font', function (): void {
    $pdf = (new AuditLogRenderer)->renderPdf(g7_rendererRecord(), '{}');

    expect($pdf)->toStartWith('%PDF-')
        ->toContain('/Lang (utf-8)')
        ->toContain('/MediaBox [0 0 595.280 841.890]')
        ->toContain('/BaseFont /MPDFAA+DejaVuSans-Bold')
        ->toMatch("/\\/Font <<\n\\/F1 \\d+ 0 R\n\\/F2 \\d+ 0 R\n\\/F3 \\d+ 0 R\n>>/")
        ->not->toContain('/F4 ');
});
