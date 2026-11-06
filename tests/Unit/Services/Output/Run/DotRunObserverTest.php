<?php

declare(strict_types=1);

use App\Data\Cloning\StatsTableTransferData;
use App\Data\Cloning\TableRunStatus;
use App\Services\Output\Run\DotRunObserver;
use Symfony\Component\Console\Output\BufferedOutput;

it('prints one character per finished table', function (TableRunStatus $status, int $skipped, string $expected): void {
    $output = new BufferedOutput;
    $observer = new DotRunObserver($output);

    $observer->tableFinished('users', $status, 3, $skipped, []);

    expect($output->fetch())->toBe($expected);
})->with([
    'copied' => [TableRunStatus::Transferred, 0, '.'],
    'copied with skipped rows' => [TableRunStatus::Transferred, 1, 'F'],
    'failed' => [TableRunStatus::Failed, 0, 'E'],
    'not found' => [TableRunStatus::NotFound, 0, '?'],
    'schema failed' => [TableRunStatus::SkippedBySchemaFailure, 0, 'S'],
    'skipped by flag' => [TableRunStatus::SkippedByFlag, 0, ''],
    'skipped by cascade' => [TableRunStatus::SkippedByCascade, 0, ''],
]);

it('prints nothing for the other events', function (): void {
    $output = new BufferedOutput;
    $observer = new DotRunObserver($output);

    $observer->started(3);
    $observer->tableStarted('users');
    $observer->tableProgress('users', new StatsTableTransferData);

    expect($output->fetch())->toBe('');
});

it('wraps after 70 characters and ends the line when the run finishes', function (): void {
    $output = new BufferedOutput;
    $observer = new DotRunObserver($output);

    foreach (range(1, 71) as $i) {
        $observer->tableFinished('t'.$i, TableRunStatus::Transferred, 1, 0, []);
    }

    $observer->finished();

    expect($output->fetch())->toBe(str_repeat('.', 70)."\n.\n");
});

it('does not count tables it prints nothing for', function (): void {
    $output = new BufferedOutput;
    $observer = new DotRunObserver($output);

    foreach (range(1, 69) as $i) {
        $observer->tableFinished('t'.$i, TableRunStatus::Transferred, 1, 0, []);
    }

    $observer->tableFinished('skipped', TableRunStatus::SkippedByFlag, 0, 0, []);
    $observer->tableFinished('last', TableRunStatus::Transferred, 1, 0, []);

    expect($output->fetch())->toBe(str_repeat('.', 70)."\n");
});

it('adds no empty line when the run ends on a full line', function (): void {
    $output = new BufferedOutput;
    $observer = new DotRunObserver($output);

    foreach (range(1, 70) as $i) {
        $observer->tableFinished('t'.$i, TableRunStatus::Transferred, 1, 0, []);
    }

    $observer->finished();

    expect($output->fetch())->toBe(str_repeat('.', 70)."\n");
});

it('prints nothing when the run finishes without tables', function (): void {
    $output = new BufferedOutput;

    new DotRunObserver($output)->finished();

    expect($output->fetch())->toBe('');
});
