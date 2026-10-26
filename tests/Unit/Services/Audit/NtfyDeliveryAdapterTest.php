<?php

declare(strict_types=1);

use App\Services\Audit\NtfyDeliveryAdapter;
use Illuminate\Support\Facades\Crypt;
use Tests\Unit\Services\Audit\Support\G7FakeServer;
use Tests\Unit\Services\Audit\Support\G7FunctionSpy;

require_once __DIR__.'/Support/G7FunctionSpies.php';

/** A port that nothing listens on: cURL fails fast with a connection error. */
const NTFY_DEAD_URL = 'http://127.0.0.1:1';

it('builds correct ntfy URL from config', function (): void {
    $adapter = new NtfyDeliveryAdapter;
    $reflection = new ReflectionMethod($adapter, 'buildUrl');
    $url = $reflection->invoke($adapter, ['url' => 'https://ntfy.sh', 'topic' => 'clonio-alerts']);
    expect($url)->toBe('https://ntfy.sh/clonio-alerts');
});

it('builds correct ntfy URL with trailing slash in base URL', function (): void {
    $adapter = new NtfyDeliveryAdapter;
    $reflection = new ReflectionMethod($adapter, 'buildUrl');
    $url = $reflection->invoke($adapter, ['url' => 'https://ntfy.example.com/', 'topic' => 'audit']);
    expect($url)->toBe('https://ntfy.example.com/audit');
});

it('builds headers and POSTs, throwing on transport failure', function (): void {
    config(['app.key' => 'base64:ROzyPViGEkER6n3g0OHblde5CygEIcuDlAFbca99xvM=']);
    $adapter = new NtfyDeliveryAdapter;

    expect(fn () => $adapter->deliver(
        artefacts: ['audit.html' => '<h1>x</h1>'],
        channelConfig: [
            'url' => NTFY_DEAD_URL,
            'topic' => 'alerts',
            'priority' => 'high',
            'tags' => ['warning', 'rotating_light'],
            'token' => 'encrypted:'.Crypt::encryptString('tok-123'),
        ],
        templateVars: ['source' => 'prod', 'target' => 'staging', 'timestamp' => '2026-04-20'],
    ))->toThrow(RuntimeException::class, 'ntfy POST failed (HTTP 0): ');
});

it('POSTs the notification with title, priority, tags and a decrypted bearer token', function (): void {
    config(['app.key' => 'base64:ROzyPViGEkER6n3g0OHblde5CygEIcuDlAFbca99xvM=']);
    $server = G7FakeServer::http(200, 'ok');

    (new NtfyDeliveryAdapter)->deliver(
        artefacts: ['audit.html' => '<h1>x</h1>', 'audit.sig' => 'sig'],
        channelConfig: [
            'url' => $server->url('/'),
            'topic' => 'alerts',
            'priority' => 'high',
            'tags' => ['warning', 5, 1.5, true, ['nested']],
            'token' => 'encrypted:'.Crypt::encryptString('tok-123'),
        ],
        templateVars: ['source' => 'prod', 'target' => 'staging', 'timestamp' => '2026-04-20'],
    );

    $requests = $server->requests();
    expect($requests)->toHaveCount(1);

    $request = $requests[0];
    expect($request['method'])->toBe('POST')
        ->and($request['path'])->toBe('/alerts')
        ->and($request['body'])->toBe('Audit completed at 2026-04-20. 2 artefact(s) generated.')
        ->and($request['headers']['title'])->toBe('Clonio: prod → staging')
        ->and($request['headers']['priority'])->toBe('high')
        ->and($request['headers']['tags'])->toBe('warning,5,1.5,1,')
        ->and($request['headers']['authorization'])->toBe('Bearer tok-123');
});

it('uses a plain token, default priority, no tags and default template values', function (): void {
    $server = G7FakeServer::http(299, 'fine');

    (new NtfyDeliveryAdapter)->deliver(
        artefacts: [],
        channelConfig: ['url' => $server->url(), 'topic' => 't', 'token' => 'plain-token'],
        templateVars: [],
    );

    $request = $server->requests()[0];
    expect($request['path'])->toBe('/t')
        ->and($request['body'])->toBe(sprintf('Audit completed at %s. 0 artefact(s) generated.', date('Y-m-d')))
        ->and($request['headers']['title'])->toBe('Clonio: unknown → unknown')
        ->and($request['headers']['priority'])->toBe('default')
        ->and($request['headers'])->not->toHaveKey('tags')
        ->and($request['headers']['authorization'])->toBe('Bearer plain-token');
});

it('posts to the bare base URL when no topic is configured and omits auth without a token', function (): void {
    $server = G7FakeServer::http(200);

    (new NtfyDeliveryAdapter)->deliver(
        artefacts: ['a' => 'b'],
        channelConfig: ['url' => $server->url(), 'priority' => 5, 'tags' => 'not-an-array'],
        templateVars: ['source' => 's', 'target' => 't', 'timestamp' => 'now'],
    );

    $request = $server->requests()[0];
    expect($request['path'])->toBe('/')
        ->and($request['headers']['priority'])->toBe('default')
        ->and($request['headers'])->not->toHaveKey('tags')
        ->and($request['headers'])->not->toHaveKey('authorization');
});

it('omits the authorization header for an empty or non-string token', function (mixed $token): void {
    $server = G7FakeServer::http(200);

    (new NtfyDeliveryAdapter)->deliver(
        artefacts: [],
        channelConfig: ['url' => $server->url(), 'topic' => 'x', 'token' => $token],
        templateVars: [],
    );

    expect($server->requests()[0]['headers'])->not->toHaveKey('authorization');
})->with([
    'empty string' => [''],
    'integer' => [123],
]);

it('throws with the response body when ntfy answers with a non-2xx status', function (int $status): void {
    $server = G7FakeServer::http($status, 'boom', 'Nope');

    expect(fn () => (new NtfyDeliveryAdapter)->deliver(
        artefacts: [],
        channelConfig: ['url' => $server->url(), 'topic' => 'x'],
        templateVars: [],
    ))->toThrow(RuntimeException::class, sprintf('ntfy POST failed (HTTP %d): boom', $status));
})->with([300, 500]);

it('throws with the cURL error when the status line is not a final response', function (): void {
    $server = G7FakeServer::http(199, 'boom', 'Info');

    expect(fn () => (new NtfyDeliveryAdapter)->deliver(
        artefacts: [],
        channelConfig: ['url' => $server->url(), 'topic' => 'x'],
        templateVars: [],
    ))->toThrow(RuntimeException::class, 'ntfy POST failed (HTTP 199): ');
});

it('throws when cURL cannot be initialised for the URL', function (): void {
    // libcurl rejects URLs longer than 8,000,000 characters, so curl_init() returns false.
    expect(fn () => (new NtfyDeliveryAdapter)->deliver(
        artefacts: [],
        channelConfig: ['url' => 'http://127.0.0.1:1', 'topic' => str_repeat('a', 8_000_001)],
        templateVars: [],
    ))->toThrow(RuntimeException::class, 'Failed to initialize cURL for ntfy POST');
});

it('configures cURL for a 15 second POST returning the response body', function (): void {
    $server = G7FakeServer::http(200);
    G7FunctionSpy::reset();

    (new NtfyDeliveryAdapter)->deliver(
        artefacts: [],
        channelConfig: ['url' => $server->url(), 'topic' => 'x', 'token' => 'tok'],
        templateVars: ['source' => 's', 'target' => 't', 'timestamp' => 'now'],
    );

    expect(G7FunctionSpy::$calls['curl_setopt_array'])->toBe([[
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => 'Audit completed at now. 0 artefact(s) generated.',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Title: Clonio: s → t', 'Priority: default', 'Authorization: Bearer tok'],
        CURLOPT_TIMEOUT => 15,
    ]]);
});
