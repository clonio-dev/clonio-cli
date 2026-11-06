<?php

declare(strict_types=1);

use App\Commands\Cloning\RunCommand;
use App\Logging\AuditBuffer;
use Illuminate\Log\Logger;
use Illuminate\Support\Facades\Log;
use Monolog\Handler\StreamHandler;
use Monolog\Handler\TestHandler;
use Monolog\Handler\WhatFailureGroupHandler;
use Monolog\Level;
use Monolog\Logger as Monolog;
use Psr\Log\NullLogger;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;

/** The stderr handler of the default log stack. */
function stderrLogHandler(): StreamHandler
{
    $logger = Log::driver();
    expect($logger)->toBeInstanceOf(Logger::class);
    $monolog = $logger->getLogger();
    expect($monolog)->toBeInstanceOf(Monolog::class);

    $handlers = array_values(array_filter(
        $monolog->getHandlers(),
        static fn (object $h): bool => $h instanceof StreamHandler && $h->getUrl() === 'php://stderr',
    ));
    expect($handlers)->toHaveCount(1);

    return $handlers[0];
}

function redirectRunLogs(OutputInterface $section): Closure
{
    return (fn (OutputInterface $s): Closure => $this->redirectLogsTo($s))->call(app(RunCommand::class), $section);
}

beforeEach(function (): void {
    app(AuditBuffer::class)->clear();
});

it('moves stderr records into the section, at the stderr level, until restored', function (): void {
    $stderr = stderrLogHandler();
    $levelBefore = $stderr->getLevel();
    $stderr->setLevel(Level::Info); // as at -v
    $section = new BufferedOutput;

    $restore = redirectRunLogs($section);

    expect($stderr->getLevel())->toBe(Level::Emergency);

    Log::warning('auto_increment_correction_failed', ['table' => 'users']);
    Log::info('table_transferred', ['table' => 'users']);
    Log::debug('chunk_fetched');
    Log::warning('row_skipped', ['table' => 'users']); // listed per table as skip groups instead

    expect($section->fetch())->toBe(
        "  WARNING: auto_increment_correction_failed {\"table\":\"users\"}\n"
        ."  INFO: table_transferred {\"table\":\"users\"}\n"
    );
    // The audit trail still records every event.
    expect(array_column(app(AuditBuffer::class)->records(), 'event'))
        ->toBe(['auto_increment_correction_failed', 'table_transferred', 'chunk_fetched', 'row_skipped']);

    $restore();

    expect($stderr->getLevel())->toBe(Level::Info);
    $stderr->setLevel($levelBefore);

    Log::error('audit_delivery_failed');
    expect($section->fetch())->toBe('');
});

it('leaves logging alone when the default logger is not Monolog-backed', function (): void {
    Log::shouldReceive('driver')->andReturn(new NullLogger);
    $section = new BufferedOutput;

    $restore = redirectRunLogs($section);
    $restore();

    expect($section->fetch())->toBe('');
});

it('leaves logging alone when the stack has no plain stderr handler', function (): void {
    // E.g. ignore_exceptions wraps every handler in a WhatFailureGroupHandler.
    $wrapped = new TestHandler;
    $monolog = new Monolog('clonio', [new WhatFailureGroupHandler([$wrapped])]);
    Log::shouldReceive('driver')->andReturn(new Logger($monolog));
    $section = new BufferedOutput;

    $restore = redirectRunLogs($section);

    expect($monolog->getHandlers())->toHaveCount(1);
    $monolog->warning('auto_increment_correction_failed');
    expect($section->fetch())->toBe('')
        ->and($wrapped->hasWarningRecords())->toBeTrue();

    $restore();
    expect($monolog->getHandlers())->toHaveCount(1);
});

it('removes only its own handler on restore, even when another was pushed after it', function (): void {
    $stderr = new StreamHandler('php://stderr', Level::Warning);
    $base = new TestHandler;
    $monolog = new Monolog('clonio', [$stderr, $base]);
    Log::shouldReceive('driver')->andReturn(new Logger($monolog));
    $section = new BufferedOutput;

    $restore = redirectRunLogs($section);
    $later = new TestHandler;
    $monolog->pushHandler($later);

    $restore();

    expect($monolog->getHandlers())->toBe([$later, $stderr, $base])
        ->and($stderr->getLevel())->toBe(Level::Warning);

    $monolog->warning('auto_increment_correction_failed');
    expect($section->fetch())->toBe('')
        ->and($later->hasWarningRecords())->toBeTrue();
});
