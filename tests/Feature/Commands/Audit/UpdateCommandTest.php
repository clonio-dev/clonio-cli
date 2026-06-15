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

it('prompts which channel to update when several exist', function (): void {
    $local = ['type' => 'local', 'path' => './clonio-logs'];
    $slack = ['type' => 'slack', 'webhook_url' => 'encrypted:x'];

    $config = Mockery::mock(ConfigService::class);
    $config->shouldReceive('getAuditChannels')->andReturn([
        'my-local' => $local,
        'my-slack' => $slack,
    ]);
    $config->shouldReceive('getAuditChannel')->with('my-local')->andReturn($local);
    $config->shouldReceive('setAuditChannel')->with('my-local', Mockery::type('array'))->once();
    $this->app->instance(ConfigService::class, $config);

    $this->artisan('audit:update')
        ->expectsChoice('Which channel do you want to update?', 'my-local', ['my-local', 'my-slack'])
        ->expectsQuestion('Log path', './clonio-logs')
        ->expectsConfirmation('Save changes?', 'yes')
        ->expectsOutputToContain("Channel 'my-local' updated successfully.")
        ->assertExitCode(0);
});

it('reports an IO error when saving the update fails', function (): void {
    $channel = ['type' => 'local', 'path' => './clonio-logs'];

    $config = Mockery::mock(ConfigService::class);
    $config->shouldReceive('getAuditChannels')->andReturn(['my-local' => $channel]);
    $config->shouldReceive('getAuditChannel')->with('my-local')->andReturn($channel);
    $config->shouldReceive('setAuditChannel')->andThrow(new RuntimeException('write failed'));
    $this->app->instance(ConfigService::class, $config);

    $this->artisan('audit:update', ['name' => 'my-local'])
        ->expectsQuestion('Log path', './clonio-logs/{year}')
        ->expectsConfirmation('Save changes?', 'yes')
        ->expectsOutputToContain('write failed')
        ->assertExitCode(5);
});

it('shows a diff and changes a field on update', function (): void {
    $channel = ['type' => 'local', 'path' => './old-logs'];

    $config = Mockery::mock(ConfigService::class);
    $config->shouldReceive('getAuditChannels')->andReturn(['my-local' => $channel]);
    $config->shouldReceive('getAuditChannel')->with('my-local')->andReturn($channel);
    $config->shouldReceive('setAuditChannel')->with('my-local', Mockery::type('array'))->once();
    $this->app->instance(ConfigService::class, $config);

    $this->artisan('audit:update', ['name' => 'my-local'])
        ->expectsQuestion('Log path', './new-logs')
        ->expectsConfirmation('Save changes?', 'yes')
        ->expectsOutputToContain("Channel 'my-local' updated successfully.")
        ->assertExitCode(0);
});

it('updates an s3 channel preserving retry settings and replacing the secret', function (): void {
    $channel = [
        'type' => 's3',
        'endpoint' => 'https://s3.example.com',
        'bucket' => 'my-bucket',
        'region' => 'us-east-1',
        'access_key' => 'AKIA123',
        'secret_key' => 'encrypted:old',
        'path_prefix' => 'clonio/{year}/',
        'retry' => ['max_attempts' => 5],
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
        ->expectsQuestion('Secret key (press Enter to keep)', 'new-secret')
        ->expectsQuestion('Path prefix', 'clonio/{year}/')
        ->expectsConfirmation('Save changes?', 'yes')
        ->expectsOutputToContain("Channel 'my-s3' updated successfully.")
        ->assertExitCode(0);
});

it('updates an email channel with pre-filled values and preserved retry', function (): void {
    $channel = [
        'type' => 'email',
        'host' => 'smtp.example.com',
        'port' => 587,
        'encryption' => 'tls',
        'username' => 'user@example.com',
        'password' => 'encrypted:old',
        'from_address' => 'noreply@example.com',
        'from_name' => 'Clonio',
        'to' => ['admin@example.com', 'ops@example.com'],
        'retry' => ['max_attempts' => 3],
    ];

    $config = Mockery::mock(ConfigService::class);
    $config->shouldReceive('getAuditChannels')->andReturn(['my-email' => $channel]);
    $config->shouldReceive('getAuditChannel')->with('my-email')->andReturn($channel);
    $config->shouldReceive('setAuditChannel')->with('my-email', Mockery::type('array'))->once();
    $this->app->instance(ConfigService::class, $config);

    $this->artisan('audit:update', ['name' => 'my-email'])
        ->expectsQuestion('SMTP host', 'smtp.example.com')
        ->expectsQuestion('SMTP port', '587')
        ->expectsChoice('Encryption', 'tls', ['tls', 'ssl', 'none'])
        ->expectsQuestion('Username', 'user@example.com')
        ->expectsQuestion('Password (press Enter to keep)', '')
        ->expectsQuestion('From address', 'noreply@example.com')
        ->expectsQuestion('From name', 'Clonio')
        ->expectsQuestion('Recipients (comma-separated)', 'admin@example.com, ops@example.com')
        ->expectsConfirmation('Save changes?', 'yes')
        ->expectsOutputToContain("Channel 'my-email' updated successfully.")
        ->assertExitCode(0);
});

it('updates a webhook channel preserving retry settings', function (): void {
    $channel = [
        'type' => 'ms_teams',
        'webhook_url' => 'encrypted:old',
        'retry' => ['max_attempts' => 3],
    ];

    $config = Mockery::mock(ConfigService::class);
    $config->shouldReceive('getAuditChannels')->andReturn(['my-teams' => $channel]);
    $config->shouldReceive('getAuditChannel')->with('my-teams')->andReturn($channel);
    $config->shouldReceive('setAuditChannel')->with('my-teams', Mockery::type('array'))->once();
    $this->app->instance(ConfigService::class, $config);

    $this->artisan('audit:update', ['name' => 'my-teams'])
        ->expectsQuestion('Webhook URL (press Enter to keep)', 'https://new-webhook')
        ->expectsConfirmation('Save changes?', 'yes')
        ->expectsOutputToContain("Channel 'my-teams' updated successfully.")
        ->assertExitCode(0);
});

it('updates an ntfy channel with pre-filled values, tags, token and retry', function (): void {
    $channel = [
        'type' => 'ntfy',
        'url' => 'https://ntfy.sh',
        'topic' => 'my-topic',
        'priority' => 'high',
        'tags' => ['alert', 'ops'],
        'token' => 'encrypted:old',
        'retry' => ['max_attempts' => 3],
    ];

    $config = Mockery::mock(ConfigService::class);
    $config->shouldReceive('getAuditChannels')->andReturn(['my-ntfy' => $channel]);
    $config->shouldReceive('getAuditChannel')->with('my-ntfy')->andReturn($channel);
    $config->shouldReceive('setAuditChannel')->with('my-ntfy', Mockery::type('array'))->once();
    $this->app->instance(ConfigService::class, $config);

    $this->artisan('audit:update', ['name' => 'my-ntfy'])
        ->expectsQuestion('Server URL', 'https://ntfy.sh')
        ->expectsQuestion('Topic', 'my-topic')
        ->expectsChoice('Priority', 'high', ['min', 'low', 'default', 'high', 'max'])
        ->expectsQuestion('Tags (comma-separated, optional)', 'alert, ops')
        ->expectsQuestion('Bearer token (optional — press Enter to keep/skip) (press Enter to keep)', 'new-token')
        ->expectsConfirmation('Save changes?', 'yes')
        ->expectsOutputToContain("Channel 'my-ntfy' updated successfully.")
        ->assertExitCode(0);
});

it('updates a stack channel with new channel list', function (): void {
    $channel = [
        'type' => 'stack',
        'channels' => ['a', 'b'],
    ];

    $config = Mockery::mock(ConfigService::class);
    $config->shouldReceive('getAuditChannels')->andReturn(['my-stack' => $channel]);
    $config->shouldReceive('getAuditChannel')->with('my-stack')->andReturn($channel);
    $config->shouldReceive('setAuditChannel')->with('my-stack', Mockery::type('array'))->once();
    $this->app->instance(ConfigService::class, $config);

    $this->artisan('audit:update', ['name' => 'my-stack'])
        ->expectsQuestion('Channels (comma-separated)', 'a, b, c')
        ->expectsConfirmation('Save changes?', 'yes')
        ->expectsOutputToContain("Channel 'my-stack' updated successfully.")
        ->assertExitCode(0);
});
