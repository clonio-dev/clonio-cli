<?php

declare(strict_types=1);

use App\Services\Audit\AuditDeliveryService;
use App\Services\Audit\LocalDeliveryAdapter;
use App\Services\Cloning\RunLogWriter;
use Illuminate\Support\Facades\Storage;

it('silently skips delivery when audit config is null', function (): void {
    Storage::fake('local');

    $adapter = Mockery::mock(LocalDeliveryAdapter::class);
    $adapter->shouldNotReceive('deliver');

    $runLog = new RunLogWriter;
    $service = new AuditDeliveryService($adapter, $runLog);

    $service->deliver(
        auditConfig: null,
        auditArtefacts: ['audit.html' => '<html/>'],
        runLogContent: '{"event": "test"}',
        channelOverride: [],
        templateVars: [],
    );
});

it('delivers to local channel when configured', function (): void {
    Storage::fake('local');

    $adapter = Mockery::mock(LocalDeliveryAdapter::class);
    $adapter->shouldReceive('deliver')
        ->twice()
        ->andReturn();

    $runLog = new RunLogWriter;
    $service = new AuditDeliveryService($adapter, $runLog);

    $auditConfig = [
        'channels' => [
            'local-main' => [
                'type' => 'local',
                'audit_log' => ['path' => 'audit-logs'],
                'run_log' => ['path' => 'run-logs'],
            ],
        ],
    ];

    $service->deliver(
        auditConfig: $auditConfig,
        auditArtefacts: ['audit.html' => '<html/>'],
        runLogContent: '{"event": "test"}',
        channelOverride: [],
        templateVars: ['source' => 'prod', 'target' => 'staging', 'timestamp' => '2026-04-01T14-32-00Z'],
    );
});

it('skips unsupported channel types', function (): void {
    Storage::fake('local');

    $adapter = Mockery::mock(LocalDeliveryAdapter::class);
    $adapter->shouldNotReceive('deliver');

    $runLog = new RunLogWriter;
    $service = new AuditDeliveryService($adapter, $runLog);

    $auditConfig = [
        'channels' => [
            's3-bucket' => [
                'type' => 's3',
                'audit_log' => ['bucket' => 'my-bucket'],
            ],
        ],
    ];

    $service->deliver(
        auditConfig: $auditConfig,
        auditArtefacts: ['audit.html' => '<html/>'],
        runLogContent: '{"event": "test"}',
        channelOverride: [],
        templateVars: [],
    );
});

it('applies channel override to filter channels', function (): void {
    Storage::fake('local');

    $adapter = Mockery::mock(LocalDeliveryAdapter::class);
    // Only one channel should be used (the overridden one)
    $adapter->shouldReceive('deliver')->twice()->andReturn();

    $runLog = new RunLogWriter;
    $service = new AuditDeliveryService($adapter, $runLog);

    $auditConfig = [
        'channels' => [
            'local-main' => [
                'type' => 'local',
                'audit_log' => ['path' => 'audit-logs'],
                'run_log' => ['path' => 'run-logs'],
            ],
            'local-secondary' => [
                'type' => 'local',
                'audit_log' => ['path' => 'audit-logs-2'],
                'run_log' => ['path' => 'run-logs-2'],
            ],
        ],
    ];

    $service->deliver(
        auditConfig: $auditConfig,
        auditArtefacts: ['audit.html' => '<html/>'],
        runLogContent: '{"event": "test"}',
        channelOverride: ['local-main'],
        templateVars: ['source' => 'prod', 'target' => 'staging', 'timestamp' => '2026-04-01T14-32-00Z'],
    );
});
