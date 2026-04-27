<?php

declare(strict_types=1);

namespace App\Services\Audit;

use App\Data\Audit\AuditRecordData;
use App\Data\Audit\AuditTableRecordData;
use Mpdf\Mpdf;

class AuditLogRenderer
{
    /**
     * Render the audit record to an HTML string.
     * The HTML must embed the canonical JSON in a script tag with id="audit-data"
     * so verify-audit can extract it.
     */
    public function render(AuditRecordData $record, string $canonicalJson): string
    {
        $startedAt = $record->startedAt->format('Y-m-d H:i:s T');
        $finishedAt = $record->finishedAt->format('Y-m-d H:i:s T');
        $durationSeconds = (float) ($record->finishedAt->getTimestamp() - $record->startedAt->getTimestamp())
            + (((int) $record->finishedAt->format('u')) - ((int) $record->startedAt->format('u'))) / 1_000_000;
        $duration = self::formatDuration(max(0.0, $durationSeconds));

        $tableCount = count($record->tables);
        $piiColumnCount = array_sum(array_map(static fn (AuditTableRecordData $t): int => count($t->transformedColumns), $record->tables));

        $statusBadge = $record->success
            ? '<span class="badge success">Success</span>'
            : '<span class="badge failure">Failed</span>';
        $statusBandClass = $record->success ? 'status-band' : 'status-band failure';
        $statusText = $record->success ? 'Run completed successfully' : 'Run failed';

        $tableRows = '';

        foreach ($record->tables as $i => $table) {
            $tableRows .= $this->renderTableRow($table, $i % 2 === 1);
        }

        $piiRows = '';

        foreach ($record->tables as $table) {
            foreach ($table->transformedColumns as $i => $col) {
                $alt = $i % 2 === 1 ? ' class="alt"' : '';
                $piiRows .= sprintf(
                    '<tr%s><td><strong>%s</strong></td><td>%s</td><td><span class="chip chip-indigo">%s</span></td></tr>',
                    $alt,
                    htmlspecialchars($table->tableName),
                    htmlspecialchars($col->columnName),
                    htmlspecialchars($col->strategy),
                );
            }
        }

        $logoSrc = $this->logoDataUri();
        $sourceConnection = htmlspecialchars($record->sourceConnection);
        $targetConnection = htmlspecialchars($record->targetConnection);
        $clonioVersion = htmlspecialchars($record->clonioVersion);
        $yamlFileName = htmlspecialchars($record->yamlFileName);
        $contentHash = htmlspecialchars($record->contentHash);
        $hmacSignature = htmlspecialchars($record->hmacSignature);
        $totalRowsTransferred = number_format($record->totalRowsTransferred, 0, '.', ' ');
        $totalRowsSkipped = number_format($record->totalRowsSkipped, 0, '.', ' ');

        $escapedCanonicalJson = htmlspecialchars($canonicalJson, ENT_NOQUOTES);

        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Clonio Audit Log · {$sourceConnection} → {$targetConnection}</title>
<style>
  @page { margin: 18mm 14mm 18mm 14mm; }
  body { font-family: "DejaVu Sans", "Helvetica", sans-serif; color: #0f172a; font-size: 10pt; line-height: 1.5; }

  .hero { width: 100%; margin-bottom: 8mm; }
  .hero td { vertical-align: middle; }
  .hero .left { padding-right: 4mm; }
  .hero .right { text-align: right; width: 28mm; }
  .hero .right img { width: 22mm; height: auto; }
  .eyebrow { font-size: 8pt; color: #6366f1; letter-spacing: 0.16em; text-transform: uppercase; font-weight: bold; }
  h1 { font-size: 24pt; margin: 1mm 0 1mm 0; color: #0f172a; font-weight: bold; letter-spacing: -0.02em; }
  .lede { color: #475569; font-size: 10.5pt; margin: 0; }

  .status-band { background: #ecfdf5; border-left: 4px solid #10b981; padding: 4mm 5mm; margin-bottom: 6mm; }
  .status-band.failure { background: #fef2f2; border-left-color: #ef4444; }
  .status-band table { width: 100%; }
  .status-band td { vertical-align: middle; }
  .status-band .status-text { font-size: 13pt; font-weight: bold; color: #065f46; }
  .status-band.failure .status-text { color: #991b1b; }
  .status-band .status-meta { font-size: 9pt; color: #475569; margin-top: 1mm; }

  .kpi { width: 100%; border-collapse: separate; border-spacing: 3mm 0; margin-bottom: 6mm; margin-left: -3mm; margin-right: -3mm; }
  .kpi td { width: 25%; background: #f8fafc; border: 1px solid #e2e8f0; padding: 4mm; vertical-align: top; border-radius: 2mm; }
  .kpi .kpi-label { font-size: 8pt; color: #64748b; text-transform: uppercase; letter-spacing: 0.1em; font-weight: bold; }
  .kpi .kpi-value { font-size: 18pt; color: #0f172a; font-weight: bold; margin-top: 2mm; letter-spacing: -0.02em; }
  .kpi .kpi-sub { font-size: 8pt; color: #94a3b8; margin-top: 1mm; }

  h2 { font-size: 13pt; color: #0f172a; margin: 7mm 0 3mm 0; font-weight: bold; }
  h2.page-break { page-break-before: always; break-before: page; }
  h2 .h2-count { color: #94a3b8; font-weight: normal; font-size: 11pt; margin-left: 2mm; }

  .badge { display: inline-block; padding: 1mm 3mm; border-radius: 10mm; font-size: 9pt; font-weight: bold; }
  .badge.success { background: #d1fae5; color: #065f46; }
  .badge.failure { background: #fee2e2; color: #991b1b; }

  table.data { width: 100%; border-collapse: collapse; font-size: 9.5pt; border: 1px solid #e2e8f0; }
  table.data thead th { background: #f1f5f9; text-align: left; font-size: 8pt; text-transform: uppercase; letter-spacing: 0.08em;
                        color: #475569; padding: 3mm 3mm; border-bottom: 1px solid #e2e8f0; font-weight: bold; }
  table.data tbody td { padding: 3mm 3mm; border-bottom: 1px solid #f1f5f9; }
  table.data tr.alt td { background: #fafbfc; }
  table.data .num { text-align: right; font-variant-numeric: tabular-nums; }
  table.data .mono { font-family: "DejaVu Sans Mono", monospace; font-size: 9pt; color: #475569; }

  .chip { display: inline-block; padding: 0.5mm 2.5mm; background: #f1f5f9; color: #475569; border-radius: 2mm; font-size: 8.5pt; font-weight: bold; }
  .chip-indigo { background: #eef2ff; color: #4338ca; }
  .dot { display: inline-block; width: 2.5mm; height: 2.5mm; border-radius: 5mm; vertical-align: middle; margin-right: 1.5mm; }
  .dot-green { background: #10b981; }
  .dot-amber { background: #f59e0b; }

  .meta-table { width: 100%; border-collapse: collapse; margin-top: 2mm; }
  .meta-table td { padding: 1.5mm 0; vertical-align: top; }
  .meta-table .label { color: #64748b; font-size: 9pt; width: 38%; }
  .meta-table .value { color: #0f172a; font-size: 10pt; }

  .integrity-card { background: #0f172a; color: #cbd5e1; padding: 5mm; border-radius: 2mm; margin-top: 3mm; font-size: 8.5pt; }
  .integrity-card .label { color: #6366f1; font-size: 8pt; text-transform: uppercase; letter-spacing: 0.12em; font-weight: bold; }
  .integrity-card .hash { font-family: "DejaVu Sans Mono", monospace; word-break: break-all; margin: 1mm 0 3mm 0; color: #f1f5f9; }
  .integrity-card .hash:last-child { margin-bottom: 0; }
</style>
</head>
<body>

<table class="hero">
  <tr>
    <td class="left">
      <div class="eyebrow">Clonio Audit Log</div>
      <h1>Cloning Protocol</h1>
      <p class="lede">{$sourceConnection} &nbsp;→&nbsp; {$targetConnection}</p>
    </td>
    <td class="right"><img src="{$logoSrc}" alt="Clonio"></td>
  </tr>
</table>

<div class="{$statusBandClass}">
  <table><tr><td>
    <div class="status-text">{$statusBadge}&nbsp; {$statusText}</div>
    <div class="status-meta">{$startedAt} &nbsp;→&nbsp; {$finishedAt} &nbsp;·&nbsp; {$duration} duration</div>
  </td></tr></table>
</div>

<table class="kpi">
  <tr>
    <td>
      <div class="kpi-label">Rows transferred</div>
      <div class="kpi-value">{$totalRowsTransferred}</div>
      <div class="kpi-sub">across {$tableCount} tables</div>
    </td>
    <td>
      <div class="kpi-label">Rows skipped</div>
      <div class="kpi-value">{$totalRowsSkipped}</div>
      <div class="kpi-sub">filtered or errored</div>
    </td>
    <td>
      <div class="kpi-label">PII columns</div>
      <div class="kpi-value">{$piiColumnCount}</div>
      <div class="kpi-sub">anonymised</div>
    </td>
    <td>
      <div class="kpi-label">Duration</div>
      <div class="kpi-value">{$duration}</div>
      <div class="kpi-sub">wall-clock</div>
    </td>
  </tr>
</table>

<h2>Run details</h2>
<table class="meta-table">
  <tr><td class="label">Clonio version</td><td class="value">{$clonioVersion}</td></tr>
  <tr><td class="label">Configuration</td><td class="value">{$yamlFileName}</td></tr>
  <tr><td class="label">Started</td><td class="value">{$startedAt}</td></tr>
  <tr><td class="label">Finished</td><td class="value">{$finishedAt}</td></tr>
</table>

<h2>Table results <span class="h2-count">· {$tableCount}</span></h2>
<table class="data">
  <thead><tr><th>Table</th><th>State</th><th>Strategy</th><th class="num">Transferred</th><th class="num">Skipped</th><th class="num">Duration</th></tr></thead>
  <tbody>{$tableRows}</tbody>
</table>

<h2>PII transformations <span class="h2-count">· {$piiColumnCount}</span></h2>
<table class="data">
  <thead><tr><th>Table</th><th>Column</th><th>Strategy</th></tr></thead>
  <tbody>{$piiRows}</tbody>
</table>

<h2 class="page-break">Integrity</h2>
<div class="integrity-card">
  <div class="label">SHA-256 content hash</div>
  <div class="hash">{$contentHash}</div>
  <div class="label">HMAC signature</div>
  <div class="hash">{$hmacSignature}</div>
</div>

<script type="application/json" id="audit-data">{$escapedCanonicalJson}</script>
</body>
</html>
HTML;
    }

    /**
     * Format seconds into HH:MM:SS,mmm (e.g. 00:07:25,757).
     */
    public static function formatDuration(float $seconds): string
    {
        $totalSeconds = (int) floor($seconds);
        $milliseconds = (int) round(($seconds - $totalSeconds) * 1000);

        $hours = intdiv($totalSeconds, 3600);
        $minutes = intdiv($totalSeconds % 3600, 60);
        $secs = $totalSeconds % 60;

        return sprintf('%02d:%02d:%02d,%03d', $hours, $minutes, $secs, $milliseconds);
    }

    /**
     * Render the audit record to a PDF string.
     */
    public function renderPdf(AuditRecordData $record, string $canonicalJson): string
    {
        $html = $this->render($record, $canonicalJson);

        $mpdf = new Mpdf([
            'mode' => 'utf-8',
            'format' => 'A4',
            'margin_top' => 18,
            'margin_bottom' => 18,
            'margin_left' => 14,
            'margin_right' => 14,
            'default_font' => 'DejaVuSans',
            // Direct mpdf's spill files to the OS temp dir. Without this, mpdf
            // defaults to a path inside its own vendor directory, which is read-only
            // when clonio runs from the PHAR/micro-SAPI distribution.
            'tempDir' => sys_get_temp_dir(),
        ]);

        $mpdf->WriteHTML($html);

        // @phpstan-ignore-next-line
        return $mpdf->Output('', 'S');
    }

    private function renderTableRow(AuditTableRecordData $table, bool $alt): string
    {
        $duration = self::formatDuration($table->durationSeconds);
        $state = $table->skippedByFlag
            ? '<span class="dot dot-amber"></span> Skipped'
            : '<span class="dot dot-green"></span> Done';
        $altClass = $alt ? ' class="alt"' : '';

        return sprintf(
            '<tr%s><td><strong>%s</strong></td><td>%s</td><td><span class="chip">%s</span></td><td class="num">%s</td><td class="num">%s</td><td class="num mono">%s</td></tr>',
            $altClass,
            htmlspecialchars($table->tableName),
            $state,
            htmlspecialchars($table->rowStrategy),
            number_format($table->rowsTransferred, 0, '.', ' '),
            number_format($table->rowsSkipped, 0, '.', ' '),
            $duration,
        );
    }

    private function logoDataUri(): string
    {
        $bytes = @file_get_contents(resource_path('assets/clonio-ghost.png'));

        if ($bytes === false) {
            return '';
        }

        return 'data:image/png;base64,'.base64_encode($bytes);
    }
}
