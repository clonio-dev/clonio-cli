<?php

declare(strict_types=1);

use App\Contracts\DeliveryAdapterInterface;
use App\Enums\AuditChannelType;
use App\Logging\AuditBuffer;
use App\Services\Audit\AuditDeliveryService;
use App\Services\Audit\EmailDeliveryAdapter;
use App\Services\Audit\LocalDeliveryAdapter;
use App\Services\Audit\NtfyDeliveryAdapter;
use App\Services\Audit\S3DeliveryAdapter;
use App\Services\Audit\StderrDeliveryAdapter;
use App\Services\Audit\StdoutDeliveryAdapter;
use App\Services\Audit\WebhookDeliveryAdapter;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    app(AuditBuffer::class)->clear();
});

function makeDeliveryService(?DeliveryAdapterInterface $localOverride = null, ?DeliveryAdapterInterface $stdoutOverride = null, ?DeliveryAdapterInterface $stderrOverride = null): AuditDeliveryService
{
    return new AuditDeliveryService(
        localAdapter: $localOverride ?? new LocalDeliveryAdapter,
        stdoutAdapter: $stdoutOverride ?? new StdoutDeliveryAdapter,
        stderrAdapter: $stderrOverride ?? new StderrDeliveryAdapter,
        s3Adapter: new S3DeliveryAdapter,
        emailAdapter: new EmailDeliveryAdapter,
        teamsAdapter: new WebhookDeliveryAdapter(AuditChannelType::MsTeams),
        slackAdapter: new WebhookDeliveryAdapter(AuditChannelType::Slack),
        ntfyAdapter: new NtfyDeliveryAdapter,
    );
}

it('silently skips delivery when audit config is null', function (): void {
    Storage::fake('local');

    $adapter = Mockery::mock(LocalDeliveryAdapter::class);
    $adapter->shouldNotReceive('deliver');

    $service = makeDeliveryService(localOverride: $adapter);

    $service->deliver(
        auditConfig: null,
        auditArtefacts: ['audit.html' => '<html/>'],
        processLogContent: '{"event": "test"}',
        channelOverride: [],
        templateVars: [],
    );
});

it('delivers to the default channel', function (): void {
    Storage::fake('local');

    $adapter = Mockery::mock(LocalDeliveryAdapter::class);
    $adapter->shouldReceive('deliver')->twice()->andReturn();

    $service = makeDeliveryService(localOverride: $adapter);

    $service->deliver(
        auditConfig: [
            'use' => ['local-main'],
            'channels' => [
                'local-main' => ['type' => 'local', 'path' => 'clonio-logs'],
            ],
        ],
        auditArtefacts: ['audit.html' => '<html/>'],
        processLogContent: '{"event": "test"}',
        channelOverride: [],
        templateVars: ['source' => 'prod', 'target' => 'staging', 'timestamp' => '2026-04-01T14-32-00Z'],
    );
});

it('delivers only audit when delivers_process_log is false', function (): void {
    Storage::fake('local');

    $adapter = Mockery::mock(LocalDeliveryAdapter::class);
    $adapter->shouldReceive('deliver')->once()->andReturn();

    $service = makeDeliveryService(localOverride: $adapter);

    $service->deliver(
        auditConfig: [
            'use' => ['local-main'],
            'channels' => [
                'local-main' => ['type' => 'local', 'path' => 'clonio-logs', 'delivers_process_log' => false],
            ],
        ],
        auditArtefacts: ['audit.html' => '<html/>'],
        processLogContent: '{"event": "test"}',
        channelOverride: [],
        templateVars: ['source' => 'prod', 'target' => 'staging', 'timestamp' => '2026-04-01T14-32-00Z'],
    );
});

it('channel override overrides default', function (): void {
    Storage::fake('local');

    $adapter = Mockery::mock(LocalDeliveryAdapter::class);
    $adapter->shouldReceive('deliver')->twice()->andReturn();

    $service = makeDeliveryService(localOverride: $adapter);

    $service->deliver(
        auditConfig: [
            'use' => ['local-secondary'],
            'channels' => [
                'local-main' => ['type' => 'local', 'path' => 'clonio-logs'],
                'local-secondary' => ['type' => 'local', 'path' => 'clonio-logs-2'],
            ],
        ],
        auditArtefacts: ['audit.html' => '<html/>'],
        processLogContent: '{"event": "test"}',
        channelOverride: ['local-main'],
        templateVars: ['source' => 'prod', 'target' => 'staging', 'timestamp' => '2026-04-01T14-32-00Z'],
    );
});

it('stack channel fans out to child channels', function (): void {
    Storage::fake('local');

    $localAdapter = Mockery::mock(LocalDeliveryAdapter::class);
    // local-a gets audit + process log (2 calls)
    // local-b gets audit + process log (2 calls)
    $localAdapter->shouldReceive('deliver')->times(4)->andReturn();

    $service = makeDeliveryService(localOverride: $localAdapter);

    $service->deliver(
        auditConfig: [
            'use' => ['all'],
            'channels' => [
                'local-a' => ['type' => 'local', 'path' => './a'],
                'local-b' => ['type' => 'local', 'path' => './b'],
                'all' => ['type' => 'stack', 'channels' => ['local-a', 'local-b']],
            ],
        ],
        auditArtefacts: ['audit.html' => '<html/>'],
        processLogContent: '{"event": "test"}',
        channelOverride: [],
        templateVars: ['source' => 'prod', 'target' => 'staging', 'timestamp' => '2026-04-01T14-32-00Z'],
    );
});

it('logs warning for unknown channel name', function (): void {
    Storage::fake('local');

    $service = makeDeliveryService();

    $service->deliver(
        auditConfig: [
            'use' => ['nonexistent'],
            'channels' => [],
        ],
        auditArtefacts: ['audit.html' => '<html/>'],
        processLogContent: '{"event": "test"}',
        channelOverride: [],
        templateVars: [],
    );

    $events = array_column(app(AuditBuffer::class)->records(), 'event');
    expect($events)->toContain('audit_channel_not_found');
});

it('skips delivery when use list is empty', function (): void {
    Storage::fake('local');

    $adapter = Mockery::mock(LocalDeliveryAdapter::class);
    $adapter->shouldNotReceive('deliver');

    $service = makeDeliveryService(localOverride: $adapter);

    $service->deliver(
        auditConfig: [
            'channels' => [
                'local-main' => ['type' => 'local', 'path' => 'clonio-logs'],
            ],
            'use' => [],
        ],
        auditArtefacts: ['audit.html' => '<html/>'],
        processLogContent: '{"event": "test"}',
        channelOverride: [],
        templateVars: ['source' => 'prod', 'target' => 'staging', 'timestamp' => '2026-04-01T14-32-00Z'],
    );
});
