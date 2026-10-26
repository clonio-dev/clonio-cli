<?php

declare(strict_types=1);

use App\Services\Config\ConfigService;
use Illuminate\Console\OutputStyle;
use Illuminate\Support\Facades\Artisan;
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
    $config->shouldReceive('getAuditUse')->andReturn([]);
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
    $config->shouldReceive('getAuditUse')->andReturn([]);
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
    $config->shouldReceive('getAuditUse')->andReturn([]);
    $config->shouldReceive('deleteAuditChannel')->with('my-s3')->once();
    $this->app->instance(ConfigService::class, $config);

    $this->artisan('audit:delete', ['name' => 'my-s3', '--force' => true])
        ->expectsOutputToContain("Channel 'my-s3' deleted.")
        ->assertExitCode(0);
});

it('shows active channel warning when deleting a channel in audit.use', function (): void {
    $channel = ['type' => 'local'];

    $config = Mockery::mock(ConfigService::class);
    $config->shouldReceive('getAuditChannels')->andReturn(['my-local' => $channel]);
    $config->shouldReceive('getAuditChannel')->with('my-local')->andReturn($channel);
    $config->shouldReceive('getAuditUse')->andReturn(['my-local']);
    $config->shouldReceive('deleteAuditChannel')->with('my-local')->once();
    $this->app->instance(ConfigService::class, $config);

    $this->artisan('audit:delete', ['name' => 'my-local', '--force' => true])
        ->expectsOutputToContain('is active')
        ->assertExitCode(0);
});

it('auto-selects the only channel when no name is given', function (): void {
    $channel = ['type' => 'local'];

    $config = Mockery::mock(ConfigService::class);
    $config->shouldReceive('getAuditChannels')->andReturn(['only-channel' => $channel]);
    $config->shouldReceive('getAuditChannel')->with('only-channel')->andReturn($channel);
    $config->shouldReceive('getAuditUse')->andReturn([]);
    $config->shouldReceive('deleteAuditChannel')->with('only-channel')->once();
    $this->app->instance(ConfigService::class, $config);

    $this->artisan('audit:delete', ['--force' => true])
        ->expectsOutputToContain("Channel 'only-channel' deleted.")
        ->assertExitCode(0);
});

it('prompts which channel to delete when several exist', function (): void {
    $local = ['type' => 'local'];
    $s3 = ['type' => 's3'];

    $config = Mockery::mock(ConfigService::class);
    $config->shouldReceive('getAuditChannels')->andReturn([
        'my-local' => $local,
        'my-s3' => $s3,
    ]);
    $config->shouldReceive('getAuditChannel')->with('my-local')->andReturn($local);
    $config->shouldReceive('getAuditUse')->andReturn([]);
    $config->shouldReceive('deleteAuditChannel')->with('my-local')->once();
    $this->app->instance(ConfigService::class, $config);

    $this->artisan('audit:delete', ['--force' => true])
        ->expectsChoice('Select a channel to delete', 'my-local', ['my-local', 'my-s3'])
        ->expectsOutputToContain("Channel 'my-local' deleted.")
        ->assertExitCode(0);
});

it('reports an IO error when deletion fails', function (): void {
    $channel = ['type' => 'local'];

    $config = Mockery::mock(ConfigService::class);
    $config->shouldReceive('getAuditChannels')->andReturn(['my-local' => $channel]);
    $config->shouldReceive('getAuditChannel')->with('my-local')->andReturn($channel);
    $config->shouldReceive('getAuditUse')->andReturn([]);
    $config->shouldReceive('deleteAuditChannel')->andThrow(new RuntimeException('delete failed'));
    $this->app->instance(ConfigService::class, $config);

    $this->artisan('audit:delete', ['name' => 'my-local', '--force' => true])
        ->expectsOutputToContain('delete failed')
        ->assertExitCode(5);
});

it('treats an empty name argument as missing and auto-selects the only channel', function (): void {
    $channel = ['type' => 'local'];

    $config = Mockery::mock(ConfigService::class);
    $config->shouldReceive('getAuditChannels')->andReturn(['only-channel' => $channel]);
    $config->shouldReceive('getAuditChannel')->with('only-channel')->once()->andReturn($channel);
    $config->shouldReceive('getAuditUse')->andReturn([]);
    $config->shouldReceive('deleteAuditChannel')->with('only-channel')->once();
    $this->app->instance(ConfigService::class, $config);

    $this->artisan('audit:delete', ['name' => '', '--force' => true])
        ->expectsOutputToContain("Channel 'only-channel' deleted.")
        ->assertExitCode(0);
});

it('falls back to the first channel when the choice yields no answer', function (): void {
    $first = ['type' => 'local'];

    $config = Mockery::mock(ConfigService::class);
    $config->shouldReceive('getAuditChannels')->andReturn(['first' => $first, 'second' => ['type' => 's3']]);
    $config->shouldReceive('getAuditChannel')->with('first')->once()->andReturn($first);
    $config->shouldReceive('getAuditUse')->andReturn([]);
    $config->shouldReceive('deleteAuditChannel')->with('first')->once();
    app()->instance(ConfigService::class, $config);

    $result = g2_audit_run('audit:delete', ['--force' => true]);

    expect($result->exit)->toBe(0)
        ->and($result->asked)->toBe([[
            'question' => 'Select a channel to delete',
            'default' => null,
            'hidden' => false,
            'choices' => ['first', 'second'],
        ]])
        ->and($result->output)->toContain("Channel 'first' deleted.");
});

it('defaults the delete confirmation to no', function (): void {
    $channel = ['type' => 'local'];

    $config = Mockery::mock(ConfigService::class);
    $config->shouldReceive('getAuditChannels')->andReturn(['my-local' => $channel]);
    $config->shouldReceive('getAuditChannel')->with('my-local')->andReturn($channel);
    $config->shouldReceive('getAuditUse')->andReturn([]);
    $config->shouldReceive('deleteAuditChannel')->never();
    app()->instance(ConfigService::class, $config);

    $result = g2_audit_run('audit:delete', ['name' => 'my-local']);

    expect($result->exit)->toBe(0)
        ->and($result->asked)->toBe([[
            'question' => "Delete channel 'my-local'? This cannot be undone.",
            'default' => false,
            'hidden' => false,
        ]])
        ->and($result->output)->toContain('Cancelled.');
});

it('renders a summary table with the channel name and type label', function (array $channel, string $typeLabel): void {
    $config = Mockery::mock(ConfigService::class);
    $config->shouldReceive('getAuditChannels')->andReturn(['my-chan' => $channel]);
    $config->shouldReceive('getAuditChannel')->with('my-chan')->andReturn($channel);
    $config->shouldReceive('getAuditUse')->andReturn([]);
    $config->shouldReceive('deleteAuditChannel')->with('my-chan')->once();
    $this->app->instance(ConfigService::class, $config);

    $this->artisan('audit:delete', ['name' => 'my-chan', '--force' => true])
        ->expectsTable(['Field', 'Value'], [
            ['Name', 'my-chan'],
            ['Type', $typeLabel],
        ])
        ->assertExitCode(0);
})->with([
    'known type' => [['type' => 'ms_teams'], 'Microsoft Teams'],
    'unknown type' => [['type' => 'carrier-pigeon'], 'carrier-pigeon'],
    'missing type' => [['path' => 'x'], ''],
    'non-string type' => [['type' => 7], ''],
]);
