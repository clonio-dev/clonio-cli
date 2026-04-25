<?php

declare(strict_types=1);

use App\Services\Output\VerboseStepRenderer;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;

function makeStepRenderer(
    int $verbosity = OutputInterface::VERBOSITY_VERBOSE,
    bool $decorated = false,
    bool $ci = false,
): array {
    $output = new BufferedOutput($verbosity, $decorated);

    return [new VerboseStepRenderer($output, $ci), $output];
}

it('writes nothing when verbosity is below verbose', function (): void {
    [$step, $output] = makeStepRenderer(verbosity: OutputInterface::VERBOSITY_NORMAL);

    $step->start('Doing thing');
    $step->success();
    $step->note('     └ note line');

    expect($output->fetch())->toBe('');
});

it('writes nothing when ci flag is set even at verbose verbosity', function (): void {
    [$step, $output] = makeStepRenderer(ci: true);

    $step->start('Doing thing');
    $step->success();

    expect($output->fetch())->toBe('');
});

it('renders non-tty start as label-with-ellipsis line and success as second line', function (): void {
    [$step, $output] = makeStepRenderer(decorated: false);

    $step->start('Validating YAML');
    $step->success();

    $text = $output->fetch();
    expect($text)->toContain('  Validating YAML ...');
    expect($text)->toContain('✓');
    // Two separate lines on non-TTY output
    expect(substr_count($text, "\n"))->toBe(2);
});

it('renders failure with cross symbol on non-tty', function (): void {
    [$step, $output] = makeStepRenderer(decorated: false);

    $step->start('Connecting to db');
    $step->fail();

    expect($output->fetch())->toContain('✗');
});

it('rewrites the start line in place on tty (uses cursor-up + clear-line escape)', function (): void {
    [$step, $output] = makeStepRenderer(decorated: true);

    $step->start('Validating YAML');
    $step->success();

    $text = $output->fetch();
    expect($text)->toContain("\x1b[1A");
    expect($text)->toContain("\r");
    expect($text)->toContain('Validating YAML');
});

it('places suffix between label and success symbol', function (): void {
    [$step, $output] = makeStepRenderer(decorated: false);

    $step->start('users');
    $step->success('(1.245.000 rows, 2 skipped)');

    $text = $output->fetch();
    expect($text)->toMatch('/users.*\(1\.245\.000 rows, 2 skipped\).*✓/s');
});

it('writes indented note line without affecting step state', function (): void {
    [$step, $output] = makeStepRenderer(decorated: false);

    $step->start('orders');
    $step->success('(8.731.987 rows, 425 skipped)');
    $step->note('     └ 312× SQLSTATE[23000]: Integrity constraint violation');

    $text = $output->fetch();
    expect($text)->toContain('     └ 312× SQLSTATE[23000]: Integrity constraint violation');
});

it('run wraps closure: success path writes ✓ and returns closure value', function (): void {
    [$step, $output] = makeStepRenderer(decorated: false);

    $result = $step->run('Validating YAML', static fn (): int => 42);

    expect($result)->toBe(42);
    expect($output->fetch())->toContain('✓');
});

it('run wraps closure: failure path writes ✗ and re-throws exception', function (): void {
    [$step, $output] = makeStepRenderer(decorated: false);

    $closure = static function (): never {
        throw new RuntimeException('boom');
    };

    expect(static fn (): mixed => $step->run('Validating YAML', $closure))
        ->toThrow(RuntimeException::class, 'boom');

    expect($output->fetch())->toContain('✗');
});

it('finalize is a no-op when no step was started', function (): void {
    [$step, $output] = makeStepRenderer(decorated: false);

    $step->success();
    $step->fail();

    expect($output->fetch())->toBe('');
});
