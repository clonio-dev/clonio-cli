<?php

declare(strict_types=1);

use App\Contracts\DeliveryAdapterInterface;
use App\Enums\AuditChannelType;
use App\Services\Audit\AuditDeliveryService;
use App\Services\Audit\EmailDeliveryAdapter;
use App\Services\Audit\LocalDeliveryAdapter;
use App\Services\Audit\NtfyDeliveryAdapter;
use App\Services\Audit\S3DeliveryAdapter;
use App\Services\Audit\StderrDeliveryAdapter;
use App\Services\Audit\StdoutDeliveryAdapter;
use App\Services\Audit\WebhookDeliveryAdapter;
use App\Services\Cloning\RunLogWriter;
use Illuminate\Support\Facades\Storage;

function makeDeliveryService(RunLogWriter $runLog, ?DeliveryAdapterInterface $localOverride = null, ?DeliveryAdapterInterface $stdoutOverride = null, ?DeliveryAdapterInterface $stderrOverride = null): AuditDeliveryService
{
    return new AuditDeliveryService(
        runLog: $runLog,
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

    $runLog = new RunLogWriter;
    $service = makeDeliveryService($runLog, localOverride: $adapter);

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

    $runLog = new RunLogWriter;
    $service = makeDeliveryService($runLog, localOverride: $adapter);

    $service->deliver(
        auditConfig: [
            'default' => 'local-main',
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

    $runLog = new RunLogWriter;
    $service = makeDeliveryService($runLog, localOverride: $adapter);

    $service->deliver(
        auditConfig: [
            'default' => 'local-main',
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

    $runLog = new RunLogWriter;
    $service = makeDeliveryService($runLog, localOverride: $adapter);

    $service->deliver(
        auditConfig: [
            'default' => 'local-secondary',
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

    $runLog = new RunLogWriter;
    $service = makeDeliveryService($runLog, localOverride: $localAdapter);

    $service->deliver(
        auditConfig: [
            'default' => 'all',
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

    $runLog = new RunLogWriter;
    $service = makeDeliveryService($runLog);

    $service->deliver(
        auditConfig: [
            'default' => 'nonexistent',
            'channels' => [],
        ],
        auditArtefacts: ['audit.html' => '<html/>'],
        processLogContent: '{"event": "test"}',
        channelOverride: [],
        templateVars: [],
    );

    $log = $runLog->flush();
    expect($log)->toContain('audit_channel_not_found');
});

it('falls back to legacy deliver_to when default is missing', function (): void {
    Storage::fake('local');

    $adapter = Mockery::mock(LocalDeliveryAdapter::class);
    $adapter->shouldReceive('deliver')->twice()->andReturn();

    $runLog = new RunLogWriter;
    $service = makeDeliveryService($runLog, localOverride: $adapter);

    $service->deliver(
        auditConfig: [
            'channels' => [
                'local-main' => ['type' => 'local', 'path' => 'clonio-logs'],
            ],
            'audit_log' => ['deliver_to' => ['local-main']],
        ],
        auditArtefacts: ['audit.html' => '<html/>'],
        processLogContent: '{"event": "test"}',
        channelOverride: [],
        templateVars: ['source' => 'prod', 'target' => 'staging', 'timestamp' => '2026-04-01T14-32-00Z'],
    );
});
