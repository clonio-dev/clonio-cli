<?php

declare(strict_types=1);

use App\Services\Audit\EmailDeliveryAdapter;
use Illuminate\Support\Facades\Crypt;
use Tests\Unit\Services\Audit\Support\G7FakeServer;
use Tests\Unit\Services\Audit\Support\G7FunctionSpy;

require_once __DIR__.'/Support/G7FunctionSpies.php';

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

/**
 * Replace the random MIME boundary and the Date header of an SMTP transcript with fixed placeholders.
 *
 * @return array{string, string}
 */
function g7_normalizeSmtpTranscript(string $transcript): array
{
    preg_match('/boundary="([^"]*)"/', $transcript, $matches);
    $boundary = $matches[1] ?? '';

    $normalized = preg_replace('/^Date: .*\r\n/m', "Date: <date>\r\n", $transcript);

    return [str_replace($boundary, '<boundary>', (string) $normalized), $boundary];
}

it('speaks SMTP and sends the exact MIME message with all attachments', function (): void {
    $server = G7FakeServer::smtp();
    $html = str_repeat('A', 100);

    (new EmailDeliveryAdapter)->deliver(
        ['audit.html' => $html, 'audit.sig' => 'sha256:abc'],
        emailConfig([
            'port' => $server->port(),
            'encryption' => 'none',
            'from_name' => 'Clonio Bot',
            'to' => ['ops@example.com', 7, 'dev@example.com'],
        ]),
        ['source' => 'prod', 'target' => 'staging', 'timestamp' => '2026-04-20'],
    );

    [$transcript, $boundary] = g7_normalizeSmtpTranscript($server->transcript());
    $ehlo = sprintf("EHLO %s\r\n", gethostname() ?: 'localhost');

    expect($boundary)->toMatch('/^[0-9a-f]{32}$/')
        ->and($server->transcript())->toMatch('/\r\nDate: [A-Z][a-z]{2}, \d{2} [A-Z][a-z]{2} \d{4} \d{2}:\d{2}:\d{2} \+0000\r\n/')
        ->and($transcript)->toBe(
            $ehlo
            ."AUTH LOGIN\r\n"
            .base64_encode('user')."\r\n"
            .base64_encode('pass')."\r\n"
            ."MAIL FROM:<audit@clonio.dev>\r\n"
            ."RCPT TO:<ops@example.com>\r\n"
            ."RCPT TO:<dev@example.com>\r\n"
            ."DATA\r\n"
            ."From: Clonio Bot <audit@clonio.dev>\r\n"
            ."To: ops@example.com, dev@example.com\r\n"
            ."Subject: Clonio Audit Log — prod → staging (2026-04-20)\r\n"
            ."MIME-Version: 1.0\r\n"
            ."Content-Type: multipart/mixed; boundary=\"<boundary>\"\r\n"
            ."Date: <date>\r\n"
            ."\r\n"
            ."--<boundary>\r\n"
            ."Content-Type: text/plain; charset=UTF-8\r\n\r\n"
            ."Clonio audit log attached. See HTML attachment for the full report.\r\n\r\n"
            ."--<boundary>\r\n"
            ."Content-Type: application/octet-stream; name=\"audit.html\"\r\n"
            ."Content-Transfer-Encoding: base64\r\n"
            ."Content-Disposition: attachment; filename=\"audit.html\"\r\n\r\n"
            .substr(base64_encode($html), 0, 76)."\r\n"
            .substr(base64_encode($html), 76)."\r\n"
            ."--<boundary>\r\n"
            ."Content-Type: application/octet-stream; name=\"audit.sig\"\r\n"
            ."Content-Transfer-Encoding: base64\r\n"
            ."Content-Disposition: attachment; filename=\"audit.sig\"\r\n\r\n"
            .base64_encode('sha256:abc')."\r\n"
            ."--<boundary>--\r\n"
            ."\r\n.\r\n"
            ."QUIT\r\n"
        );
});

it('falls back to default sender, subject values and skips AUTH without credentials', function (): void {
    $server = G7FakeServer::smtp();

    (new EmailDeliveryAdapter)->deliver(
        [],
        [
            'host' => '127.0.0.1',
            'port' => $server->port(),
            'encryption' => 'none',
            'password' => 'pass',
            'to' => ['ops@example.com'],
        ],
        [],
    );

    $transcript = $server->transcript();

    expect($transcript)->not->toContain('AUTH LOGIN')
        ->and($transcript)->toContain("MAIL FROM:<>\r\n")
        ->and($transcript)->toContain("\r\nFrom: Clonio <>\r\n")
        ->and($transcript)->toContain(sprintf("\r\nSubject: Clonio Audit Log — unknown → unknown (%s)\r\n", date('Y-m-d')));
});

it('skips AUTH when the password is missing or not a string', function (array $credentials): void {
    $server = G7FakeServer::smtp();

    (new EmailDeliveryAdapter)->deliver(
        ['a.txt' => 'x'],
        array_merge(['host' => '127.0.0.1', 'port' => $server->port(), 'encryption' => 'none', 'to' => ['ops@example.com']], $credentials),
        [],
    );

    expect($server->transcript())->not->toContain('AUTH LOGIN')
        ->and($server->transcript())->toContain("RCPT TO:<ops@example.com>\r\n");
})->with([
    'missing password' => [['username' => 'user']],
    'non-string password' => [['username' => 'user', 'password' => 123]],
    'non-string username' => [['username' => 123, 'password' => 'pass']],
    'non-string sender' => [['from_address' => 1, 'from_name' => 2]],
]);

it('authenticates with a decrypted password', function (): void {
    config(['app.key' => 'base64:ROzyPViGEkER6n3g0OHblde5CygEIcuDlAFbca99xvM=']);
    $server = G7FakeServer::smtp();

    (new EmailDeliveryAdapter)->deliver(
        [],
        emailConfig(['port' => $server->port(), 'encryption' => 'none', 'password' => 'encrypted:'.Crypt::encryptString('s3cret')]),
        [],
    );

    expect($server->transcript())->toContain("AUTH LOGIN\r\n".base64_encode('user')."\r\n".base64_encode('s3cret')."\r\n");
});

it('upgrades the connection with STARTTLS when encryption is missing (tls default)', function (array $config): void {
    $server = G7FakeServer::smtp(['STARTTLS' => '454 TLS not available']);

    expect(fn () => (new EmailDeliveryAdapter)->deliver(
        [],
        array_merge(['host' => '127.0.0.1', 'port' => $server->port(), 'to' => ['ops@example.com']], $config),
        [],
    ))->toThrow(RuntimeException::class, 'SMTP error 454: 454 TLS not available');

    expect($server->transcript())->toBe(sprintf("EHLO %s\r\nSTARTTLS\r\n", gethostname() ?: 'localhost'));
})->with([
    'missing' => [[]],
    'non-string' => [['encryption' => 1]],
]);

it('connects via ssl:// when encryption is ssl, which fails against a plaintext server', function (): void {
    $server = G7FakeServer::smtp();

    expect(fn () => (new EmailDeliveryAdapter)->deliver(
        [],
        emailConfig(['port' => $server->port(), 'encryption' => 'ssl']),
        [],
    ))->toThrow(RuntimeException::class, 'SMTP connection failed');

    expect($server->transcript())->not->toContain('EHLO');
});

it('fails to resolve an empty host when none is configured', function (mixed $host): void {
    expect(fn () => (new EmailDeliveryAdapter)->deliver(
        [],
        ['host' => $host, 'port' => 1, 'to' => ['ops@example.com']],
        [],
    ))->toThrow(RuntimeException::class, 'getaddrinfo for  failed');
})->with([
    'non-string' => [false],
]);

it('fails to resolve an empty host when the host key is missing', function (): void {
    expect(fn () => (new EmailDeliveryAdapter)->deliver(
        [],
        ['port' => 1, 'to' => ['ops@example.com']],
        [],
    ))->toThrow(RuntimeException::class, 'getaddrinfo for  failed');
});

it('throws when no recipients key is configured', function (): void {
    expect(fn () => (new EmailDeliveryAdapter)->deliver(['a.html' => 'x'], ['host' => '127.0.0.1'], []))
        ->toThrow(RuntimeException::class, 'Email channel has no recipients configured');
});

/**
 * Deliver one plain-text audit mail against a fake SMTP server and return what was thrown (if anything).
 *
 * @param  array<string, string>  $replies
 */
function g7_deliverAgainstSmtp(array $replies): ?Throwable
{
    $server = G7FakeServer::smtp($replies);

    try {
        (new EmailDeliveryAdapter)->deliver([], emailConfig(['port' => $server->port(), 'encryption' => 'none']), []);
    } catch (Throwable $e) {
        return $e;
    }

    return null;
}

it('reports the full trimmed multi-line SMTP error response', function (): void {
    $e = g7_deliverAgainstSmtp(['GREETING' => "554-first line\r\n554 second line"]);

    expect($e)->toBeInstanceOf(RuntimeException::class)
        ->and($e?->getMessage())->toBe("SMTP error 554: 554-first line\r\n554 second line");
});

it('reads SMTP replies in chunks of at most 514 bytes', function (): void {
    $e = g7_deliverAgainstSmtp(['MAIL FROM' => '554 '.str_repeat('x', 600)]);

    expect($e?->getMessage())->toBe('SMTP error 554: 554 '.str_repeat('x', 510));
});

it('treats reply codes from 400 upwards as errors', function (): void {
    expect(g7_deliverAgainstSmtp(['MAIL FROM' => '400 temporary'])?->getMessage())->toBe('SMTP error 400: 400 temporary')
        ->and(g7_deliverAgainstSmtp(['MAIL FROM' => '399 odd but fine']))->toBeNull();
});

it('takes the reply code from the first three characters of the response only', function (): void {
    // Continuation line without a space at offset 3: "2201" must not be read as code 2201.
    expect(g7_deliverAgainstSmtp(['GREETING' => "2201 hi\r\n220 ready"]))->toBeNull();
});

it('compares the reply code numerically', function (): void {
    // "9ab" is code 9 (< 400); a string comparison against 400 would reject it.
    expect(g7_deliverAgainstSmtp(['GREETING' => '9ab odd greeting']))->toBeNull();
});

it('keeps reading past reply lines shorter than four characters', function (): void {
    expect(g7_deliverAgainstSmtp(['GREETING' => "5\r\n220 ready"]))->toBeNull();
});

it('reads the reply to the message body before sending QUIT', function (): void {
    $e = g7_deliverAgainstSmtp(['QUIT' => '421 closing']);

    expect($e?->getMessage())->toBe('SMTP error 421: 421 closing');
});

it('closes the SMTP socket even when the conversation fails', function (): void {
    $server = G7FakeServer::smtp(['MAIL FROM' => '550 denied']);

    try {
        (new EmailDeliveryAdapter)->deliver([], emailConfig(['port' => $server->port(), 'encryption' => 'none']), []);
        $e = null;
    } catch (RuntimeException $e) {
        // keep $e (and the socket arguments in its trace) alive while inspecting open streams
    }

    $open = array_filter(
        get_resources('stream'),
        fn ($stream): bool => str_ends_with((string) @stream_socket_get_name($stream, true), ':'.$server->port()),
    );

    expect($e)->toBeInstanceOf(RuntimeException::class)
        ->and($open)->toBe([]);
});

it('connects to the SMTP submission port 587 with a 15 second timeout by default', function (array $config, string $address): void {
    G7FunctionSpy::reset();

    try {
        (new EmailDeliveryAdapter)->deliver([], array_merge(['host' => '127.0.0.1', 'to' => ['ops@example.com']], $config), []);
    } catch (RuntimeException) {
        // nothing (or anything) may listen on 587 here; only the connection attempt matters
    }

    expect(G7FunctionSpy::$calls['stream_socket_client'][0])->toBe(['address' => $address, 'timeout' => 15.0]);
})->with([
    'missing port' => [[], 'tcp://127.0.0.1:587'],
    'non-integer port' => [['port' => '2525'], 'tcp://127.0.0.1:587'],
    'implicit TLS' => [['port' => 465, 'encryption' => 'ssl'], 'ssl://127.0.0.1:465'],
]);
