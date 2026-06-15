<?php

declare(strict_types=1);

use App\Services\Audit\EmailDeliveryAdapter;
use Illuminate\Support\Facades\Crypt;

/** @return array<string, mixed> */
function emailConfig(array $overrides = []): array
{
    return array_merge([
        'host' => '127.0.0.1',
        'port' => 1, // nothing listens — stream_socket_client fails fast
        'encryption' => 'tls',
        'username' => 'user',
        'password' => 'pass',
        'from_address' => 'audit@clonio.dev',
        'from_name' => 'Clonio',
        'to' => ['ops@example.com'],
    ], $overrides);
}

it('builds email subject with source and target from template vars', function (): void {
    $adapter = new EmailDeliveryAdapter;
    $reflection = new ReflectionMethod($adapter, 'buildSubject');
    $subject = $reflection->invoke($adapter, [
        'source' => 'production',
        'target' => 'staging',
        'timestamp' => '2026-04-20T10-00-00Z',
    ]);
    expect($subject)->toBe('Clonio Audit Log — production → staging (2026-04-20T10-00-00Z)');
});

it('builds email subject with defaults when template vars missing', function (): void {
    $adapter = new EmailDeliveryAdapter;
    $reflection = new ReflectionMethod($adapter, 'buildSubject');
    $subject = $reflection->invoke($adapter, []);
    expect($subject)->toContain('unknown');
});

it('throws when no recipients are configured', function (): void {
    expect(fn () => (new EmailDeliveryAdapter)->deliver(['a.html' => 'x'], emailConfig(['to' => []]), []))
        ->toThrow(RuntimeException::class, 'no recipients');
});

it('throws when no valid (string) recipients remain', function (): void {
    expect(fn () => (new EmailDeliveryAdapter)->deliver(['a.html' => 'x'], emailConfig(['to' => [123, false]]), []))
        ->toThrow(RuntimeException::class, 'no valid recipients');
});

it('builds the MIME body and attempts an SMTP connection (tcp/tls)', function (): void {
    expect(fn () => (new EmailDeliveryAdapter)->deliver(
        ['audit.html' => '<h1>x</h1>', 'audit.sig' => 'sha256:abc'],
        emailConfig(),
        ['source' => 'prod', 'target' => 'staging', 'timestamp' => '2026-04-20'],
    ))->toThrow(RuntimeException::class, 'SMTP connection failed');
});

it('uses the ssl:// transport when encryption is ssl', function (): void {
    expect(fn () => (new EmailDeliveryAdapter)->deliver(
        ['a.html' => 'x'],
        emailConfig(['encryption' => 'ssl']),
        [],
    ))->toThrow(RuntimeException::class, 'SMTP connection failed');
});

it('decrypts an encrypted SMTP password before connecting', function (): void {
    config(['app.key' => 'base64:ROzyPViGEkER6n3g0OHblde5CygEIcuDlAFbca99xvM=']);

    expect(fn () => (new EmailDeliveryAdapter)->deliver(
        ['a.html' => 'x'],
        emailConfig(['password' => 'encrypted:'.Crypt::encryptString('s3cret')]),
        [],
    ))->toThrow(RuntimeException::class, 'SMTP connection failed');
});
