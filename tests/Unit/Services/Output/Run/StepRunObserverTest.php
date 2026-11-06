<?php

declare(strict_types=1);

use App\Data\Cloning\StatsLoopData;
use App\Data\Cloning\StatsTableTransferData;
use App\Data\Cloning\TableRunStatus;
use App\Services\Cloning\SkippedRow;
use App\Services\Output\Run\StepRunObserver;
use App\Services\Output\VerboseStepRenderer;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;

/** @return array{StepRunObserver, BufferedOutput} */
function stepRunObserver(bool $debug = false): array
{
    $output = new BufferedOutput(OutputInterface::VERBOSITY_VERBOSE);

    return [new StepRunObserver(new VerboseStepRenderer($output, false), $output, $debug), $output];
}

/** @return list<SkippedRow> */
function stepRunSkipped(string $error, int $count): array
{
    return array_map(
        static fn (int $i): SkippedRow => new SkippedRow('users', 0, $i, null, $error),
        range(1, $count),
    );
}

function stepRunTimings(): StatsTableTransferData
{
    $stats = new StatsTableTransferData;
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

it('prints a step line for a copied table with its row count', function (): void {
    [$observer, $output] = stepRunObserver();

    $observer->started(1);
    $observer->tableStarted('users');
    $observer->tableFinished('users', TableRunStatus::Transferred, 1234, 0, []);
    $observer->finished();

    expect($output->fetch())->toBe("    users ...\n    users (1,234 rows) ✓\n");
});

it('adds the skipped row count to the row summary', function (): void {
    [$observer, $output] = stepRunObserver();

    $observer->tableStarted('users');
    $observer->tableFinished('users', TableRunStatus::Transferred, 3, 1, []);

    expect($output->fetch())->toBe("    users ...\n    users (3 rows, 1 skipped) ✓\n");
});

it('fails the step line for a failed table', function (): void {
    [$observer, $output] = stepRunObserver();

    $observer->tableStarted('users');
    $observer->tableFinished('users', TableRunStatus::Failed, 0, 0, []);

    expect($output->fetch())->toBe("    users ...\n    users ✗\n");
});

it('prints a line for a table that is not in the source', function (): void {
    [$observer, $output] = stepRunObserver();

    $observer->tableFinished('ghost', TableRunStatus::NotFound, 0, 0, stepRunSkipped('ignored', 1));

    expect($output->fetch())->toBe("  ?  ghost  — not found in source, skipped\n");
});

it('prints a line for a table whose schema failed', function (): void {
    [$observer, $output] = stepRunObserver();

    $observer->tableFinished('orders', TableRunStatus::SkippedBySchemaFailure, 0, 0, stepRunSkipped('ignored', 1));

    expect($output->fetch())->toBe("  S  orders  — schema replication failed, skipped\n");
});

it('prints nothing for a table skipped by flag', function (): void {
    [$observer, $output] = stepRunObserver(debug: true);

    $observer->tableProgress('users', stepRunTimings());
    $observer->tableFinished('users', TableRunStatus::SkippedByFlag, 0, 0, stepRunSkipped('ignored', 1));

    expect($output->fetch())->toBe('');
});

it('groups the skip reasons, most frequent first', function (TableRunStatus $status): void {
    [$observer, $output] = stepRunObserver();

    $observer->tableFinished('users', $status, 3, 3, [...stepRunSkipped('rare', 1), ...stepRunSkipped('common', 2)]);

    expect($output->fetch())->toBe("     └ 2× common\n     └ 1× rare\n");
})->with([TableRunStatus::Transferred, TableRunStatus::Failed]);

it('shows at most ten skip reasons and counts the rest', function (int $reasons, string $tail): void {
    [$observer, $output] = stepRunObserver();

    $skipped = [];
    foreach (range(1, $reasons) as $i) {
        $skipped = [...$skipped, ...stepRunSkipped('error '.$i, $i)];
    }

    $observer->tableFinished('users', TableRunStatus::Transferred, 0, count($skipped), $skipped);

    $lines = explode("\n", rtrim($output->fetch(), "\n"));

    expect($lines)->toHaveCount(min($reasons, 10) + ($tail === '' ? 0 : 1))
        ->and($lines[0])->toBe(sprintf('     └ %d× error %d', $reasons, $reasons))
        ->and(end($lines))->toBe($tail === '' ? sprintf('     └ 1× error 1') : $tail);
})->with([
    'ten' => [10, ''],
    'eleven' => [11, '     └ … and 1 more error types'],
]);

it('prints the timing summary at debug level for the table that just finished', function (): void {
    [$observer, $output] = stepRunObserver(debug: true);

    $observer->tableStarted('users');
    $observer->tableProgress('users', stepRunTimings());
    $observer->tableFinished('users', TableRunStatus::Transferred, 3, 0, []);

    expect($output->fetch())->toContain('── timing summary ──');

    // The timings belong to the finished table, not to the next one.
    $observer->tableStarted('orders');
    $observer->tableFinished('orders', TableRunStatus::Transferred, 3, 0, []);

    expect($output->fetch())->not->toContain('timing summary');
});

it('prints no timing summary below debug level', function (): void {
    [$observer, $output] = stepRunObserver();

    $observer->tableProgress('users', stepRunTimings());
    $observer->tableFinished('users', TableRunStatus::Failed, 3, 0, []);

    expect($output->fetch())->not->toContain('timing summary');
});

it('prints numeric skip reasons as they are', function (): void {
    [$observer, $output] = stepRunObserver();

    $observer->tableFinished('users', TableRunStatus::Transferred, 0, 3, [...stepRunSkipped('1062', 2), ...stepRunSkipped('1452', 1)]);

    expect($output->fetch())->toBe("     └ 2× 1062\n     └ 1× 1452\n");
});
