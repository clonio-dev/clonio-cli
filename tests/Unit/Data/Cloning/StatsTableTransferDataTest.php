<?php

declare(strict_types=1);

use App\Data\Cloning\StatsLoopData;
use App\Data\Cloning\StatsTableTransferData;
use App\Data\Cloning\TableRunPhase;

it('returns an empty (count-0) aggregate for one-shot phases that never ran', function (): void {
    $stats = new StatsTableTransferData;

    // Regression: a one-shot phase that did not execute must be skippable by
    // consumers (count === 0), not reported as having run in 0.0 ms.
    foreach ([TableRunPhase::CountingRows, TableRunPhase::DisableFkChecks, TableRunPhase::Clear] as $phase) {
        expect($stats->aggregate($phase)->count)->toBe(0);
    }
});

it('records a single-sample aggregate once a one-shot phase runs', function (): void {
    $stats = new StatsTableTransferData;
    $stats->setTotalRows(1000);
    $stats->recordClearTable(0.5);

    $cleared = $stats->aggregate(TableRunPhase::Clear);
    expect($cleared->count)->toBe(1);
    expect($cleared->sum)->toBe(0.5);

    // A sibling one-shot phase that still never ran stays at count 0.
    expect($stats->aggregate(TableRunPhase::DisableFkChecks)->count)->toBe(0);
});

it('estimates remaining time from the latest loop pace', function (): void {
    $stats = new StatsTableTransferData;
    $stats->setTotalRows(100);
    $stats->recordLoop(new StatsLoopData(
        loopIndex: 0,
        chunkRows: 10,
        selectSeconds: 0.2,
        transformSeconds: 0.1,
        insertSeconds: 0.7,
        overallSeconds: 1.0,
        rowsDone: 10,
        rowsSkipped: 0,
        totalRows: 100,
    ));

    // 90 rows remaining × (1.0s / 10 rows) = 9.0s.
    expect($stats->estimatedSecondsRemaining)->toBe(9.0);
});

it('reports a zero ETA when nothing remains or no pace is known yet', function (): void {
    $stats = new StatsTableTransferData;
    expect($stats->estimatedSecondsRemaining)->toBe(0.0); // nothing to do

    $stats->setTotalRows(100);
    expect($stats->estimatedSecondsRemaining)->toBe(0.0); // rows remain but no completed loop → no pace
});
