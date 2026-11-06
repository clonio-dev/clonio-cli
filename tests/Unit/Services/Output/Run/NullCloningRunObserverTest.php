<?php

declare(strict_types=1);

use App\Data\Cloning\StatsTableTransferData;
use App\Data\Cloning\TableRunStatus;
use App\Services\Cloning\NullCloningRunObserver;

it('accepts every event without doing anything', function (): void {
    $observer = new NullCloningRunObserver;

    $observer->started(1);
    $observer->tableStarted('users');
    $observer->tableProgress('users', new StatsTableTransferData);
    $observer->tableFinished('users', TableRunStatus::Transferred, 1, 0, []);
    $observer->finished();

    expect($observer)->toBeInstanceOf(NullCloningRunObserver::class);
});
