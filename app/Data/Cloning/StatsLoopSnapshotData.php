<?php

declare(strict_types=1);

namespace App\Data\Cloning;

/**
 * Immutable scalar snapshot captured after a chunk loop is recorded.
 * Consumers can iterate `StatsTableTransferData::$statsOverTime` to observe
 * how cumulative progress and per-phase throughput evolve across loops.
 *
 * Only scalars are stored (not references to the mutable aggregates), so a
 * snapshot is intrinsically immutable and cheap to retain per loop.
 */
final readonly class StatsLoopSnapshotData
{
    public function __construct(
        public int $loopIndex,
        public int $loopsRecorded,
        public int $rowsDoneCumulative,
        public int $rowsSkippedCumulative,
        public ?float $percentComplete,
        public ?float $selectPacePerMillion,
        public ?float $transformPacePerMillion,
        public ?float $insertPacePerMillion,
        public ?float $loopPacePerMillion,
    ) {}
}
