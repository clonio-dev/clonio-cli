<?php

declare(strict_types=1);

use App\Services\Config\ConfigService;

beforeEach(function (): void {
    config(['app.key' => 'base64:ROzyPViGEkER6n3g0OHblde5CygEIcuDlAFbca99xvM=']);
});

it('errors when no channels are configured', function (): void {
    $config = Mockery::mock(ConfigService::class);
    $config->shouldReceive('getAuditChannels')->andReturn([]);
    $this->app->instance(ConfigService::class, $config);

    $this->artisan('audit:update')
        ->expectsOutputToContain('No audit channels configured')
        ->assertExitCode(2);
});

it('errors when the given channel name is not found', function (): void {
    $config = Mockery::mock(ConfigService::class);
    $config->shouldReceive('getAuditChannels')->andReturn(['other' => ['type' => 'local']]);
    $config->shouldReceive('getAuditChannel')->with('missing')->andReturn(null);
    $this->app->instance(ConfigService::class, $config);

    $this->artisan('audit:update', ['name' => 'missing'])
        ->expectsOutputToContain("Channel 'missing' not found")
        ->assertExitCode(4);
});

it('errors when channel type is unknown', function (): void {
    $config = Mockery::mock(ConfigService::class);
    $config->shouldReceive('getAuditChannels')->andReturn(['broken' => ['type' => 'invalid']]);
    $config->shouldReceive('getAuditChannel')->with('broken')->andReturn(['type' => 'invalid']);
    $this->app->instance(ConfigService::class, $config);

    $this->artisan('audit:update', ['name' => 'broken'])
        ->expectsOutputToContain('unknown type')
        ->assertExitCode(2);
});

it('cancels when the user declines saving changes', function (): void {
    $channel = [
        'type' => 'local',
        'path' => './clonio-logs',
    ];

    $config = Mockery::mock(ConfigService::class);
    $config->shouldReceive('getAuditChannels')->andReturn(['my-local' => $channel]);
    $config->shouldReceive('getAuditChannel')->with('my-local')->andReturn($channel);
    $config->shouldNotReceive('setAuditChannel');
    $this->app->instance(ConfigService::class, $config);

    $this->artisan('audit:update', ['name' => 'my-local'])
        ->expectsQuestion('Log path', './clonio-logs')
        ->expectsConfirmation('Save changes?', 'no')
        ->expectsOutputToContain('Cancelled.')
        ->assertExitCode(0);
});

it('auto-selects the only channel when no name is given', function (): void {
    $channel = [
        'type' => 'local',
        'path' => './clonio-logs',
    ];

    $config = Mockery::mock(ConfigService::class);
    $config->shouldReceive('getAuditChannels')->andReturn(['only-one' => $channel]);
    $config->shouldReceive('getAuditChannel')->with('only-one')->andReturn($channel);
    $config->shouldReceive('setAuditChannel')->once();
    $this->app->instance(ConfigService::class, $config);

    $this->artisan('audit:update')
        ->expectsQuestion('Log path', './clonio-logs')
        ->expectsConfirmation('Save changes?', 'yes')
        ->expectsOutputToContain("Channel 'only-one' updated successfully.")
        ->assertExitCode(0);
});

it('updates an s3 channel with pre-filled values', function (): void {
    $channel = [
        'type' => 's3',
        'endpoint' => 'https://s3.example.com',
        'bucket' => 'my-bucket',
        'region' => 'us-east-1',
        'access_key' => 'AKIA123',
        'secret_key' => 'encrypted:abc',
        'path_prefix' => 'clonio/{year}/',
    ];

    $config = Mockery::mock(ConfigService::class);
    $config->shouldReceive('getAuditChannels')->andReturn(['my-s3' => $channel]);
    $config->shouldReceive('getAuditChannel')->with('my-s3')->andReturn($channel);
    $config->shouldReceive('setAuditChannel')->with('my-s3', Mockery::type('array'))->once();
    $this->app->instance(ConfigService::class, $config);

    $this->artisan('audit:update', ['name' => 'my-s3'])
        ->expectsQuestion('Endpoint', 'https://s3.example.com')
        ->expectsQuestion('Bucket', 'my-bucket')
        ->expectsQuestion('Region', 'us-east-1')
        ->expectsQuestion('Access key', 'AKIA123')
        ->expectsQuestion('Secret key (press Enter to keep)', '')
        ->expectsQuestion('Path prefix', 'clonio/{year}/')
        ->expectsConfirmation('Save changes?', 'yes')
        ->expectsOutputToContain("Channel 'my-s3' updated successfully.")
        ->assertExitCode(0);
});

it('updates a webhook channel (slack) with pre-filled values', function (): void {
    $channel = [
        'type' => 'slack',
        'webhook_url' => 'encrypted:old-webhook',
    ];

    $config = Mockery::mock(ConfigService::class);
    $config->shouldReceive('getAuditChannels')->andReturn(['my-slack' => $channel]);
    $config->shouldReceive('getAuditChannel')->with('my-slack')->andReturn($channel);
    $config->shouldReceive('setAuditChannel')->with('my-slack', Mockery::type('array'))->once();
    $this->app->instance(ConfigService::class, $config);

    $this->artisan('audit:update', ['name' => 'my-slack'])
        ->expectsQuestion('Webhook URL (press Enter to keep)', '')
        ->expectsConfirmation('Save changes?', 'yes')
        ->expectsOutputToContain("Channel 'my-slack' updated successfully.")
        ->assertExitCode(0);
});
