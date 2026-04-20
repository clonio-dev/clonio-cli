<?php

declare(strict_types=1);

use App\Services\Config\ConfigService;

it('errors when no channels are configured', function (): void {
    $config = Mockery::mock(ConfigService::class);
    $config->shouldReceive('getAuditChannels')->andReturn([]);
    $this->app->instance(ConfigService::class, $config);

    $this->artisan('audit:delete')
        ->expectsOutputToContain('No audit channels configured')
        ->assertExitCode(2);
});

it('errors when the given channel name is not found', function (): void {
    $config = Mockery::mock(ConfigService::class);
    $config->shouldReceive('getAuditChannels')->andReturn(['other' => ['type' => 'local']]);
    $config->shouldReceive('getAuditChannel')->with('missing')->andReturn(null);
    $this->app->instance(ConfigService::class, $config);

    $this->artisan('audit:delete', ['name' => 'missing'])
        ->expectsOutputToContain("Channel 'missing' not found")
        ->assertExitCode(4);
});

it('deletes channel after user confirms', function (): void {
    $channel = ['type' => 'local', 'path' => './clonio-logs'];

    $config = Mockery::mock(ConfigService::class);
    $config->shouldReceive('getAuditChannels')->andReturn(['my-local' => $channel]);
    $config->shouldReceive('getAuditChannel')->with('my-local')->andReturn($channel);
    $config->shouldReceive('getAuditDefault')->andReturn(null);
    $config->shouldReceive('deleteAuditChannel')->with('my-local')->once();
    $this->app->instance(ConfigService::class, $config);

    $this->artisan('audit:delete', ['name' => 'my-local'])
        ->expectsConfirmation("Delete channel 'my-local'? This cannot be undone.", 'yes')
        ->expectsOutputToContain("Channel 'my-local' deleted.")
        ->assertExitCode(0);
});

it('cancels when user declines confirmation', function (): void {
    $channel = ['type' => 'local'];

    $config = Mockery::mock(ConfigService::class);
    $config->shouldReceive('getAuditChannels')->andReturn(['my-local' => $channel]);
    $config->shouldReceive('getAuditChannel')->with('my-local')->andReturn($channel);
    $config->shouldReceive('getAuditDefault')->andReturn(null);
    $config->shouldReceive('deleteAuditChannel')->never();
    $this->app->instance(ConfigService::class, $config);

    $this->artisan('audit:delete', ['name' => 'my-local'])
        ->expectsConfirmation("Delete channel 'my-local'? This cannot be undone.", 'no')
        ->expectsOutputToContain('Cancelled.')
        ->assertExitCode(0);
});

it('deletes immediately with --force flag', function (): void {
    $channel = ['type' => 's3', 'bucket' => 'my-bucket'];

    $config = Mockery::mock(ConfigService::class);
    $config->shouldReceive('getAuditChannels')->andReturn(['my-s3' => $channel]);
    $config->shouldReceive('getAuditChannel')->with('my-s3')->andReturn($channel);
    $config->shouldReceive('getAuditDefault')->andReturn(null);
    $config->shouldReceive('deleteAuditChannel')->with('my-s3')->once();
    $this->app->instance(ConfigService::class, $config);

    $this->artisan('audit:delete', ['name' => 'my-s3', '--force' => true])
        ->expectsOutputToContain("Channel 'my-s3' deleted.")
        ->assertExitCode(0);
});

it('shows default channel warning when deleting the default', function (): void {
    $channel = ['type' => 'local'];

    $config = Mockery::mock(ConfigService::class);
    $config->shouldReceive('getAuditChannels')->andReturn(['my-local' => $channel]);
    $config->shouldReceive('getAuditChannel')->with('my-local')->andReturn($channel);
    $config->shouldReceive('getAuditDefault')->andReturn('my-local');
    $config->shouldReceive('deleteAuditChannel')->with('my-local')->once();
    $this->app->instance(ConfigService::class, $config);

    $this->artisan('audit:delete', ['name' => 'my-local', '--force' => true])
        ->expectsOutputToContain('is the current default')
        ->assertExitCode(0);
});

it('auto-selects the only channel when no name is given', function (): void {
    $channel = ['type' => 'local'];

    $config = Mockery::mock(ConfigService::class);
    $config->shouldReceive('getAuditChannels')->andReturn(['only-channel' => $channel]);
    $config->shouldReceive('getAuditChannel')->with('only-channel')->andReturn($channel);
    $config->shouldReceive('getAuditDefault')->andReturn(null);
    $config->shouldReceive('deleteAuditChannel')->with('only-channel')->once();
    $this->app->instance(ConfigService::class, $config);

    $this->artisan('audit:delete', ['--force' => true])
        ->expectsOutputToContain("Channel 'only-channel' deleted.")
        ->assertExitCode(0);
});
