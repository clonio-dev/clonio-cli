<?php

declare(strict_types=1);

use App\Enums\AuditChannelType;
use App\Services\Audit\WebhookDeliveryAdapter;
use Illuminate\Support\Facades\Crypt;

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
