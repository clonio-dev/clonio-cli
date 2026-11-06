<?php

declare(strict_types=1);

use App\Data\Cloning\StatsLoopData;
use App\Data\Cloning\StatsTableTransferData;
use App\Data\Cloning\TableRunStatus;
use App\Services\Cloning\SkippedRow;
use App\Services\Output\CloningProgressDisplay;
use App\Services\Output\Run\ProgressRunObserver;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Output\ConsoleSectionOutput;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * An observer over in-memory sections. Returns [observer, log, table bar, overall bar].
 *
 * @return array{ProgressRunObserver, ConsoleSectionOutput, ConsoleSectionOutput, ConsoleSectionOutput}
 */
function progressRunObserver(bool $debug = false): array
{
    $stream = fopen('php://memory', 'w+');
    $sections = [];
    $formatter = new OutputFormatter(true);
    $section = static fn (): ConsoleSectionOutput => new ConsoleSectionOutput($stream, $sections, OutputInterface::VERBOSITY_NORMAL, true, $formatter);

    $log = $section();
    $table = $section();
    $overall = $section();
    $display = new CloningProgressDisplay($log, $table, $overall, false, minSecondsBetweenRedraws: 0);

    return [new ProgressRunObserver($display, $debug), $log, $table, $overall];
}

/** Current text of a section, without ANSI styling. */
function progressRunText(string $content): string
{
    return (string) preg_replace('/\e\[[0-9;]*[A-Za-z]/', '', $content);
}

function progressRunTimings(): StatsTableTransferData
{
    $stats = new StatsTableTransferData;
    $stats->setTotalRows(3);
    $stats->recordLoop(new StatsLoopData(
        chunkRows: 3,
        selectSeconds: 0.1,
        transformSeconds: 0.1,
        insertSeconds: 0.2,
        overallSeconds: 0.4,
        rowsDone: 3,
        rowsSkipped: 0,
    ));

    return $stats;
}

it('drives the bars and logs a line per table', function (): void {
    [$observer, $log, $table, $overall] = progressRunObserver();

    $observer->started(2);
    $observer->tableStarted('users');
    $observer->tableProgress('users', progressRunTimings());
    $observer->tableFinished('users', TableRunStatus::Transferred, 3, 0, []);

    expect(progressRunText($log->getContent()))->toBe("  ✓  users  (3 rows)\n")
        ->and(progressRunText($table->getContent()))->toMatch('/^  users  \[[^\]]+\] +3  /')
        ->and(progressRunText($overall->getContent()))->toContain('1/2');

    $observer->tableStarted('orders');
    $observer->tableFinished('orders', TableRunStatus::Failed, 0, 0, [new SkippedRow('orders', 0, 0, null, 'boom')]);

    expect(progressRunText($log->getContent()))->toBe("  ✓  users  (3 rows)\n  ✗  orders  — transfer failed\n     └ 1× boom\n")
        ->and(progressRunText($table->getContent()))->toContain('orders  stopped after 0 rows')
        ->and(progressRunText($overall->getContent()))->toContain('2/2');
});

it('logs tables that never started a bar without touching the table bar', function (): void {
    [$observer, $log, $table, $overall] = progressRunObserver();

    $observer->started(2);
    $observer->tableFinished('ghost', TableRunStatus::NotFound, 0, 0, []);
    $observer->tableFinished('orders', TableRunStatus::SkippedBySchemaFailure, 0, 0, []);

    expect(progressRunText($log->getContent()))->toBe("  ?  ghost  — not found in source, skipped\n  S  orders  — schema replication failed, skipped\n")
        ->and(progressRunText($table->getContent()))->toBe('')
        ->and(progressRunText($overall->getContent()))->toContain('2/2');
});

it('closes the bars when the run finishes', function (): void {
    [$observer, , $table, $overall] = progressRunObserver();

    $observer->started(2);
    $observer->tableStarted('users');
    $observer->finished();

    expect(progressRunText($table->getContent()))->toContain('stopped')
        ->and(progressRunText($overall->getContent()))->toContain('0/2');
});

it('prints the timing summary into the log at debug level', function (): void {
    [$observer, $log] = progressRunObserver(debug: true);

    $observer->started(1);
    $observer->tableStarted('users');
    $observer->tableProgress('users', progressRunTimings());
    $observer->tableFinished('users', TableRunStatus::Transferred, 3, 0, []);

    expect(progressRunText($log->getContent()))->toContain('── timing summary ──');
});
