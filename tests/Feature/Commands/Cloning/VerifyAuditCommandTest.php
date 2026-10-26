<?php

declare(strict_types=1);

use App\Enums\ExitCode;
use Illuminate\Support\Facades\Artisan;

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

it('returns GeneralError(1) when the HTML contains no audit data block', function (): void {
    // No <script id="audit-data"> block at all -> extractAuditJson returns null.
    $htmlContent = '<!DOCTYPE html><html><body><p>nothing here</p></body></html>';

    $tmpHtml = tempnam(sys_get_temp_dir(), 'audit_test_').'.html';
    $tmpSig = str_replace('.html', '.sig', $tmpHtml);

    file_put_contents($tmpHtml, $htmlContent);
    file_put_contents($tmpSig, 'sha256:deadbeef');

    $this->artisan('cloning:verify-audit', ['file' => $tmpHtml])
        ->expectsOutputToContain('Could not find audit data')
        ->assertExitCode(ExitCode::GeneralError->value);

    @unlink($tmpHtml);
    @unlink($tmpSig);
});

it('verifies audit data when the script attributes are in alternate order (id before type)', function (): void {
    $canonicalJson = json_encode([
        'clonio_version' => '1.0.0',
        'source_connection' => 'production-db',
        'success' => true,
    ]) ?: '{}';

    $contentHash = hash('sha256', $canonicalJson);
    $hmacSignature = hash_hmac('sha256', $contentHash, (string) config('app.key'));

    $escaped = htmlspecialchars($canonicalJson, ENT_NOQUOTES);
    // Note: id attribute comes BEFORE type -> exercises the alternate regex.
    $htmlContent = <<<HTML
    <!DOCTYPE html>
    <html><body>
    <script id="audit-data" type="application/json">{$escaped}</script>
    </body></html>
    HTML;

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

it('uses an explicit --sig path when provided', function (): void {
    $canonicalJson = json_encode(['clonio_version' => '1.0.0', 'success' => true]) ?: '{}';

    $contentHash = hash('sha256', $canonicalJson);
    $hmacSignature = hash_hmac('sha256', $contentHash, (string) config('app.key'));

    $htmlContent = makeAuditHtml($canonicalJson);

    $tmpHtml = tempnam(sys_get_temp_dir(), 'audit_test_').'.html';
    $customSig = tempnam(sys_get_temp_dir(), 'audit_sig_').'.signature';

    file_put_contents($tmpHtml, $htmlContent);
    // Signature stored WITHOUT the sha256: prefix to cover that branch too.
    file_put_contents($customSig, $hmacSignature);

    $this->artisan('cloning:verify-audit', ['file' => $tmpHtml, '--sig' => $customSig])
        ->expectsOutputToContain('verified')
        ->assertExitCode(ExitCode::Success->value);

    @unlink($tmpHtml);
    @unlink($customSig);
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

/**
 * @return array{0: string, 1: string}
 */
function g5_writeAuditPair(string $html, string $sig): array
{
    $base = sys_get_temp_dir().'/g5_audit_'.bin2hex(random_bytes(6));
    file_put_contents($base.'.html', $html);
    file_put_contents($base.'.sig', $sig);

    return [$base.'.html', $base.'.sig'];
}

function g5_signAudit(string $canonicalJson, string $key): string
{
    return hash_hmac('sha256', hash('sha256', $canonicalJson), $key);
}

function g5_runVerifyAudit(array $parameters): array
{
    $exitCode = Artisan::call('cloning:verify-audit', $parameters);

    return [$exitCode, Artisan::output()];
}

it('prints the exact success report', function (): void {
    $this->withoutMockingConsoleOutput();
    $canonicalJson = json_encode(['clonio_version' => '1.0.0', 'success' => true]) ?: '{}';
    [$html, $sig] = g5_writeAuditPair(makeAuditHtml($canonicalJson), 'sha256:'.g5_signAudit($canonicalJson, (string) config('app.key')));

    [$exitCode, $output] = g5_runVerifyAudit(['file' => $html]);

    expect($exitCode)->toBe(ExitCode::Success->value)
        ->and($output)->toBe(
            "\n"
            ."  ✓  Audit log verified — signature matches\n"
            .'     File:      '.basename($html)."\n"
            .'     SHA-256:   '.hash('sha256', $canonicalJson)."\n"
            ."\n"
        );

    @unlink($html);
    @unlink($sig);
});

it('prints the exact failure report', function (): void {
    $this->withoutMockingConsoleOutput();
    $canonicalJson = json_encode(['clonio_version' => '1.0.0', 'success' => true]) ?: '{}';
    [$html, $sig] = g5_writeAuditPair(makeAuditHtml($canonicalJson), 'sha256:abc123');
    $recomputed = g5_signAudit($canonicalJson, (string) config('app.key'));

    [$exitCode, $output] = g5_runVerifyAudit(['file' => $html]);

    expect($exitCode)->toBe(ExitCode::GeneralError->value)
        ->and($output)->toBe(
            "\n"
            ."  ✗  Audit log verification FAILED — document may have been tampered with\n"
            .'     File:      '.basename($html)."\n"
            ."     Expected:  abc123  (from .sig)\n"
            .'     Got:       '.$recomputed."  (recomputed)\n"
            ."\n"
        );

    @unlink($html);
    @unlink($sig);
});

it('stops right after reporting a missing audit file', function (): void {
    $this->withoutMockingConsoleOutput();
    [$html, $sig] = g5_writeAuditPair('<html></html>', 'sha256:abc');
    @unlink($html);

    [$exitCode, $output] = g5_runVerifyAudit(['file' => $html, '--sig' => $sig]);

    expect($exitCode)->toBe(ExitCode::IoError->value)
        ->and($output)->toBe('Audit file not found: '.$html."\n");

    @unlink($sig);
});

it('stops right after reporting a missing signature file', function (): void {
    $this->withoutMockingConsoleOutput();
    [$html, $sig] = g5_writeAuditPair(makeAuditHtml('{}'), 'sha256:abc');
    @unlink($sig);

    [$exitCode, $output] = g5_runVerifyAudit(['file' => $html]);

    expect($exitCode)->toBe(ExitCode::IoError->value)
        ->and($output)->toBe('Signature file not found: '.$sig."\n");

    @unlink($html);
});

it('falls back to the default sig path when --sig is empty', function (): void {
    $canonicalJson = json_encode(['success' => true]) ?: '{}';
    [$html, $sig] = g5_writeAuditPair(makeAuditHtml($canonicalJson), 'sha256:'.g5_signAudit($canonicalJson, (string) config('app.key')));

    $this->artisan('cloning:verify-audit', ['file' => $html, '--sig' => ''])
        ->expectsOutputToContain('verified')
        ->assertExitCode(ExitCode::Success->value);

    @unlink($html);
    @unlink($sig);
});

it('ignores surrounding whitespace in the signature file', function (): void {
    $canonicalJson = json_encode(['success' => true]) ?: '{}';
    [$html, $sig] = g5_writeAuditPair(makeAuditHtml($canonicalJson), '  sha256:'.g5_signAudit($canonicalJson, (string) config('app.key'))."\n");

    $this->artisan('cloning:verify-audit', ['file' => $html])
        ->expectsOutputToContain('verified')
        ->assertExitCode(ExitCode::Success->value);

    @unlink($html);
    @unlink($sig);
});

it('uses an empty HMAC key when app.key is not a string', function (): void {
    config(['app.key' => null]);
    $canonicalJson = json_encode(['success' => true]) ?: '{}';
    [$html, $sig] = g5_writeAuditPair(makeAuditHtml($canonicalJson), 'sha256:'.g5_signAudit($canonicalJson, ''));

    $this->artisan('cloning:verify-audit', ['file' => $html])
        ->expectsOutputToContain('verified')
        ->assertExitCode(ExitCode::Success->value);

    @unlink($html);
    @unlink($sig);
});

it('decodes quote entities in the embedded audit data', function (): void {
    $canonicalJson = json_encode(['source' => "it's \"prod\" & co"]) ?: '{}';
    $escaped = htmlspecialchars($canonicalJson, ENT_QUOTES | ENT_HTML5);
    expect($escaped)->toContain('&quot;')->toContain('&apos;');
    $htmlContent = '<html><body><script type="application/json" id="audit-data">'.$escaped.'</script></body></html>';
    [$html, $sig] = g5_writeAuditPair($htmlContent, 'sha256:'.g5_signAudit($canonicalJson, (string) config('app.key')));

    $this->artisan('cloning:verify-audit', ['file' => $html])
        ->expectsOutputToContain('verified')
        ->assertExitCode(ExitCode::Success->value);

    @unlink($html);
    @unlink($sig);
});
