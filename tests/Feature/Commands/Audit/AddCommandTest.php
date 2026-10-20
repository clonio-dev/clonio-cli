<?php

declare(strict_types=1);

use App\Services\Config\ConfigService;
use Illuminate\Console\OutputStyle;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Crypt;
use Mockery\MockInterface;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Question\ChoiceQuestion;
use Symfony\Component\Console\Question\Question;

if (! function_exists('g2_audit_run')) {
    /**
     * Run a command through a recording OutputStyle. Questions listed in $answers
     * receive that answer (a list answers repeated questions in order); any other
     * question receives its default, exactly like a non-interactive terminal would.
     *
     * @param  array<string, mixed>  $params
     * @param  array<string, mixed>  $answers
     * @return object{exit: int, output: string, asked: list<array<string, mixed>>}
     */
    function g2_audit_run(string $command, array $params = [], array $answers = []): object
    {
        $buffer = new BufferedOutput;
        $style = new class(new ArrayInput([]), $buffer, $answers) extends OutputStyle
        {
            /** @var list<array<string, mixed>> */
            public array $asked = [];

            /**
             * @param  array<string, mixed>  $answers
             */
            public function __construct(ArrayInput $input, BufferedOutput $output, private array $answers)
            {
                parent::__construct($input, $output);
            }

            public function askQuestion(Question $question): mixed
            {
                $text = $question->getQuestion();
                $entry = [
                    'question' => $text,
                    'default' => $question->getDefault(),
                    'hidden' => $question->isHidden(),
                ];

                if ($question instanceof ChoiceQuestion) {
                    $entry['choices'] = $question->getChoices();
                }

                $this->asked[] = $entry;

                if (array_key_exists($text, $this->answers)) {
                    $answer = $this->answers[$text];

                    if (is_array($answer)) {
                        $answer = array_shift($this->answers[$text]);
                    }

                    return $answer;
                }

                $default = $question->getDefault();

                if ($question instanceof ChoiceQuestion && $default !== null) {
                    return $question->getChoices()[$default] ?? $default;
                }

                return $default;
            }
        };

        $exit = Artisan::call($command, $params, $style);

        return (object) ['exit' => $exit, 'output' => $buffer->fetch(), 'asked' => $style->asked];
    }
}

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
    $config->shouldReceive('addAuditUse')->with('my-local')->once();
    $this->app->instance(ConfigService::class, $config);

    $this->artisan('audit:add', [
        'name' => 'my-local',
        '--type' => 'local',
        '--local-path' => './clonio-logs/{year}',
        '--enable' => true,
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
    $config->shouldReceive('addAuditUse')->with('my-s3')->once();
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
        '--enable' => true,
    ])
        ->expectsConfirmation('Override retry settings?', 'no')
        ->expectsConfirmation('Save this channel?', 'yes')
        ->expectsOutputToContain("Channel 'my-s3' added successfully.")
        ->assertExitCode(0);
});

it('prompts interactively for name and type when not given', function (): void {
    $config = Mockery::mock(ConfigService::class);
    $config->shouldReceive('exists')->andReturn(true);
    $config->shouldReceive('hasAuditChannel')->with('interactive')->andReturn(false);
    $config->shouldReceive('setAuditChannel')->with('interactive', Mockery::type('array'))->once();
    $this->app->instance(ConfigService::class, $config);

    $this->artisan('audit:add')
        ->expectsQuestion('Channel name', 'interactive')
        ->expectsChoice('Channel type', 'Local filesystem', [
            'Local filesystem',
            'S3-compatible storage',
            'Email (SMTP)',
            'Microsoft Teams',
            'Slack',
            'ntfy.sh / self-hosted ntfy',
            'Standard output',
            'Standard error',
            'Stack (fan-out to multiple channels)',
        ])
        ->expectsQuestion('Log path (Supports: {year} {month} {day} {source} {target} {timestamp})', './clonio-logs')
        ->expectsConfirmation('Save this channel?', 'yes')
        ->expectsOutputToContain("Channel 'interactive' added successfully.")
        ->assertExitCode(0);
});

it('fails to add a stack channel when no channels exist yet', function (): void {
    $config = Mockery::mock(ConfigService::class);
    $config->shouldReceive('exists')->andReturn(true);
    $config->shouldReceive('hasAuditChannel')->with('my-stack')->andReturn(false);
    $config->shouldReceive('getAuditChannels')->andReturn([]);
    $config->shouldNotReceive('setAuditChannel');
    $this->app->instance(ConfigService::class, $config);

    $this->artisan('audit:add', ['name' => 'my-stack', '--type' => 'stack'])
        ->expectsOutputToContain('No channels exist yet')
        ->assertExitCode(2);
});

it('successfully adds a stack channel referencing existing channels', function (): void {
    $config = Mockery::mock(ConfigService::class);
    $config->shouldReceive('exists')->andReturn(true);
    $config->shouldReceive('hasAuditChannel')->with('my-stack')->andReturn(false);
    $config->shouldReceive('getAuditChannels')->andReturn([
        'a' => ['type' => 'local'],
        'b' => ['type' => 's3'],
    ]);
    $config->shouldReceive('setAuditChannel')->with('my-stack', Mockery::type('array'))->once();
    $this->app->instance(ConfigService::class, $config);

    $this->artisan('audit:add', ['name' => 'my-stack', '--type' => 'stack'])
        ->expectsQuestion('Channels to include (comma-separated)', 'a, b')
        ->expectsConfirmation('Save this channel?', 'yes')
        ->expectsOutputToContain("Channel 'my-stack' added successfully.")
        ->assertExitCode(0);
});

it('reports an IO error when saving the channel fails', function (): void {
    $config = Mockery::mock(ConfigService::class);
    $config->shouldReceive('exists')->andReturn(true);
    $config->shouldReceive('hasAuditChannel')->with('my-local')->andReturn(false);
    $config->shouldReceive('setAuditChannel')->andThrow(new RuntimeException('disk full'));
    $this->app->instance(ConfigService::class, $config);

    $this->artisan('audit:add', [
        'name' => 'my-local',
        '--type' => 'local',
        '--local-path' => './clonio-logs/{year}',
    ])
        ->expectsConfirmation('Save this channel?', 'yes')
        ->expectsOutputToContain('Failed to save channel: disk full')
        ->assertExitCode(5);
});

it('prompts interactively for s3 secret and retry settings', function (): void {
    $config = Mockery::mock(ConfigService::class);
    $config->shouldReceive('exists')->andReturn(true);
    $config->shouldReceive('hasAuditChannel')->with('my-s3')->andReturn(false);
    $config->shouldReceive('setAuditChannel')->with('my-s3', Mockery::type('array'))->once();
    $this->app->instance(ConfigService::class, $config);

    $this->artisan('audit:add', [
        'name' => 'my-s3',
        '--type' => 's3',
        '--s3-endpoint' => 'https://s3.example.com',
        '--s3-bucket' => 'my-bucket',
        '--s3-region' => 'us-east-1',
        '--s3-access-key' => 'AKIA123',
        '--s3-path-prefix' => 'clonio/logs/',
    ])
        ->expectsQuestion('Secret key', 'my-secret')
        ->expectsConfirmation('Override retry settings?', 'yes')
        ->expectsQuestion('Max attempts', '5')
        ->expectsQuestion('Initial delay (ms)', '1000')
        ->expectsQuestion('Backoff multiplier', '2')
        ->expectsQuestion('Max delay (ms)', '30000')
        ->expectsConfirmation('Save this channel?', 'yes')
        ->expectsOutputToContain("Channel 'my-s3' added successfully.")
        ->assertExitCode(0);
});

it('prompts interactively for email encryption and password', function (): void {
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
        '--mail-username' => 'user@example.com',
        '--mail-from-address' => 'noreply@example.com',
        '--mail-from-name' => 'Clonio',
        '--mail-to' => 'admin@example.com',
    ])
        ->expectsChoice('Encryption', 'tls', ['tls', 'ssl', 'none'])
        ->expectsQuestion('SMTP password', 'my-smtp-password')
        ->expectsConfirmation('Override retry settings?', 'yes')
        ->expectsQuestion('Max attempts', '3')
        ->expectsQuestion('Initial delay (ms)', '500')
        ->expectsQuestion('Backoff multiplier', '2')
        ->expectsQuestion('Max delay (ms)', '10000')
        ->expectsConfirmation('Save this channel?', 'yes')
        ->expectsOutputToContain("Channel 'my-email' added successfully.")
        ->assertExitCode(0);
});

it('prompts interactively for webhook url when not given via flag', function (): void {
    $config = Mockery::mock(ConfigService::class);
    $config->shouldReceive('exists')->andReturn(true);
    $config->shouldReceive('hasAuditChannel')->with('my-teams')->andReturn(false);
    $config->shouldReceive('setAuditChannel')->with('my-teams', Mockery::type('array'))->once();
    $this->app->instance(ConfigService::class, $config);

    $this->artisan('audit:add', ['name' => 'my-teams', '--type' => 'ms_teams'])
        ->expectsQuestion('Teams webhook URL', 'https://outlook.office.com/webhook/test')
        ->expectsConfirmation('Override retry settings?', 'yes')
        ->expectsQuestion('Max attempts', '3')
        ->expectsQuestion('Initial delay (ms)', '500')
        ->expectsQuestion('Backoff multiplier', '2')
        ->expectsQuestion('Max delay (ms)', '10000')
        ->expectsConfirmation('Save this channel?', 'yes')
        ->expectsOutputToContain("Channel 'my-teams' added successfully.")
        ->assertExitCode(0);
});

it('prompts interactively for ntfy priority, tags and token', function (): void {
    $config = Mockery::mock(ConfigService::class);
    $config->shouldReceive('exists')->andReturn(true);
    $config->shouldReceive('hasAuditChannel')->with('my-ntfy')->andReturn(false);
    $config->shouldReceive('setAuditChannel')->with('my-ntfy', Mockery::type('array'))->once();
    $this->app->instance(ConfigService::class, $config);

    $this->artisan('audit:add', [
        'name' => 'my-ntfy',
        '--type' => 'ntfy',
        '--ntfy-url' => 'https://ntfy.sh',
        '--ntfy-topic' => 'my-alerts',
    ])
        ->expectsChoice('Priority', 'default', ['min', 'low', 'default', 'high', 'max'])
        ->expectsQuestion('Tags (comma-separated, optional — press Enter to skip)', '')
        ->expectsQuestion('Bearer token (optional — press Enter to skip)', '')
        ->expectsConfirmation('Override retry settings?', 'yes')
        ->expectsQuestion('Max attempts', '3')
        ->expectsQuestion('Initial delay (ms)', '500')
        ->expectsQuestion('Backoff multiplier', '2')
        ->expectsQuestion('Max delay (ms)', '10000')
        ->expectsConfirmation('Save this channel?', 'yes')
        ->expectsOutputToContain("Channel 'my-ntfy' added successfully.")
        ->assertExitCode(0);
});

if (! function_exists('g2_audit_table')) {
    /**
     * Render a table exactly like Command::table() does with the default style.
     *
     * @param  list<string>  $headers
     * @param  list<list<string>>  $rows
     */
    function g2_audit_table(array $headers, array $rows): string
    {
        $buffer = new BufferedOutput;
        (new Table($buffer))->setHeaders($headers)->setRows($rows)->render();

        return $buffer->fetch();
    }
}

if (! function_exists('g2_audit_questions')) {
    /**
     * @param  object{asked: list<array<string, mixed>>}  $result
     * @return list<array{0: string, 1: mixed}>
     */
    function g2_audit_questions(object $result): array
    {
        return array_map(static fn (array $q): array => [$q['question'], $q['default']], $result->asked);
    }
}

if (! function_exists('g2_audit_decrypt')) {
    function g2_audit_decrypt(mixed $value): string
    {
        expect($value)->toBeString()->toStartWith('encrypted:');

        return Crypt::decryptString(substr((string) $value, strlen('encrypted:')));
    }
}

/**
 * @param  array<string, mixed>|null  $saved
 * @param  array<string, mixed>  $existing
 */
function g2_add_mock(?array &$saved, array $existing = []): MockInterface
{
    $config = Mockery::mock(ConfigService::class);
    $config->shouldReceive('exists')->andReturn(true);
    $config->shouldReceive('hasAuditChannel')->andReturn(false);
    $config->shouldReceive('getAuditChannels')->andReturn($existing);
    $config->shouldReceive('setAuditChannel')->andReturnUsing(function (string $name, array $channel) use (&$saved): void {
        $saved = [$name => $channel];
    });
    app()->instance(ConfigService::class, $config);

    return $config;
}

const G2_RETRY = 'Override retry settings?';
const G2_SAVE = 'Save this channel?';
const G2_HINT = 'Supports: {year} {month} {day} {source} {target} {timestamp}';

it('asks for the name when the name argument is empty', function (): void {
    $saved = null;
    g2_add_mock($saved);

    $result = g2_audit_run('audit:add', ['name' => '', '--type' => 'local', '--local-path' => './l'], [
        'Channel name' => 'asked-name',
    ]);

    expect($result->exit)->toBe(0)
        ->and(g2_audit_questions($result))->toBe([
            ['Channel name', null],
            [G2_SAVE, true],
        ])
        ->and($saved)->toBe(['asked-name' => ['path' => './l', 'type' => 'local']]);
});

it('rejects an unanswered channel name', function (): void {
    $saved = null;
    g2_add_mock($saved);

    $result = g2_audit_run('audit:add', ['--type' => 'local']);

    expect($result->exit)->toBe(4)
        ->and($result->output)->toContain('Invalid channel name. Use only lowercase letters, numbers, dashes, and underscores (max 64 chars).')
        ->and($saved)->toBeNull();
});

it('accepts a channel name of exactly 64 characters', function (): void {
    $saved = null;
    g2_add_mock($saved);
    $name = str_repeat('a', 64);

    $result = g2_audit_run('audit:add', ['name' => $name, '--type' => 'stdout']);

    expect($result->exit)->toBe(0)
        ->and($saved)->toBe([$name => ['type' => 'stdout']]);
});

it('rejects a channel name of 65 characters', function (): void {
    $saved = null;
    g2_add_mock($saved);

    $result = g2_audit_run('audit:add', ['name' => str_repeat('a', 65), '--type' => 'stdout']);

    expect($result->exit)->toBe(4)
        ->and($result->output)->toContain('Invalid channel name')
        ->and($saved)->toBeNull();
});

it('asks for the type when the type option is empty and falls back to local without an answer', function (): void {
    $saved = null;
    g2_add_mock($saved);

    $result = g2_audit_run('audit:add', ['name' => 'chan', '--type' => '']);

    expect($result->exit)->toBe(0)
        ->and(g2_audit_questions($result))->toBe([
            ['Channel type', null],
            ['Log path ('.G2_HINT.')', './clonio-logs/{year}/{month}'],
            [G2_SAVE, true],
        ])
        ->and($saved)->toBe(['chan' => ['path' => './clonio-logs/{year}/{month}', 'type' => 'local']]);
});

it('saves by default and renders the summary for a local channel', function (): void {
    $saved = null;
    $config = g2_add_mock($saved);
    $config->shouldNotReceive('addAuditUse');

    $result = g2_audit_run('audit:add', ['name' => 'loc', '--type' => 'local', '--local-path' => '']);

    expect($result->exit)->toBe(0)
        ->and(g2_audit_questions($result))->toBe([
            ['Log path ('.G2_HINT.')', './clonio-logs/{year}/{month}'],
            [G2_SAVE, true],
        ])
        ->and($result->output)->toContain(g2_audit_table(['Field', 'Value'], [
            ['Name', 'loc'],
            ['Type', 'Local filesystem'],
            ['path', './clonio-logs/{year}/{month}'],
        ]))
        ->and($result->output)->toContain("Channel 'loc' added successfully.")
        ->and($saved)->toBe(['loc' => ['path' => './clonio-logs/{year}/{month}', 'type' => 'local']]);
});

it('uses the answered local path', function (): void {
    $saved = null;
    g2_add_mock($saved);

    $result = g2_audit_run('audit:add', ['name' => 'loc', '--type' => 'local'], [
        'Log path ('.G2_HINT.')' => './custom',
    ]);

    expect($result->exit)->toBe(0)
        ->and($saved)->toBe(['loc' => ['path' => './custom', 'type' => 'local']]);
});

it('adds the channel to audit.use when --enable is given', function (): void {
    $saved = null;
    $config = g2_add_mock($saved);
    $config->shouldReceive('addAuditUse')->with('loc')->once();

    $result = g2_audit_run('audit:add', ['name' => 'loc', '--type' => 'stdout', '--enable' => true]);

    expect($result->exit)->toBe(0)
        ->and($saved)->toBe(['loc' => ['type' => 'stdout']]);
});

it('prompts for every s3 field with its defaults and retry defaults', function (): void {
    $saved = null;
    g2_add_mock($saved);

    $result = g2_audit_run('audit:add', ['name' => 'bucket', '--type' => 's3', '--s3-endpoint' => '', '--s3-secret-key' => ''], [
        G2_RETRY => true,
    ]);

    expect($result->exit)->toBe(0)
        ->and(g2_audit_questions($result))->toBe([
            ['Endpoint URL', null],
            ['Bucket name', null],
            ['Region', null],
            ['Access key', null],
            ['Secret key', null],
            ['Path prefix ('.G2_HINT.')', 'clonio/{year}/{month}/{source}/'],
            [G2_RETRY, false],
            ['Max attempts', '5'],
            ['Initial delay (ms)', '1000'],
            ['Backoff multiplier', '2'],
            ['Max delay (ms)', '30000'],
            [G2_SAVE, true],
        ])
        ->and($result->asked[4]['hidden'])->toBeTrue();

    $channel = $saved['bucket'];
    expect(g2_audit_decrypt($channel['secret_key']))->toBe('');
    unset($channel['secret_key']);

    expect($channel)->toBe([
        'endpoint' => '',
        'bucket' => '',
        'region' => '',
        'access_key' => '',
        'path_prefix' => 'clonio/{year}/{month}/{source}/',
        'retry' => [
            'max_attempts' => 5,
            'initial_delay_ms' => 1000,
            'backoff_multiplier' => 2.0,
            'max_delay_ms' => 30000,
        ],
        'type' => 's3',
    ]);
});

it('stores answered s3 fields, the encrypted secret and custom retry settings', function (): void {
    $saved = null;
    g2_add_mock($saved);

    $result = g2_audit_run('audit:add', ['name' => 'bucket', '--type' => 's3'], [
        'Endpoint URL' => 'https://s3.example.com',
        'Bucket name' => 'bkt',
        'Region' => 'eu-1',
        'Access key' => 'AKIA',
        'Secret key' => 'shh',
        'Path prefix ('.G2_HINT.')' => 'pre/',
        G2_RETRY => true,
        'Max attempts' => '7',
        'Initial delay (ms)' => '250',
        'Backoff multiplier' => '1.5',
        'Max delay (ms)' => '9000',
    ]);

    expect($result->exit)->toBe(0);

    $channel = $saved['bucket'];
    expect(g2_audit_decrypt($channel['secret_key']))->toBe('shh');
    unset($channel['secret_key']);

    expect($channel)->toBe([
        'endpoint' => 'https://s3.example.com',
        'bucket' => 'bkt',
        'region' => 'eu-1',
        'access_key' => 'AKIA',
        'path_prefix' => 'pre/',
        'retry' => [
            'max_attempts' => 7,
            'initial_delay_ms' => 250,
            'backoff_multiplier' => 1.5,
            'max_delay_ms' => 9000,
        ],
        'type' => 's3',
    ])
        ->and($result->output)->toContain(g2_audit_table(['Field', 'Value'], [
            ['Name', 'bucket'],
            ['Type', 'S3-compatible storage'],
            ['endpoint', 'https://s3.example.com'],
            ['bucket', 'bkt'],
            ['region', 'eu-1'],
            ['access key', 'AKIA'],
            ['secret key', '••••••••'],
            ['path prefix', 'pre/'],
            ['retry', '7, 250, 1.5, 9000'],
        ]));
});

it('warns when the s3 secret key is passed as a flag', function (): void {
    $saved = null;
    g2_add_mock($saved);

    $result = g2_audit_run('audit:add', [
        'name' => 'bucket',
        '--type' => 's3',
        '--s3-endpoint' => 'e',
        '--s3-bucket' => 'b',
        '--s3-region' => 'r',
        '--s3-access-key' => 'a',
        '--s3-secret-key' => 'flag-secret',
        '--s3-path-prefix' => 'p/',
    ]);

    expect($result->exit)->toBe(0)
        ->and($result->output)->toContain('Passing --s3-secret-key via CLI flag may expose it in shell history.')
        ->and(g2_audit_questions($result))->toBe([[G2_RETRY, false], [G2_SAVE, true]])
        ->and(g2_audit_decrypt($saved['bucket']['secret_key']))->toBe('flag-secret');
});

it('prompts for every email field with its defaults and retry defaults', function (): void {
    $saved = null;
    g2_add_mock($saved);

    $result = g2_audit_run('audit:add', ['name' => 'mail', '--type' => 'email', '--mail-encryption' => 'bogus'], [
        G2_RETRY => true,
    ]);

    expect($result->exit)->toBe(0)
        ->and(g2_audit_questions($result))->toBe([
            ['SMTP host', null],
            ['SMTP port', '587'],
            ['Encryption', 0],
            ['SMTP username', null],
            ['SMTP password', null],
            ['From address', null],
            ['From name', null],
            ['Recipients (comma-separated emails)', null],
            [G2_RETRY, false],
            ['Max attempts', '3'],
            ['Initial delay (ms)', '500'],
            ['Backoff multiplier', '2'],
            ['Max delay (ms)', '10000'],
            [G2_SAVE, true],
        ])
        ->and($result->asked[2]['choices'])->toBe(['tls', 'ssl', 'none']);

    $channel = $saved['mail'];
    expect(g2_audit_decrypt($channel['password']))->toBe('');
    unset($channel['password']);

    expect($channel)->toBe([
        'host' => '',
        'port' => 587,
        'encryption' => 'tls',
        'username' => '',
        'from_address' => '',
        'from_name' => '',
        'to' => [],
        'retry' => [
            'max_attempts' => 3,
            'initial_delay_ms' => 500,
            'backoff_multiplier' => 2.0,
            'max_delay_ms' => 10000,
        ],
        'type' => 'email',
    ]);
});

it('stores answered email fields with trimmed recipients', function (): void {
    $saved = null;
    g2_add_mock($saved);

    $result = g2_audit_run('audit:add', ['name' => 'mail', '--type' => 'email', '--mail-encryption' => 'ssl'], [
        'SMTP host' => 'smtp.x.io',
        'SMTP port' => '2525',
        'SMTP username' => 'user',
        'SMTP password' => 'pw',
        'From address' => 'from@x.io',
        'From name' => 'Clonio',
        'Recipients (comma-separated emails)' => ' a@x.io , ,b@x.io ',
    ]);

    expect($result->exit)->toBe(0)
        ->and(g2_audit_questions($result))->not->toContain(['Encryption', 0]);

    $channel = $saved['mail'];
    expect(g2_audit_decrypt($channel['password']))->toBe('pw');
    unset($channel['password']);

    expect($channel)->toBe([
        'host' => 'smtp.x.io',
        'port' => 2525,
        'encryption' => 'ssl',
        'username' => 'user',
        'from_address' => 'from@x.io',
        'from_name' => 'Clonio',
        'to' => ['a@x.io', 'b@x.io'],
        'type' => 'email',
    ])
        ->and($result->output)->toContain(g2_audit_table(['Field', 'Value'], [
            ['Name', 'mail'],
            ['Type', 'Email (SMTP)'],
            ['host', 'smtp.x.io'],
            ['port', '2525'],
            ['encryption', 'ssl'],
            ['username', 'user'],
            ['password', '••••••••'],
            ['from address', 'from@x.io'],
            ['from name', 'Clonio'],
            ['to', 'a@x.io, b@x.io'],
        ]));
});

it('prompts for the webhook url with the provider hint and retry defaults', function (string $type, string $label, string $hint): void {
    $saved = null;
    g2_add_mock($saved);

    $result = g2_audit_run('audit:add', ['name' => 'hook', '--type' => $type, '--webhook-url' => ''], [
        G2_RETRY => true,
    ]);

    expect($result->exit)->toBe(0)
        ->and($result->output)->toContain('  '.$hint)
        ->and($result->output)->not->toContain('Passing webhook URL via CLI flag')
        ->and(g2_audit_questions($result))->toBe([
            [$label.' webhook URL', null],
            [G2_RETRY, false],
            ['Max attempts', '3'],
            ['Initial delay (ms)', '500'],
            ['Backoff multiplier', '2'],
            ['Max delay (ms)', '10000'],
            [G2_SAVE, true],
        ])
        ->and($result->asked[0]['hidden'])->toBeTrue();

    $channel = $saved['hook'];
    expect(g2_audit_decrypt($channel['webhook_url']))->toBe('');
    unset($channel['webhook_url']);

    expect($channel)->toBe([
        'retry' => [
            'max_attempts' => 3,
            'initial_delay_ms' => 500,
            'backoff_multiplier' => 2.0,
            'max_delay_ms' => 10000,
        ],
        'type' => $type,
    ]);
})->with([
    'teams' => ['ms_teams', 'Teams', 'Create an incoming webhook in Teams under Apps → Incoming Webhook.'],
    'slack' => ['slack', 'Slack', 'Create an incoming webhook at api.slack.com → Your Apps → Incoming Webhooks.'],
]);

it('stores an answered webhook url encrypted', function (): void {
    $saved = null;
    g2_add_mock($saved);

    $result = g2_audit_run('audit:add', ['name' => 'hook', '--type' => 'slack'], [
        'Slack webhook URL' => 'https://hooks.slack.com/x',
    ]);

    expect($result->exit)->toBe(0)
        ->and(g2_audit_decrypt($saved['hook']['webhook_url']))->toBe('https://hooks.slack.com/x')
        ->and(array_keys($saved['hook']))->toBe(['webhook_url', 'type'])
        ->and($result->output)->toContain(g2_audit_table(['Field', 'Value'], [
            ['Name', 'hook'],
            ['Type', 'Slack'],
            ['webhook url', '••••••••'],
        ]));
});

it('warns when the webhook url is passed as a flag', function (): void {
    $saved = null;
    g2_add_mock($saved);

    $result = g2_audit_run('audit:add', ['name' => 'hook', '--type' => 'ms_teams', '--webhook-url' => 'https://flag']);

    expect($result->exit)->toBe(0)
        ->and($result->output)->toContain('Passing webhook URL via CLI flag may expose it in shell history.')
        ->and(g2_audit_questions($result))->toBe([[G2_RETRY, false], [G2_SAVE, true]])
        ->and(g2_audit_decrypt($saved['hook']['webhook_url']))->toBe('https://flag');
});

it('prompts for every ntfy field with its defaults and retry defaults', function (): void {
    $saved = null;
    g2_add_mock($saved);

    $result = g2_audit_run('audit:add', ['name' => 'nt', '--type' => 'ntfy', '--ntfy-priority' => 'urgent', '--ntfy-token' => ''], [
        G2_RETRY => true,
    ]);

    expect($result->exit)->toBe(0)
        ->and($result->output)->toContain('  Topics are public by default on ntfy.sh. Use a hard-to-guess topic or self-hosted server for sensitive environments.')
        ->and($result->output)->not->toContain('Passing token via CLI flag')
        ->and(g2_audit_questions($result))->toBe([
            ['ntfy server URL', 'https://ntfy.sh'],
            ['Topic', null],
            ['Priority', 2],
            ['Tags (comma-separated, optional — press Enter to skip)', null],
            ['Bearer token (optional — press Enter to skip)', null],
            [G2_RETRY, false],
            ['Max attempts', '3'],
            ['Initial delay (ms)', '500'],
            ['Backoff multiplier', '2'],
            ['Max delay (ms)', '10000'],
            [G2_SAVE, true],
        ])
        ->and($result->asked[2]['choices'])->toBe(['min', 'low', 'default', 'high', 'max'])
        ->and($result->asked[4]['hidden'])->toBeTrue()
        ->and($saved)->toBe(['nt' => [
            'url' => 'https://ntfy.sh',
            'topic' => '',
            'priority' => 'default',
            'retry' => [
                'max_attempts' => 3,
                'initial_delay_ms' => 500,
                'backoff_multiplier' => 2.0,
                'max_delay_ms' => 10000,
            ],
            'type' => 'ntfy',
        ]]);
});

it('omits the ntfy token when the answer is empty', function (): void {
    $saved = null;
    g2_add_mock($saved);

    $result = g2_audit_run('audit:add', ['name' => 'nt', '--type' => 'ntfy'], [
        'Bearer token (optional — press Enter to skip)' => '',
        'Tags (comma-separated, optional — press Enter to skip)' => '',
    ]);

    expect($result->exit)->toBe(0)
        ->and($saved)->toBe(['nt' => [
            'url' => 'https://ntfy.sh',
            'topic' => '',
            'priority' => 'default',
            'type' => 'ntfy',
        ]]);
});

it('stores answered ntfy fields with trimmed tags and an encrypted token', function (): void {
    $saved = null;
    g2_add_mock($saved);

    $result = g2_audit_run('audit:add', ['name' => 'nt', '--type' => 'ntfy', '--ntfy-priority' => 'high'], [
        'ntfy server URL' => 'https://push.example.com',
        'Topic' => 'ops',
        'Tags (comma-separated, optional — press Enter to skip)' => ' a , ,b ',
        'Bearer token (optional — press Enter to skip)' => 'tok',
    ]);

    expect($result->exit)->toBe(0);

    $channel = $saved['nt'];
    expect(g2_audit_decrypt($channel['token']))->toBe('tok');
    unset($channel['token']);

    expect($channel)->toBe([
        'url' => 'https://push.example.com',
        'topic' => 'ops',
        'priority' => 'high',
        'tags' => ['a', 'b'],
        'type' => 'ntfy',
    ])
        ->and($result->output)->toContain(g2_audit_table(['Field', 'Value'], [
            ['Name', 'nt'],
            ['Type', 'ntfy.sh / self-hosted ntfy'],
            ['url', 'https://push.example.com'],
            ['topic', 'ops'],
            ['priority', 'high'],
            ['tags', 'a, b'],
            ['token', '••••••••'],
        ]));
});

it('warns when the ntfy token is passed as a flag', function (): void {
    $saved = null;
    g2_add_mock($saved);

    $result = g2_audit_run('audit:add', [
        'name' => 'nt',
        '--type' => 'ntfy',
        '--ntfy-url' => 'https://n',
        '--ntfy-topic' => 't',
        '--ntfy-priority' => 'min',
        '--ntfy-tags' => 'x',
        '--ntfy-token' => 'flag-token',
    ]);

    expect($result->exit)->toBe(0)
        ->and($result->output)->toContain('Passing token via CLI flag may expose it in shell history.')
        ->and(g2_audit_questions($result))->toBe([[G2_RETRY, false], [G2_SAVE, true]])
        ->and(g2_audit_decrypt($saved['nt']['token']))->toBe('flag-token')
        ->and($saved['nt']['tags'])->toBe(['x']);
});

it('lists available channels and stores trimmed stack members', function (): void {
    $saved = null;
    g2_add_mock($saved, ['a' => ['type' => 'local'], 'b' => ['type' => 's3']]);

    $result = g2_audit_run('audit:add', ['name' => 'stk', '--type' => 'stack'], [
        'Channels to include (comma-separated)' => ' a , ,b ',
    ]);

    expect($result->exit)->toBe(0)
        ->and($result->output)->toStartWith("  Available channels: a, b\n")
        ->and($saved)->toBe(['stk' => ['channels' => ['a', 'b'], 'type' => 'stack']])
        ->and($result->output)->toContain(g2_audit_table(['Field', 'Value'], [
            ['Name', 'stk'],
            ['Type', 'Stack (fan-out to multiple channels)'],
            ['channels', 'a, b'],
        ]));
});

it('stores an empty stack when no members are answered', function (): void {
    $saved = null;
    g2_add_mock($saved, ['a' => ['type' => 'local']]);

    $result = g2_audit_run('audit:add', ['name' => 'stk', '--type' => 'stack']);

    expect($result->exit)->toBe(0)
        ->and(g2_audit_questions($result))->toBe([
            ['Channels to include (comma-separated)', null],
            [G2_SAVE, true],
        ])
        ->and($saved)->toBe(['stk' => ['channels' => [], 'type' => 'stack']]);
});
