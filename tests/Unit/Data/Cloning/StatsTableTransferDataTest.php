<?php

declare(strict_types=1);

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
