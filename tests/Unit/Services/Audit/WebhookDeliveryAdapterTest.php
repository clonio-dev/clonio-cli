<?php

declare(strict_types=1);

use App\Enums\AuditChannelType;
use App\Services\Audit\WebhookDeliveryAdapter;
use Illuminate\Support\Facades\Crypt;
use Tests\Unit\Services\Audit\Support\G7FakeServer;
use Tests\Unit\Services\Audit\Support\G7FunctionSpy;

require_once __DIR__.'/Support/G7FunctionSpies.php';

it('builds Teams payload with correct structure', function (): void {
    $adapter = new WebhookDeliveryAdapter(AuditChannelType::MsTeams);
    $reflection = new ReflectionMethod($adapter, 'buildTeamsPayload');
    $payload = $reflection->invoke($adapter, 'production', 'staging', '2026-04-20T10-00-00Z');

    expect($payload)->toBeArray()
        ->and($payload['type'])->toBe('message')
        ->and($payload['attachments'][0]['content']['body'][0]['text'])->toContain('production');
});

it('builds Slack payload with correct structure', function (): void {
    $adapter = new WebhookDeliveryAdapter(AuditChannelType::Slack);
    $reflection = new ReflectionMethod($adapter, 'buildSlackPayload');
    $payload = $reflection->invoke($adapter, 'production', 'staging', '2026-04-20T10-00-00Z');

    expect($payload)->toBeArray()
        ->and($payload['blocks'])->toBeArray()
        ->and($payload['blocks'][0]['text']['text'])->toContain('Clonio Audit');
});

const WEBHOOK_DEAD_URL = 'http://127.0.0.1:1';

it('throws when the webhook URL is empty', function (): void {
    expect(fn () => (new WebhookDeliveryAdapter(AuditChannelType::Slack))->deliver(
        artefacts: [],
        channelConfig: ['webhook_url' => ''],
        templateVars: [],
    ))->toThrow(RuntimeException::class, 'Webhook URL is empty');
});

it('builds the Slack payload and POSTs, throwing on transport failure', function (): void {
    expect(fn () => (new WebhookDeliveryAdapter(AuditChannelType::Slack))->deliver(
        artefacts: ['audit.html' => 'x'],
        channelConfig: ['webhook_url' => WEBHOOK_DEAD_URL],
        templateVars: ['source' => 'prod', 'target' => 'staging', 'timestamp' => '2026-04-20'],
    ))->toThrow(RuntimeException::class, 'Webhook POST failed');
});

it('builds the Teams payload and POSTs, throwing on transport failure', function (): void {
    expect(fn () => (new WebhookDeliveryAdapter(AuditChannelType::MsTeams))->deliver(
        artefacts: [],
        channelConfig: ['webhook_url' => WEBHOOK_DEAD_URL],
        templateVars: [],
    ))->toThrow(RuntimeException::class, 'Webhook POST failed');
});

it('decrypts an encrypted webhook URL before posting', function (): void {
    config(['app.key' => 'base64:ROzyPViGEkER6n3g0OHblde5CygEIcuDlAFbca99xvM=']);

    expect(fn () => (new WebhookDeliveryAdapter(AuditChannelType::Slack))->deliver(
        artefacts: [],
        channelConfig: ['webhook_url' => 'encrypted:'.Crypt::encryptString(WEBHOOK_DEAD_URL)],
        templateVars: [],
    ))->toThrow(RuntimeException::class, 'Webhook POST failed');
});

it('POSTs the exact Teams adaptive card as unescaped JSON', function (): void {
    $server = G7FakeServer::http(200, '1');

    (new WebhookDeliveryAdapter(AuditChannelType::MsTeams))->deliver(
        artefacts: ['audit.html' => 'x'],
        channelConfig: ['webhook_url' => $server->url('/teams')],
        templateVars: ['source' => 'prod', 'target' => 'staging', 'timestamp' => '2026-04-20'],
    );

    $request = $server->requests()[0];
    expect($request['method'])->toBe('POST')
        ->and($request['path'])->toBe('/teams')
        ->and($request['headers']['content-type'])->toBe('application/json')
        ->and($request['body'])->toBe(
            '{"type":"message","attachments":[{"contentType":"application/vnd.microsoft.card.adaptive","content":{"type":"AdaptiveCard",'
            .'"$schema":"http://adaptivecards.io/schemas/adaptive-card.json","version":"1.4","body":['
            .'{"type":"TextBlock","text":"Clonio: prod → staging","weight":"bolder","size":"medium"},'
            .'{"type":"TextBlock","text":"Audit completed at 2026-04-20","wrap":true}]}}]}'
        );
});

it('POSTs the exact Slack payload with default template values', function (): void {
    $server = G7FakeServer::http(299, 'ok');

    (new WebhookDeliveryAdapter)->deliver(
        artefacts: [],
        channelConfig: ['webhook_url' => $server->url('/slack')],
        templateVars: [],
    );

    $request = $server->requests()[0];
    expect($request['path'])->toBe('/slack')
        ->and($request['headers']['content-type'])->toBe('application/json')
        ->and(json_decode($request['body'], true))->toBe([
            'blocks' => [[
                'type' => 'section',
                'text' => [
                    'type' => 'mrkdwn',
                    'text' => sprintf(':white_check_mark: *Clonio Audit* — unknown → unknown\nCompleted at %s', date('Y-m-d')),
                ],
            ]],
        ])
        ->and($request['body'])->toContain('— unknown → unknown');
});

it('posts to a decrypted webhook URL', function (): void {
    config(['app.key' => 'base64:ROzyPViGEkER6n3g0OHblde5CygEIcuDlAFbca99xvM=']);
    $server = G7FakeServer::http(200);

    (new WebhookDeliveryAdapter(AuditChannelType::Slack))->deliver(
        artefacts: [],
        channelConfig: ['webhook_url' => 'encrypted:'.Crypt::encryptString($server->url('/secret-hook'))],
        templateVars: [],
    );

    expect($server->requests()[0]['path'])->toBe('/secret-hook');
});

it('throws when the webhook URL is missing or not a string', function (array $config): void {
    expect(fn () => (new WebhookDeliveryAdapter(AuditChannelType::Slack))->deliver(
        artefacts: [],
        channelConfig: $config,
        templateVars: [],
    ))->toThrow(RuntimeException::class, 'Webhook URL is empty');
})->with([
    'missing' => [[]],
    'non-string' => [['webhook_url' => 42]],
]);

it('throws with the response body on a non-2xx status', function (int $status): void {
    $server = G7FakeServer::http($status, 'boom', 'Nope');

    expect(fn () => (new WebhookDeliveryAdapter)->deliver(
        artefacts: [],
        channelConfig: ['webhook_url' => $server->url()],
        templateVars: [],
    ))->toThrow(RuntimeException::class, sprintf('Webhook POST failed (HTTP %d): boom', $status));
})->with([300, 500]);

it('throws with the cURL error when the status line is not a final response', function (): void {
    $server = G7FakeServer::http(199, 'boom', 'Info');

    expect(fn () => (new WebhookDeliveryAdapter)->deliver(
        artefacts: [],
        channelConfig: ['webhook_url' => $server->url()],
        templateVars: [],
    ))->toThrow(RuntimeException::class, 'Webhook POST failed (HTTP 199): ');
});

it('throws when the payload cannot be JSON encoded', function (): void {
    $server = G7FakeServer::http(200);

    expect(fn () => (new WebhookDeliveryAdapter)->deliver(
        artefacts: [],
        channelConfig: ['webhook_url' => $server->url()],
        templateVars: ['source' => "\xB1\x31"],
    ))->toThrow(RuntimeException::class, 'Failed to encode webhook payload as JSON');

    expect($server->requests())->toBe([]);
});

it('throws when cURL cannot be initialised for the URL', function (): void {
    // libcurl rejects URLs longer than 8,000,000 characters, so curl_init() returns false.
    expect(fn () => (new WebhookDeliveryAdapter)->deliver(
        artefacts: [],
        channelConfig: ['webhook_url' => 'http://127.0.0.1:1/'.str_repeat('a', 8_000_001)],
        templateVars: [],
    ))->toThrow(RuntimeException::class, 'Failed to initialize cURL for webhook POST');
});

it('configures cURL for a 15 second JSON POST returning the response body', function (): void {
    $server = G7FakeServer::http(200);
    G7FunctionSpy::reset();

    (new WebhookDeliveryAdapter)->deliver(
        artefacts: [],
        channelConfig: ['type' => 'webhook', 'webhook_url' => $server->url('/hook')],
        templateVars: ['source' => 's', 'target' => 't', 'timestamp' => 'now'],
    );

    $options = G7FunctionSpy::$calls['curl_setopt_array'];

    expect($options)->toHaveCount(1)
        ->and(array_keys($options[0]))->toBe([CURLOPT_POST, CURLOPT_POSTFIELDS, CURLOPT_RETURNTRANSFER, CURLOPT_HTTPHEADER, CURLOPT_TIMEOUT])
        ->and($options[0][CURLOPT_POST])->toBeTrue()
        ->and($options[0][CURLOPT_POSTFIELDS])->toBe($server->requests()[0]['body'])
        ->and($options[0][CURLOPT_RETURNTRANSFER])->toBeTrue()
        ->and($options[0][CURLOPT_HTTPHEADER])->toBe(['Content-Type: application/json'])
        ->and($options[0][CURLOPT_TIMEOUT])->toBe(15);
});
