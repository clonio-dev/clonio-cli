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

it('does not render a table when no channels are configured', function (): void {
    $config = Mockery::mock(ConfigService::class);
    $config->shouldReceive('getAuditChannels')->andReturn([]);
    $config->shouldReceive('getAuditUse')->andReturn([]);
    $this->app->instance(ConfigService::class, $config);

    $this->artisan('audit:list')
        ->expectsOutput('No audit channels configured. Run `audit:add` to add one.')
        ->doesntExpectOutputToContain('Details')
        ->assertExitCode(0);
});

it('renders the exact details for every channel type', function (): void {
    $config = Mockery::mock(ConfigService::class);
    $config->shouldReceive('getAuditChannels')->andReturn([
        'local-path' => ['type' => 'local', 'path' => './logs/{year}'],
        'local-none' => ['type' => 'local'],
        'local-int' => ['type' => 'local', 'path' => 42],
        's3-full' => ['type' => 's3', 'bucket' => 'bkt', 'path_prefix' => '/clonio/x/'],
        's3-bucket' => ['type' => 's3', 'bucket' => 'only-bkt'],
        's3-prefix' => ['type' => 's3', 'path_prefix' => 'p/'],
        's3-int' => ['type' => 's3', 'bucket' => 5, 'path_prefix' => 6],
        'mail' => ['type' => 'email', 'to' => ['a@x.io', 'b@x.io']],
        'mail-empty' => ['type' => 'email', 'to' => []],
        'mail-none' => ['type' => 'email'],
        'mail-str' => ['type' => 'email', 'to' => 'c@x.io'],
        'mail-int' => ['type' => 'email', 'to' => [7]],
        'teams' => ['type' => 'ms_teams'],
        'slk' => ['type' => 'slack'],
        'nt' => ['type' => 'ntfy', 'url' => 'https://ntfy.sh/', 'topic' => 'tp'],
        'nt-no-topic' => ['type' => 'ntfy', 'url' => 'https://ntfy.sh'],
        'nt-no-url' => ['type' => 'ntfy', 'topic' => 'tp'],
        'nt-int' => ['type' => 'ntfy', 'url' => 1, 'topic' => 2],
        'stk' => ['type' => 'stack', 'channels' => ['a', 3, 'b']],
        'stk-none' => ['type' => 'stack'],
        'stk-str' => ['type' => 'stack', 'channels' => 'a'],
        'out' => ['type' => 'stdout'],
        'weird' => ['type' => 'carrier-pigeon'],
        'untyped' => [],
        'int-typed' => ['type' => 9],
    ]);
    $config->shouldReceive('getAuditUse')->andReturn(['local-path', 'nt']);
    $this->app->instance(ConfigService::class, $config);

    $this->artisan('audit:list')
        ->expectsTable(['Name', 'Type', 'Active', 'Details'], [
            ['local-path', 'Local filesystem', '★', './logs/{year}'],
            ['local-none', 'Local filesystem', '', '—'],
            ['local-int', 'Local filesystem', '', '—'],
            ['s3-full', 'S3-compatible storage', '', 's3://bkt/clonio/x/'],
            ['s3-bucket', 'S3-compatible storage', '', 's3://only-bkt'],
            ['s3-prefix', 'S3-compatible storage', '', '—'],
            ['s3-int', 'S3-compatible storage', '', '—'],
            ['mail', 'Email (SMTP)', '', 'a@x.io'],
            ['mail-empty', 'Email (SMTP)', '', '—'],
            ['mail-none', 'Email (SMTP)', '', '—'],
            ['mail-str', 'Email (SMTP)', '', '—'],
            ['mail-int', 'Email (SMTP)', '', '—'],
            ['teams', 'Microsoft Teams', '', '(webhook — encrypted)'],
            ['slk', 'Slack', '', '(webhook — encrypted)'],
            ['nt', 'ntfy.sh / self-hosted ntfy', '★', 'https://ntfy.sh / tp'],
            ['nt-no-topic', 'ntfy.sh / self-hosted ntfy', '', '—'],
            ['nt-no-url', 'ntfy.sh / self-hosted ntfy', '', '—'],
            ['nt-int', 'ntfy.sh / self-hosted ntfy', '', '—'],
            ['stk', 'Stack (fan-out to multiple channels)', '', 'a, b'],
            ['stk-none', 'Stack (fan-out to multiple channels)', '', ''],
            ['stk-str', 'Stack (fan-out to multiple channels)', '', ''],
            ['out', 'Standard output', '', '—'],
            ['weird', 'carrier-pigeon', '', '—'],
            ['untyped', '', '', '—'],
            ['int-typed', '', '', '—'],
        ])
        ->assertExitCode(0);
});
