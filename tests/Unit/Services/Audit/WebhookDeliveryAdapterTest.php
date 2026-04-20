<?php

declare(strict_types=1);

use App\Enums\AuditChannelType;
use App\Services\Audit\WebhookDeliveryAdapter;

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
