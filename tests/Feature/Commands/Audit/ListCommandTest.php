<?php

declare(strict_types=1);

use App\Services\Config\ConfigService;

it('shows message when no channels are configured', function (): void {
    $config = Mockery::mock(ConfigService::class);
    $config->shouldReceive('getAuditChannels')->andReturn([]);
    $config->shouldReceive('getAuditDeliverTo')->andReturn([]);
    $this->app->instance(ConfigService::class, $config);

    $this->artisan('audit:list')
        ->expectsOutputToContain('No audit channels configured')
        ->assertExitCode(0);
});

it('lists a local channel with type label in output', function (): void {
    $config = Mockery::mock(ConfigService::class);
    $config->shouldReceive('getAuditChannels')->andReturn([
        'my-local' => [
            'type' => 'local',
            'audit_log' => ['path' => './logs/'],
            'run_log' => ['path' => './run-logs/'],
        ],
    ]);
    $config->shouldReceive('getAuditDeliverTo')->with('audit_log')->andReturn(['my-local']);
    $config->shouldReceive('getAuditDeliverTo')->with('run_log')->andReturn([]);
    $this->app->instance(ConfigService::class, $config);

    $this->artisan('audit:list')
        ->expectsOutputToContain('my-local')
        ->assertExitCode(0);
});

it('lists an s3 channel and exits successfully', function (): void {
    $config = Mockery::mock(ConfigService::class);
    $config->shouldReceive('getAuditChannels')->andReturn([
        'my-s3' => [
            'type' => 's3',
            'bucket' => 'my-bucket',
            'path_prefix' => 'clonio/logs/',
        ],
    ]);
    $config->shouldReceive('getAuditDeliverTo')->with('audit_log')->andReturn([]);
    $config->shouldReceive('getAuditDeliverTo')->with('run_log')->andReturn(['my-s3']);
    $this->app->instance(ConfigService::class, $config);

    $this->artisan('audit:list')
        ->expectsOutputToContain('my-s3')
        ->assertExitCode(0);
});

it('lists an email channel and exits successfully', function (): void {
    $config = Mockery::mock(ConfigService::class);
    $config->shouldReceive('getAuditChannels')->andReturn([
        'my-email' => [
            'type' => 'email',
            'to' => ['admin@example.com', 'ops@example.com'],
        ],
    ]);
    $config->shouldReceive('getAuditDeliverTo')->with('audit_log')->andReturn([]);
    $config->shouldReceive('getAuditDeliverTo')->with('run_log')->andReturn([]);
    $this->app->instance(ConfigService::class, $config);

    $this->artisan('audit:list')
        ->expectsOutputToContain('my-email')
        ->assertExitCode(0);
});

it('lists slack channel and exits successfully', function (): void {
    $config = Mockery::mock(ConfigService::class);
    $config->shouldReceive('getAuditChannels')->andReturn([
        'my-slack' => [
            'type' => 'slack',
            'webhook_url' => 'encrypted:abc',
        ],
    ]);
    $config->shouldReceive('getAuditDeliverTo')->with('audit_log')->andReturn([]);
    $config->shouldReceive('getAuditDeliverTo')->with('run_log')->andReturn([]);
    $this->app->instance(ConfigService::class, $config);

    $this->artisan('audit:list')
        ->expectsOutputToContain('my-slack')
        ->assertExitCode(0);
});

it('lists ntfy channel and exits successfully', function (): void {
    $config = Mockery::mock(ConfigService::class);
    $config->shouldReceive('getAuditChannels')->andReturn([
        'my-ntfy' => [
            'type' => 'ntfy',
            'url' => 'https://ntfy.sh',
            'topic' => 'my-topic',
        ],
    ]);
    $config->shouldReceive('getAuditDeliverTo')->with('audit_log')->andReturn([]);
    $config->shouldReceive('getAuditDeliverTo')->with('run_log')->andReturn([]);
    $this->app->instance(ConfigService::class, $config);

    $this->artisan('audit:list')
        ->expectsOutputToContain('my-ntfy')
        ->assertExitCode(0);
});
