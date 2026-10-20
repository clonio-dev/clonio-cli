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

it('preserves hand-edited delivery flags on update', function (): void {
    $channel = [
        'type' => 'local',
        'path' => './old-logs',
        'delivers_audit' => false,
        'delivers_process_log' => false,
    ];

    $config = Mockery::mock(ConfigService::class);
    $config->shouldReceive('getAuditChannels')->andReturn(['my-local' => $channel]);
    $config->shouldReceive('getAuditChannel')->with('my-local')->andReturn($channel);
    $config->shouldReceive('setAuditChannel')
        ->with('my-local', Mockery::on(fn (array $saved): bool => $saved['path'] === './new-logs'
            && $saved['delivers_audit'] === false
            && $saved['delivers_process_log'] === false))
        ->once();
    $this->app->instance(ConfigService::class, $config);

    $this->artisan('audit:update', ['name' => 'my-local'])
        ->expectsQuestion('Log path', './new-logs')
        ->expectsConfirmation('Save changes?', 'yes')
        ->assertExitCode(0);
});

it('does not add delivery flags that were not set before', function (): void {
    $channel = ['type' => 'local', 'path' => './old-logs'];

    $config = Mockery::mock(ConfigService::class);
    $config->shouldReceive('getAuditChannels')->andReturn(['my-local' => $channel]);
    $config->shouldReceive('getAuditChannel')->with('my-local')->andReturn($channel);
    $config->shouldReceive('setAuditChannel')
        ->with('my-local', Mockery::on(fn (array $saved): bool => ! array_key_exists('delivers_audit', $saved)
            && ! array_key_exists('delivers_process_log', $saved)))
        ->once();
    $this->app->instance(ConfigService::class, $config);

    $this->artisan('audit:update', ['name' => 'my-local'])
        ->expectsQuestion('Log path', './new-logs')
        ->expectsConfirmation('Save changes?', 'yes')
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
 * @param  array<string, array<string, mixed>>  $channels
 */
function g2_update_mock(?array &$saved, array $channels): MockInterface
{
    $config = Mockery::mock(ConfigService::class);
    $config->shouldReceive('getAuditChannels')->andReturn($channels);
    $config->shouldReceive('getAuditChannel')->andReturnUsing(fn (string $name): ?array => $channels[$name] ?? null);
    $config->shouldReceive('setAuditChannel')->andReturnUsing(function (string $name, array $channel) use (&$saved): void {
        $saved = [$name => $channel];
    });
    app()->instance(ConfigService::class, $config);

    return $config;
}

const G2_UPD_SAVE = 'Save changes?';
const G2_UPD_MASK = '••••••••';

it('treats an empty name argument as missing and auto-selects the only channel', function (): void {
    $saved = null;
    g2_update_mock($saved, ['solo' => ['type' => 'stdout']]);

    $result = g2_audit_run('audit:update', ['name' => '']);

    expect($result->exit)->toBe(0)
        ->and($saved)->toBe(['solo' => ['type' => 'stdout']]);
});

it('falls back to the first channel when the channel choice yields no answer', function (): void {
    $saved = null;
    g2_update_mock($saved, ['first' => ['type' => 'stdout'], 'second' => ['type' => 'stderr']]);

    $result = g2_audit_run('audit:update');

    expect($result->exit)->toBe(0)
        ->and($result->asked[0])->toBe([
            'question' => 'Which channel do you want to update?',
            'default' => null,
            'hidden' => false,
            'choices' => ['first', 'second'],
        ])
        ->and($saved)->toBe(['first' => ['type' => 'stdout']]);
});

it('names an empty type when the stored type is missing', function (): void {
    $saved = null;
    g2_update_mock($saved, ['broken' => ['path' => 'x']]);

    $result = g2_audit_run('audit:update', ['name' => 'broken']);

    expect($result->exit)->toBe(2)
        ->and($result->output)->toContain("Channel 'broken' has unknown type ''.")
        ->and($saved)->toBeNull();
});

it('saves by default, prints the type line and reports unchanged fields', function (): void {
    $saved = null;
    g2_update_mock($saved, ['loc' => ['type' => 'local', 'path' => './keep']]);

    $result = g2_audit_run('audit:update', ['name' => 'loc']);

    expect($result->exit)->toBe(0)
        ->and(g2_audit_questions($result))->toBe([
            ['Log path', './keep'],
            [G2_UPD_SAVE, true],
        ])
        ->and($result->output)->toContain("  Type: Local filesystem (cannot be changed — delete and re-add to change type)\n")
        ->and($result->output)->toContain('No fields changed.')
        ->and($result->output)->not->toContain('| Field')
        ->and($result->output)->toContain("Channel 'loc' updated successfully.")
        ->and($saved)->toBe(['loc' => ['path' => './keep', 'type' => 'local']]);
});

it('uses the default local path and shows added keys in the diff', function (): void {
    $saved = null;
    g2_update_mock($saved, ['loc' => ['type' => 'local', 'path' => 12]]);

    $result = g2_audit_run('audit:update', ['name' => 'loc']);

    expect($result->exit)->toBe(0)
        ->and(g2_audit_questions($result))->toBe([
            ['Log path', './clonio-logs/{year}/{month}'],
            [G2_UPD_SAVE, true],
        ])
        ->and($result->output)->toContain(g2_audit_table(['Field', 'Old', 'New'], [
            ['path', '12', './clonio-logs/{year}/{month}'],
        ]))
        ->and($saved)->toBe(['loc' => ['path' => './clonio-logs/{year}/{month}', 'type' => 'local']]);
});

it('renders a diff of removed, added and masked secret fields', function (): void {
    $saved = null;
    g2_update_mock($saved, ['loc' => [
        'type' => 'local',
        'legacy' => ['a' => 1],
        'broken' => ["\xB1"],
        'nothing' => null,
        'secret' => 'encrypted:abc',
        'flag' => true,
    ]]);

    $result = g2_audit_run('audit:update', ['name' => 'loc'], ['Log path' => 'encrypted:new']);

    expect($result->exit)->toBe(0)
        ->and($result->output)->toContain(g2_audit_table(['Field', 'Old', 'New'], [
            ['legacy', '{"a":1}', ''],
            ['secret', G2_UPD_MASK, ''],
            ['flag', '1', ''],
            ['path', '', G2_UPD_MASK],
        ]));
});

it('prompts s3 fields pre-filled from the current channel and keeps the secret', function (): void {
    $saved = null;
    $current = [
        'type' => 's3',
        'endpoint' => 'https://s3',
        'bucket' => 'bkt',
        'region' => 'eu',
        'access_key' => 'AK',
        'secret_key' => 'encrypted:old',
        'path_prefix' => 'pre/',
        'retry' => ['max_attempts' => 9],
    ];
    g2_update_mock($saved, ['s' => $current]);

    $result = g2_audit_run('audit:update', ['name' => 's']);

    expect($result->exit)->toBe(0)
        ->and(g2_audit_questions($result))->toBe([
            ['Endpoint', 'https://s3'],
            ['Bucket', 'bkt'],
            ['Region', 'eu'],
            ['Access key', 'AK'],
            ['Secret key (press Enter to keep)', null],
            ['Path prefix', 'pre/'],
            [G2_UPD_SAVE, true],
        ])
        ->and($result->asked[4]['hidden'])->toBeTrue()
        ->and($result->output)->toContain('  Secret key: '.G2_UPD_MASK.' (press Enter to keep)')
        ->and($saved)->toBe(['s' => [
            'endpoint' => 'https://s3',
            'bucket' => 'bkt',
            'region' => 'eu',
            'access_key' => 'AK',
            'secret_key' => 'encrypted:old',
            'path_prefix' => 'pre/',
            'retry' => ['max_attempts' => 9],
            'type' => 's3',
        ]]);
});

it('prompts s3 fields with empty defaults when nothing is stored', function (): void {
    $saved = null;
    g2_update_mock($saved, ['s' => ['type' => 's3', 'retry' => 'bad', 'secret_key' => 5]]);

    $result = g2_audit_run('audit:update', ['name' => 's']);

    expect($result->exit)->toBe(0)
        ->and(g2_audit_questions($result))->toBe([
            ['Endpoint', null],
            ['Bucket', null],
            ['Region', null],
            ['Access key', null],
            ['Secret key (press Enter to keep)', null],
            ['Path prefix', 'clonio/{year}/{month}/{source}/'],
            [G2_UPD_SAVE, true],
        ])
        ->and($saved)->toBe(['s' => [
            'endpoint' => '',
            'bucket' => '',
            'region' => '',
            'access_key' => '',
            'secret_key' => '',
            'path_prefix' => 'clonio/{year}/{month}/{source}/',
            'type' => 's3',
        ]]);
});

it('keeps an empty s3 secret when none is stored', function (): void {
    $saved = null;
    g2_update_mock($saved, ['s' => ['type' => 's3']]);

    $result = g2_audit_run('audit:update', ['name' => 's'], ['Secret key (press Enter to keep)' => '']);

    expect($result->exit)->toBe(0)
        ->and($saved['s']['secret_key'])->toBe('');
});

it('stores answered s3 fields and encrypts a new secret', function (): void {
    $saved = null;
    g2_update_mock($saved, ['s' => ['type' => 's3', 'secret_key' => 'encrypted:old']]);

    $result = g2_audit_run('audit:update', ['name' => 's'], [
        'Endpoint' => 'e',
        'Bucket' => 'b',
        'Region' => 'r',
        'Access key' => 'a',
        'Secret key (press Enter to keep)' => 'new-secret',
        'Path prefix' => 'p/',
    ]);

    expect($result->exit)->toBe(0);

    $channel = $saved['s'];
    expect(g2_audit_decrypt($channel['secret_key']))->toBe('new-secret');
    unset($channel['secret_key']);

    expect($channel)->toBe([
        'endpoint' => 'e',
        'bucket' => 'b',
        'region' => 'r',
        'access_key' => 'a',
        'path_prefix' => 'p/',
        'type' => 's3',
    ]);
});

it('prompts email fields pre-filled from the current channel', function (): void {
    $saved = null;
    g2_update_mock($saved, ['m' => [
        'type' => 'email',
        'host' => 'smtp.x',
        'port' => 2525,
        'encryption' => 'ssl',
        'username' => 'u',
        'password' => 'encrypted:pw',
        'from_address' => 'f@x',
        'from_name' => 'F',
        'to' => ['a@x', 5, ['nested']],
        'retry' => ['max_attempts' => 2],
    ]]);

    $result = g2_audit_run('audit:update', ['name' => 'm']);

    expect($result->exit)->toBe(0)
        ->and(g2_audit_questions($result))->toBe([
            ['SMTP host', 'smtp.x'],
            ['SMTP port', '2525'],
            ['Encryption', 1],
            ['Username', 'u'],
            ['Password (press Enter to keep)', null],
            ['From address', 'f@x'],
            ['From name', 'F'],
            ['Recipients (comma-separated)', 'a@x, 5, '],
            [G2_UPD_SAVE, true],
        ])
        ->and($result->asked[2]['choices'])->toBe(['tls', 'ssl', 'none'])
        ->and($saved)->toBe(['m' => [
            'host' => 'smtp.x',
            'port' => 2525,
            'encryption' => 'ssl',
            'username' => 'u',
            'password' => 'encrypted:pw',
            'from_address' => 'f@x',
            'from_name' => 'F',
            'to' => ['a@x', '5'],
            'retry' => ['max_attempts' => 2],
            'type' => 'email',
        ]]);
});

it('prompts email fields with fallback defaults when nothing is stored', function (): void {
    $saved = null;
    g2_update_mock($saved, ['m' => ['type' => 'email', 'port' => '25', 'encryption' => 'starttls', 'to' => 'x', 'retry' => 'bad']]);

    $result = g2_audit_run('audit:update', ['name' => 'm']);

    expect($result->exit)->toBe(0)
        ->and(g2_audit_questions($result))->toBe([
            ['SMTP host', null],
            ['SMTP port', '587'],
            ['Encryption', 0],
            ['Username', null],
            ['Password (press Enter to keep)', null],
            ['From address', null],
            ['From name', null],
            ['Recipients (comma-separated)', null],
            [G2_UPD_SAVE, true],
        ])
        ->and($saved)->toBe(['m' => [
            'host' => '',
            'port' => 587,
            'encryption' => 'tls',
            'username' => '',
            'password' => '',
            'from_address' => '',
            'from_name' => '',
            'to' => [],
            'type' => 'email',
        ]]);
});

it('stores answered email fields with trimmed recipients', function (): void {
    $saved = null;
    g2_update_mock($saved, ['m' => ['type' => 'email']]);

    $result = g2_audit_run('audit:update', ['name' => 'm'], [
        'SMTP host' => 'h',
        'SMTP port' => '465',
        'Encryption' => 'none',
        'Username' => 'u',
        'Password (press Enter to keep)' => 'pw',
        'From address' => 'f',
        'From name' => 'n',
        'Recipients (comma-separated)' => ' a , ,b ',
    ]);

    expect($result->exit)->toBe(0);

    $channel = $saved['m'];
    expect(g2_audit_decrypt($channel['password']))->toBe('pw');
    unset($channel['password']);

    expect($channel)->toBe([
        'host' => 'h',
        'port' => 465,
        'encryption' => 'none',
        'username' => 'u',
        'from_address' => 'f',
        'from_name' => 'n',
        'to' => ['a', 'b'],
        'type' => 'email',
    ]);
});

it('keeps the webhook url and retry settings by default', function (): void {
    $saved = null;
    g2_update_mock($saved, ['w' => ['type' => 'slack', 'webhook_url' => 'encrypted:old', 'retry' => ['max_attempts' => 4]]]);

    $result = g2_audit_run('audit:update', ['name' => 'w']);

    expect($result->exit)->toBe(0)
        ->and(g2_audit_questions($result))->toBe([
            ['Webhook URL (press Enter to keep)', null],
            [G2_UPD_SAVE, true],
        ])
        ->and($result->output)->toContain('  Webhook URL: '.G2_UPD_MASK.' (press Enter to keep)')
        ->and($saved)->toBe(['w' => [
            'webhook_url' => 'encrypted:old',
            'retry' => ['max_attempts' => 4],
            'type' => 'slack',
        ]]);
});

it('stores an empty webhook url when none is stored', function (): void {
    $saved = null;
    g2_update_mock($saved, ['w' => ['type' => 'ms_teams', 'retry' => 'bad']]);

    $result = g2_audit_run('audit:update', ['name' => 'w']);

    expect($result->exit)->toBe(0)
        ->and($saved)->toBe(['w' => ['webhook_url' => '', 'type' => 'ms_teams']]);
});

it('prompts ntfy fields pre-filled from the current channel', function (): void {
    $saved = null;
    g2_update_mock($saved, ['n' => [
        'type' => 'ntfy',
        'url' => 'https://push.x',
        'topic' => 'ops',
        'priority' => 'high',
        'tags' => ['a', 5, ['nested']],
        'token' => 'encrypted:tok',
        'retry' => ['max_attempts' => 1],
    ]]);

    $result = g2_audit_run('audit:update', ['name' => 'n']);

    expect($result->exit)->toBe(0)
        ->and(g2_audit_questions($result))->toBe([
            ['Server URL', 'https://push.x'],
            ['Topic', 'ops'],
            ['Priority', 3],
            ['Tags (comma-separated, optional)', 'a, 5, '],
            ['Bearer token (optional — press Enter to keep/skip) (press Enter to keep)', null],
            [G2_UPD_SAVE, true],
        ])
        ->and($result->asked[2]['choices'])->toBe(['min', 'low', 'default', 'high', 'max'])
        ->and($saved)->toBe(['n' => [
            'url' => 'https://push.x',
            'topic' => 'ops',
            'priority' => 'high',
            'tags' => ['a', '5'],
            'token' => 'encrypted:tok',
            'retry' => ['max_attempts' => 1],
            'type' => 'ntfy',
        ]]);
});

it('prompts ntfy fields with fallback defaults when nothing is stored', function (): void {
    $saved = null;
    g2_update_mock($saved, ['n' => ['type' => 'ntfy', 'priority' => 'urgent', 'tags' => 'x', 'retry' => 'bad']]);

    $result = g2_audit_run('audit:update', ['name' => 'n']);

    expect($result->exit)->toBe(0)
        ->and(g2_audit_questions($result))->toBe([
            ['Server URL', 'https://ntfy.sh'],
            ['Topic', null],
            ['Priority', 0],
            ['Tags (comma-separated, optional)', null],
            ['Bearer token (optional — press Enter to keep/skip) (press Enter to keep)', null],
            [G2_UPD_SAVE, true],
        ])
        ->and($saved)->toBe(['n' => [
            'url' => 'https://ntfy.sh',
            'topic' => '',
            'priority' => 'min',
            'type' => 'ntfy',
        ]]);
});

it('defaults the ntfy priority to default when none is stored', function (): void {
    $saved = null;
    g2_update_mock($saved, ['n' => ['type' => 'ntfy']]);

    $result = g2_audit_run('audit:update', ['name' => 'n']);

    expect($result->exit)->toBe(0)
        ->and($result->asked[2]['default'])->toBe(2)
        ->and($saved['n']['priority'])->toBe('default');
});

it('stores answered ntfy fields with trimmed tags and a new token', function (): void {
    $saved = null;
    g2_update_mock($saved, ['n' => ['type' => 'ntfy']]);

    $result = g2_audit_run('audit:update', ['name' => 'n'], [
        'Server URL' => 'https://n',
        'Topic' => 't',
        'Priority' => 'max',
        'Tags (comma-separated, optional)' => ' a , ,b ',
        'Bearer token (optional — press Enter to keep/skip) (press Enter to keep)' => 'tok',
    ]);

    expect($result->exit)->toBe(0);

    $channel = $saved['n'];
    expect(g2_audit_decrypt($channel['token']))->toBe('tok');
    unset($channel['token']);

    expect($channel)->toBe([
        'url' => 'https://n',
        'topic' => 't',
        'priority' => 'max',
        'tags' => ['a', 'b'],
        'type' => 'ntfy',
    ]);
});

it('drops ntfy tags when the answer is empty', function (): void {
    $saved = null;
    g2_update_mock($saved, ['n' => ['type' => 'ntfy', 'tags' => ['a']]]);

    $result = g2_audit_run('audit:update', ['name' => 'n'], ['Tags (comma-separated, optional)' => '']);

    expect($result->exit)->toBe(0)
        ->and($saved['n'])->not->toHaveKey('tags');
});

it('masks a json encoding failure as an empty value in the diff', function (): void {
    $saved = null;
    g2_update_mock($saved, ['n' => ['type' => 'ntfy', 'url' => 'https://ntfy.sh', 'topic' => 't', 'priority' => 'default']]);

    $result = g2_audit_run('audit:update', ['name' => 'n'], ['Tags (comma-separated, optional)' => "\xB1"]);

    expect($result->exit)->toBe(0)
        ->and($saved['n']['tags'])->toBe(["\xB1"])
        ->and($result->output)->toContain('No fields changed.');
});

it('prompts stack members pre-filled from the current channel', function (): void {
    $saved = null;
    g2_update_mock($saved, ['st' => ['type' => 'stack', 'channels' => ['a', 5, 'b']]]);

    $result = g2_audit_run('audit:update', ['name' => 'st']);

    expect($result->exit)->toBe(0)
        ->and(g2_audit_questions($result))->toBe([
            ['Channels (comma-separated)', 'a, b'],
            [G2_UPD_SAVE, true],
        ])
        ->and($saved)->toBe(['st' => ['channels' => ['a', 'b'], 'type' => 'stack']]);
});

it('prompts stack members without a default when none are stored', function (): void {
    $saved = null;
    g2_update_mock($saved, ['st' => ['type' => 'stack', 'channels' => null]]);

    $result = g2_audit_run('audit:update', ['name' => 'st'], ['Channels (comma-separated)' => ' x , ,y ']);

    expect($result->exit)->toBe(0)
        ->and(g2_audit_questions($result))->toBe([
            ['Channels (comma-separated)', null],
            [G2_UPD_SAVE, true],
        ])
        ->and($saved)->toBe(['st' => ['channels' => ['x', 'y'], 'type' => 'stack']])
        ->and($result->output)->toContain(g2_audit_table(['Field', 'Old', 'New'], [
            ['channels', '', '["x","y"]'],
        ]));
});
