<?php

declare(strict_types=1);

use App\Services\Cloning\RunLogWriter;

it('flushes logged events as JSONL', function (): void {
    $writer = new RunLogWriter;
    $writer->log('info', 'run_started', ['source' => 'prod', 'target' => 'staging']);
    $writer->log('error', 'table_failed', ['table' => 'users']);

    $output = $writer->flush();

    $lines = array_filter(explode("\n", trim($output)));
    expect($lines)->toHaveCount(2);

    $first = json_decode($lines[0], true);
    expect($first)->toBeArray();
    expect($first['level'])->toBe('info');
    expect($first['event'])->toBe('run_started');
    expect($first['source'])->toBe('prod');

    $second = json_decode(array_values($lines)[1], true);
    expect($second['level'])->toBe('error');
    expect($second['event'])->toBe('table_failed');
});

it('includes timestamp in each event', function (): void {
    $writer = new RunLogWriter;
    $writer->log('info', 'test_event');

    $output = $writer->flush();
    $first = json_decode(trim($output), true);

    expect($first)->toHaveKey('ts');
    expect($first['ts'])->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}Z$/');
});

it('has a startedAt time', function (): void {
    $writer = new RunLogWriter;
    expect($writer->startedAt())->toBeInstanceOf(DateTimeImmutable::class);
});
