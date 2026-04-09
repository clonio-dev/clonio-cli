<?php

declare(strict_types=1);

use App\Services\Audit\AuditDeliveryService;
use App\Services\Audit\LocalDeliveryAdapter;
use App\Services\Audit\StderrDeliveryAdapter;
use App\Services\Audit\StdoutDeliveryAdapter;
use App\Services\Cloning\RunLogWriter;
use Illuminate\Support\Facades\Storage;

it('silently skips delivery when audit config is null', function (): void {
    Storage::fake('local');

    $adapter = Mockery::mock(LocalDeliveryAdapter::class);
    $adapter->shouldNotReceive('deliver');

    $runLog = new RunLogWriter;
    $service = new AuditDeliveryService($adapter, new StdoutDeliveryAdapter, new StderrDeliveryAdapter, $runLog);

    $service->deliver(
        auditConfig: null,
        auditArtefacts: ['audit.html' => '<html/>'],
        processLogContent: '{"event": "test"}',
        channelOverride: [],
        templateVars: [],
    );
});

it('local channel delivers both audit and process log by default', function (): void {
    Storage::fake('local');

    $adapter = Mockery::mock(LocalDeliveryAdapter::class);
    $adapter->shouldReceive('deliver')->twice()->andReturn();

    $runLog = new RunLogWriter;
    $service = new AuditDeliveryService($adapter, new StdoutDeliveryAdapter, new StderrDeliveryAdapter, $runLog);

    $auditConfig = [
        'channels' => [
            'local-main' => ['type' => 'local', 'path' => 'clonio-logs'],
        ],
    ];

    $service->deliver(
        auditConfig: $auditConfig,
        auditArtefacts: ['audit.html' => '<html/>'],
        processLogContent: '{"event": "test"}',
        channelOverride: [],
        templateVars: ['source' => 'prod', 'target' => 'staging', 'timestamp' => '2026-04-01T14-32-00Z'],
    );
});

it('local channel delivers only audit when delivers_process_log is false', function (): void {
    Storage::fake('local');

    $adapter = Mockery::mock(LocalDeliveryAdapter::class);
    $adapter->shouldReceive('deliver')->once()->andReturn();

    $runLog = new RunLogWriter;
    $service = new AuditDeliveryService($adapter, new StdoutDeliveryAdapter, new StderrDeliveryAdapter, $runLog);

    $auditConfig = [
        'channels' => [
            'local-main' => ['type' => 'local', 'path' => 'clonio-logs', 'delivers_process_log' => false],
        ],
    ];

    $service->deliver(
        auditConfig: $auditConfig,
        auditArtefacts: ['audit.html' => '<html/>'],
        processLogContent: '{"event": "test"}',
        channelOverride: [],
        templateVars: ['source' => 'prod', 'target' => 'staging', 'timestamp' => '2026-04-01T14-32-00Z'],
    );
});

it('stderr channel delivers only process log by default', function (): void {
    Storage::fake('local');

    $localAdapter = Mockery::mock(LocalDeliveryAdapter::class);
    $localAdapter->shouldNotReceive('deliver');

    $stderrAdapter = Mockery::mock(StderrDeliveryAdapter::class);
    // Only one call: the process log (not the audit artefacts)
    $stderrAdapter->shouldReceive('deliver')->once()->andReturn();

    $runLog = new RunLogWriter;
    $service = new AuditDeliveryService($localAdapter, new StdoutDeliveryAdapter, $stderrAdapter, $runLog);

    $auditConfig = [
        'channels' => [
            'errors' => ['type' => 'stderr'],
        ],
    ];

    $service->deliver(
        auditConfig: $auditConfig,
        auditArtefacts: ['audit.html' => '<html/>'],
        processLogContent: '{"event": "test"}',
        channelOverride: [],
        templateVars: ['source' => 'prod', 'target' => 'staging', 'timestamp' => '2026-04-01T14-32-00Z'],
    );
});

it('stdout channel can be configured to deliver audit too', function (): void {
    Storage::fake('local');

    $localAdapter = Mockery::mock(LocalDeliveryAdapter::class);
    $localAdapter->shouldNotReceive('deliver');

    $stdoutAdapter = Mockery::mock(StdoutDeliveryAdapter::class);
    // Two calls: audit artefacts + process log
    $stdoutAdapter->shouldReceive('deliver')->twice()->andReturn();

    $runLog = new RunLogWriter;
    $service = new AuditDeliveryService($localAdapter, $stdoutAdapter, new StderrDeliveryAdapter, $runLog);

    $auditConfig = [
        'channels' => [
            'out' => ['type' => 'stdout', 'delivers_audit' => true, 'delivers_process_log' => true],
        ],
    ];

    $service->deliver(
        auditConfig: $auditConfig,
        auditArtefacts: ['audit.html' => '<html/>'],
        processLogContent: '{"event": "test"}',
        channelOverride: [],
        templateVars: ['source' => 'prod', 'target' => 'staging', 'timestamp' => '2026-04-01T14-32-00Z'],
    );
});

it('skips unsupported channel types', function (): void {
    Storage::fake('local');

    $adapter = Mockery::mock(LocalDeliveryAdapter::class);
    $adapter->shouldNotReceive('deliver');

    $runLog = new RunLogWriter;
    $service = new AuditDeliveryService($adapter, new StdoutDeliveryAdapter, new StderrDeliveryAdapter, $runLog);

    $auditConfig = [
        'channels' => [
            's3-bucket' => ['type' => 's3', 'bucket' => 'my-bucket'],
        ],
    ];

    $service->deliver(
        auditConfig: $auditConfig,
        auditArtefacts: ['audit.html' => '<html/>'],
        processLogContent: '{"event": "test"}',
        channelOverride: [],
        templateVars: [],
    );
});

it('applies channel override to filter channels', function (): void {
    Storage::fake('local');

    $adapter = Mockery::mock(LocalDeliveryAdapter::class);
    $adapter->shouldReceive('deliver')->twice()->andReturn();

    $runLog = new RunLogWriter;
    $service = new AuditDeliveryService($adapter, new StdoutDeliveryAdapter, new StderrDeliveryAdapter, $runLog);

    $auditConfig = [
        'channels' => [
            'local-main'      => ['type' => 'local', 'path' => 'clonio-logs'],
            'local-secondary' => ['type' => 'local', 'path' => 'clonio-logs-2'],
        ],
    ];

    $service->deliver(
        auditConfig: $auditConfig,
        auditArtefacts: ['audit.html' => '<html/>'],
        processLogContent: '{"event": "test"}',
        channelOverride: ['local-main'],
        templateVars: ['source' => 'prod', 'target' => 'staging', 'timestamp' => '2026-04-01T14-32-00Z'],
    );
});
