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
        $success = $record->success ? 'Success' : 'Failed';
        $successClass = $record->success ? 'success' : 'failure';
        $startedAt = $record->startedAt->format('Y-m-d H:i:s T');
        $finishedAt = $record->finishedAt->format('Y-m-d H:i:s T');

        $tableRows = '';

        foreach ($record->tables as $table) {
            $tableRows .= $this->renderTableRow($table);
        }

        $transformationRows = '';

        foreach ($record->tables as $table) {
            foreach ($table->transformedColumns as $col) {
                $transformationRows .= sprintf(
                    '<tr><td>%s</td><td>%s</td><td>%s</td></tr>',
                    htmlspecialchars($table->tableName),
                    htmlspecialchars($col->columnName),
                    htmlspecialchars($col->strategy),
                );
            }
        }

        $escapedCanonicalJson = htmlspecialchars($canonicalJson, ENT_NOQUOTES);

        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Clonio Audit Log - {$record->sourceConnection} → {$record->targetConnection}</title>
<style>
  body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; margin: 0; padding: 20px; background: #f8f9fa; color: #212529; }
  .container { max-width: 1100px; margin: 0 auto; }
  h1 { border-bottom: 2px solid #dee2e6; padding-bottom: 10px; }
  h2 { color: #495057; margin-top: 30px; }
  .badge { display: inline-block; padding: 4px 10px; border-radius: 4px; font-weight: bold; }
  .badge.success { background: #d4edda; color: #155724; }
  .badge.failure { background: #f8d7da; color: #721c24; }
  table { width: 100%; border-collapse: collapse; margin-top: 10px; }
  th { background: #495057; color: white; padding: 8px 12px; text-align: left; }
  td { padding: 8px 12px; border-bottom: 1px solid #dee2e6; }
  tr:hover td { background: #f1f3f5; }
  .meta { background: white; padding: 16px; border-radius: 6px; border: 1px solid #dee2e6; margin: 10px 0; }
  .meta dt { font-weight: bold; display: inline-block; min-width: 200px; color: #495057; }
  .meta dd { display: inline; margin: 0; }
  .meta div { margin: 6px 0; }
  .integrity { font-family: monospace; font-size: 12px; word-break: break-all; background: #f8f9fa; padding: 8px; border-radius: 4px; }
</style>
</head>
<body>
<div class="container">
  <h1>Clonio Audit Log</h1>

  <h2>Header</h2>
  <div class="meta">
    <div><dt>Status:</dt> <dd><span class="badge {$successClass}">{$success}</span></dd></div>
    <div><dt>Clonio Version:</dt> <dd>{$record->clonioVersion}</dd></div>
    <div><dt>Source Connection:</dt> <dd>{$record->sourceConnection}</dd></div>
    <div><dt>Target Connection:</dt> <dd>{$record->targetConnection}</dd></div>
    <div><dt>YAML File:</dt> <dd>{$record->yamlFileName}</dd></div>
    <div><dt>Started At:</dt> <dd>{$startedAt}</dd></div>
    <div><dt>Finished At:</dt> <dd>{$finishedAt}</dd></div>
  </div>

  <h2>Transfer Summary</h2>
  <div class="meta">
    <div><dt>Total Rows Transferred:</dt> <dd>{$record->totalRowsTransferred}</dd></div>
    <div><dt>Total Rows Skipped:</dt> <dd>{$record->totalRowsSkipped}</dd></div>
  </div>

  <h2>Table Results</h2>
  <table>
    <thead>
      <tr>
        <th>Table</th>
        <th>Existed</th>
        <th>Skipped</th>
        <th>Strategy</th>
        <th>Rows Transferred</th>
        <th>Rows Skipped</th>
        <th>Duration</th>
      </tr>
    </thead>
    <tbody>
      {$tableRows}
    </tbody>
  </table>

  <h2>PII Transformations</h2>
  <table>
    <thead>
      <tr><th>Table</th><th>Column</th><th>Strategy</th></tr>
    </thead>
    <tbody>
      {$transformationRows}
    </tbody>
  </table>

  <h2>Integrity</h2>
  <div class="meta">
    <div><dt>Content Hash (SHA-256):</dt> <dd class="integrity">{$record->contentHash}</dd></div>
    <div><dt>HMAC Signature:</dt> <dd class="integrity">{$record->hmacSignature}</dd></div>
  </div>
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

    private function renderTableRow(AuditTableRecordData $table): string
    {
        $existed = $table->existed ? 'Yes' : 'No';
        $skipped = $table->skippedByFlag ? 'Yes' : 'No';
        $duration = self::formatDuration($table->durationSeconds);

        return sprintf(
            '<tr><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%d</td><td>%d</td><td>%s</td></tr>',
            htmlspecialchars($table->tableName),
            $existed,
            $skipped,
            htmlspecialchars($table->rowStrategy),
            $table->rowsTransferred,
            $table->rowsSkipped,
            $duration,
        );
    }

    /**
     * Render the audit record to a PDF string.
     *
     * @return string
     */
    public function renderPdf(AuditRecordData $record, string $canonicalJson): string
    {
        $html = $this->render($record, $canonicalJson);

        $mpdf = new Mpdf([
            'mode' => 'utf-8',
            'format' => 'A4',
            'margin_top' => 20,
            'margin_bottom' => 20,
        ]);

        $mpdf->WriteHTML($html);

        // @phpstan-ignore-next-line
        return $mpdf->Output('', 'S');
    }
}
