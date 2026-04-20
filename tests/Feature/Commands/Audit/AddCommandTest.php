<?php

declare(strict_types=1);

use App\Services\Config\ConfigService;

beforeEach(function (): void {
    config(['app.key' => 'base64:ROzyPViGEkER6n3g0OHblde5CygEIcuDlAFbca99xvM=']);
});

it('fails when clonio.json does not exist', function (): void {
    $config = Mockery::mock(ConfigService::class);
    $config->shouldReceive('exists')->andReturn(false);
    $this->app->instance(ConfigService::class, $config);

    $this->artisan('audit:add', ['name' => 'my-channel', '--type' => 'local'])
        ->expectsOutputToContain('clonio.json not found')
        ->assertExitCode(2);
});

it('fails with invalid channel name', function (): void {
    $config = Mockery::mock(ConfigService::class);
    $config->shouldReceive('exists')->andReturn(true);
    $this->app->instance(ConfigService::class, $config);

    $this->artisan('audit:add', ['name' => 'Invalid Name!', '--type' => 'local'])
        ->expectsOutputToContain('Invalid channel name')
        ->assertExitCode(4);
});

it('fails when channel name already exists', function (): void {
    $config = Mockery::mock(ConfigService::class);
    $config->shouldReceive('exists')->andReturn(true);
    $config->shouldReceive('hasAuditChannel')->with('existing')->andReturn(true);
    $this->app->instance(ConfigService::class, $config);

    $this->artisan('audit:add', ['name' => 'existing', '--type' => 'local'])
        ->expectsOutputToContain("A channel named 'existing' already exists")
        ->assertExitCode(4);
});

it('fails with unknown channel type', function (): void {
    $config = Mockery::mock(ConfigService::class);
    $config->shouldReceive('exists')->andReturn(true);
    $config->shouldReceive('hasAuditChannel')->andReturn(false);
    $this->app->instance(ConfigService::class, $config);

    $this->artisan('audit:add', ['name' => 'my-channel', '--type' => 'ftp'])
        ->expectsOutputToContain('Unknown channel type')
        ->assertExitCode(4);
});

it('successfully adds a local channel with all options', function (): void {
    $config = Mockery::mock(ConfigService::class);
    $config->shouldReceive('exists')->andReturn(true);
    $config->shouldReceive('hasAuditChannel')->with('my-local')->andReturn(false);
    $config->shouldReceive('setAuditChannel')->with('my-local', Mockery::type('array'))->once();
    $config->shouldReceive('setAuditDefault')->with('my-local')->once();
    $this->app->instance(ConfigService::class, $config);

    $this->artisan('audit:add', [
        'name' => 'my-local',
        '--type' => 'local',
        '--local-path' => './clonio-logs/{year}',
        '--set-default' => true,
    ])
        ->expectsConfirmation('Save this channel?', 'yes')
        ->expectsOutputToContain("Channel 'my-local' added successfully.")
        ->assertExitCode(0);
});

it('cancels when the user declines the save confirmation', function (): void {
    $config = Mockery::mock(ConfigService::class);
    $config->shouldReceive('exists')->andReturn(true);
    $config->shouldReceive('hasAuditChannel')->with('my-local')->andReturn(false);
    $config->shouldNotReceive('setAuditChannel');
    $this->app->instance(ConfigService::class, $config);

    $this->artisan('audit:add', [
        'name' => 'my-local',
        '--type' => 'local',
        '--local-path' => './clonio-logs/{year}',
    ])
        ->expectsConfirmation('Save this channel?', 'no')
        ->expectsOutputToContain('Cancelled.')
        ->assertExitCode(0);
});

it('successfully adds an ntfy channel with token via flag', function (): void {
    $config = Mockery::mock(ConfigService::class);
    $config->shouldReceive('exists')->andReturn(true);
    $config->shouldReceive('hasAuditChannel')->with('my-ntfy')->andReturn(false);
    $config->shouldReceive('setAuditChannel')->with('my-ntfy', Mockery::type('array'))->once();
    $this->app->instance(ConfigService::class, $config);

    // Provide --tags and --token via flags to avoid secret()/ask() interactive prompts
    $this->artisan('audit:add', [
        'name' => 'my-ntfy',
        '--type' => 'ntfy',
        '--ntfy-url' => 'https://ntfy.sh',
        '--ntfy-topic' => 'my-alerts',
        '--ntfy-priority' => 'default',
        '--ntfy-tags' => 'alert,ops',
        '--ntfy-token' => 'my-bearer-token',
    ])
        ->expectsConfirmation('Override retry settings?', 'no')
        ->expectsConfirmation('Save this channel?', 'yes')
        ->expectsOutputToContain("Channel 'my-ntfy' added successfully.")
        ->assertExitCode(0);
});

it('successfully adds a ms_teams channel via webhook flag', function (): void {
    $config = Mockery::mock(ConfigService::class);
    $config->shouldReceive('exists')->andReturn(true);
    $config->shouldReceive('hasAuditChannel')->with('my-teams')->andReturn(false);
    $config->shouldReceive('setAuditChannel')->with('my-teams', Mockery::type('array'))->once();
    $this->app->instance(ConfigService::class, $config);

    $this->artisan('audit:add', [
        'name' => 'my-teams',
        '--type' => 'ms_teams',
        '--webhook-url' => 'https://outlook.office.com/webhook/test',
    ])
        ->expectsConfirmation('Override retry settings?', 'no')
        ->expectsConfirmation('Save this channel?', 'yes')
        ->expectsOutputToContain("Channel 'my-teams' added successfully.")
        ->assertExitCode(0);
});

it('successfully adds an email channel with all options via flags', function (): void {
    $config = Mockery::mock(ConfigService::class);
    $config->shouldReceive('exists')->andReturn(true);
    $config->shouldReceive('hasAuditChannel')->with('my-email')->andReturn(false);
    $config->shouldReceive('setAuditChannel')->with('my-email', Mockery::type('array'))->once();
    $this->app->instance(ConfigService::class, $config);

    $this->artisan('audit:add', [
        'name' => 'my-email',
        '--type' => 'email',
        '--mail-host' => 'smtp.example.com',
        '--mail-port' => '587',
        '--mail-encryption' => 'tls',
        '--mail-username' => 'user@example.com',
        '--mail-password' => 'my-smtp-password',
        '--mail-from-address' => 'noreply@example.com',
        '--mail-from-name' => 'Clonio',
        '--mail-to' => 'admin@example.com',
    ])
        ->expectsConfirmation('Override retry settings?', 'no')
        ->expectsConfirmation('Save this channel?', 'yes')
        ->expectsOutputToContain("Channel 'my-email' added successfully.")
        ->assertExitCode(0);
});

it('successfully adds a slack channel via webhook flag', function (): void {
    $config = Mockery::mock(ConfigService::class);
    $config->shouldReceive('exists')->andReturn(true);
    $config->shouldReceive('hasAuditChannel')->with('my-slack')->andReturn(false);
    $config->shouldReceive('setAuditChannel')->with('my-slack', Mockery::type('array'))->once();
    $this->app->instance(ConfigService::class, $config);

    $this->artisan('audit:add', [
        'name' => 'my-slack',
        '--type' => 'slack',
        '--webhook-url' => 'https://hooks.slack.com/services/T00/B00/abc123',
    ])
        ->expectsConfirmation('Override retry settings?', 'no')
        ->expectsConfirmation('Save this channel?', 'yes')
        ->expectsOutputToContain("Channel 'my-slack' added successfully.")
        ->assertExitCode(0);
});

it('successfully adds an s3 channel with all options via flags', function (): void {
    $config = Mockery::mock(ConfigService::class);
    $config->shouldReceive('exists')->andReturn(true);
    $config->shouldReceive('hasAuditChannel')->with('my-s3')->andReturn(false);
    $config->shouldReceive('setAuditChannel')->with('my-s3', Mockery::type('array'))->once();
    $config->shouldReceive('setAuditDefault')->with('my-s3')->once();
    $this->app->instance(ConfigService::class, $config);

    $this->artisan('audit:add', [
        'name' => 'my-s3',
        '--type' => 's3',
        '--s3-endpoint' => 'https://s3.example.com',
        '--s3-bucket' => 'my-bucket',
        '--s3-region' => 'us-east-1',
        '--s3-access-key' => 'AKIA123',
        '--s3-secret-key' => 'my-secret',
        '--s3-path-prefix' => 'clonio/logs/',
        '--set-default' => true,
    ])
        ->expectsConfirmation('Override retry settings?', 'no')
        ->expectsConfirmation('Save this channel?', 'yes')
        ->expectsOutputToContain("Channel 'my-s3' added successfully.")
        ->assertExitCode(0);
});
