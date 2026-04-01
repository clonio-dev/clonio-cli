<?php

declare(strict_types=1);

use App\Enums\ExitCode;

function makeAuditHtml(string $canonicalJson): string
{
    $escaped = htmlspecialchars($canonicalJson, ENT_NOQUOTES);

    return <<<HTML
<!DOCTYPE html>
<html>
<head><title>Audit</title></head>
<body>
<script type="application/json" id="audit-data">{$escaped}</script>
</body>
</html>
HTML;
}

it('returns IoError(5) when audit file does not exist', function (): void {
    $this->artisan('cloning:verify-audit', ['file' => '/nonexistent/audit.html'])
        ->expectsOutputToContain('not found')
        ->assertExitCode(ExitCode::IoError->value);
});

it('returns IoError(5) when sig file does not exist', function (): void {
    $tmpFile = tempnam(sys_get_temp_dir(), 'audit_test_').'.html';
    file_put_contents($tmpFile, '<html><body></body></html>');

    $this->artisan('cloning:verify-audit', ['file' => $tmpFile])
        ->expectsOutputToContain('not found')
        ->assertExitCode(ExitCode::IoError->value);

    @unlink($tmpFile);
});

it('returns Success(0) when audit file and signature are valid', function (): void {
    $canonicalJson = json_encode([
        'clonio_version' => '1.0.0',
        'source_connection' => 'production-db',
        'success' => true,
        'total_rows_transferred' => 100,
        'total_rows_skipped' => 0,
    ]) ?: '{}';

    $contentHash = hash('sha256', $canonicalJson);
    $hmacSignature = hash_hmac('sha256', $contentHash, (string) config('app.key'));

    $htmlContent = makeAuditHtml($canonicalJson);

    $tmpHtml = tempnam(sys_get_temp_dir(), 'audit_test_').'.html';
    $tmpSig = str_replace('.html', '.sig', $tmpHtml);

    file_put_contents($tmpHtml, $htmlContent);
    file_put_contents($tmpSig, 'sha256:'.$hmacSignature);

    $this->artisan('cloning:verify-audit', ['file' => $tmpHtml])
        ->expectsOutputToContain('verified')
        ->assertExitCode(ExitCode::Success->value);

    @unlink($tmpHtml);
    @unlink($tmpSig);
});

it('returns GeneralError(1) when audit file has been tampered with', function (): void {
    $canonicalJson = json_encode([
        'clonio_version' => '1.0.0',
        'source_connection' => 'production-db',
        'success' => true,
    ]) ?: '{}';

    $contentHash = hash('sha256', $canonicalJson);
    $hmacSignature = hash_hmac('sha256', $contentHash, (string) config('app.key'));

    // Tamper the JSON
    $tamperedJson = json_encode([
        'clonio_version' => '1.0.0',
        'source_connection' => 'hacked',
        'success' => false,
    ]) ?: '{}';

    $htmlContent = makeAuditHtml($tamperedJson);

    $tmpHtml = tempnam(sys_get_temp_dir(), 'audit_test_').'.html';
    $tmpSig = str_replace('.html', '.sig', $tmpHtml);

    file_put_contents($tmpHtml, $htmlContent);
    file_put_contents($tmpSig, 'sha256:'.$hmacSignature);

    $this->artisan('cloning:verify-audit', ['file' => $tmpHtml])
        ->expectsOutputToContain('FAILED')
        ->assertExitCode(ExitCode::GeneralError->value);

    @unlink($tmpHtml);
    @unlink($tmpSig);
});
