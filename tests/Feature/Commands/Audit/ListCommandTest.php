<?php

declare(strict_types=1);

use App\Services\Config\ConfigService;

it('shows message when no channels are configured', function (): void {
    $config = Mockery::mock(ConfigService::class);
    $config->shouldReceive('getAuditChannels')->andReturn([]);
    $config->shouldReceive('getAuditUse')->andReturn([]);
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
            'path' => './clonio-logs/',
        ],
    ]);
    $config->shouldReceive('getAuditUse')->andReturn(['my-local']);
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
    $config->shouldReceive('getAuditUse')->andReturn([]);
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
    $config->shouldReceive('getAuditUse')->andReturn([]);
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
    $config->shouldReceive('getAuditUse')->andReturn([]);
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
    $config->shouldReceive('getAuditUse')->andReturn([]);
    $this->app->instance(ConfigService::class, $config);

    $this->artisan('audit:list')
        ->expectsOutputToContain('my-ntfy')
        ->assertExitCode(0);
});

it('lists an s3 channel with only a bucket (no path prefix)', function (): void {
    $config = Mockery::mock(ConfigService::class);
    $config->shouldReceive('getAuditChannels')->andReturn([
        'bucket-only' => [
            'type' => 's3',
            'bucket' => 'my-bucket',
        ],
    ]);
    $config->shouldReceive('getAuditUse')->andReturn([]);
    $this->app->instance(ConfigService::class, $config);

    $this->artisan('audit:list')
        ->expectsOutputToContain('bucket-only')
        ->assertExitCode(0);
});

it('lists a stack channel showing its child channels', function (): void {
    $config = Mockery::mock(ConfigService::class);
    $config->shouldReceive('getAuditChannels')->andReturn([
        'my-stack' => [
            'type' => 'stack',
            'channels' => ['a', 'b'],
        ],
    ]);
    $config->shouldReceive('getAuditUse')->andReturn([]);
    $this->app->instance(ConfigService::class, $config);

    $this->artisan('audit:list')
        ->expectsOutputToContain('my-stack')
        ->assertExitCode(0);
});

it('lists a channel with no detail rendering (stdout)', function (): void {
    $config = Mockery::mock(ConfigService::class);
    $config->shouldReceive('getAuditChannels')->andReturn([
        'my-stdout' => [
            'type' => 'stdout',
        ],
    ]);
    $config->shouldReceive('getAuditUse')->andReturn([]);
    $this->app->instance(ConfigService::class, $config);

    $this->artisan('audit:list')
        ->expectsOutputToContain('my-stdout')
        ->assertExitCode(0);
});
