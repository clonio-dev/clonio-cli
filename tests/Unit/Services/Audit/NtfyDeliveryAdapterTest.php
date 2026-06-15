<?php

declare(strict_types=1);

use App\Services\Audit\NtfyDeliveryAdapter;
use Illuminate\Support\Facades\Crypt;

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
    ))->toThrow(RuntimeException::class, 'ntfy POST failed');
});

it('uses a plain (unencrypted) token and default template values', function (): void {
    $adapter = new NtfyDeliveryAdapter;

    expect(fn () => $adapter->deliver(
        artefacts: [],
        channelConfig: ['url' => NTFY_DEAD_URL, 'topic' => 't', 'token' => 'plain-token'],
        templateVars: [],
    ))->toThrow(RuntimeException::class, 'ntfy POST failed');
});
