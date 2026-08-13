<?php

declare(strict_types=1);

namespace App\Data\Cloning;

/**
 * Snapshot of a single chunk-loop iteration inside `transferTable()`.
 *
 * `rowsDone` and `rowsSkipped` count rows for **this loop only**, not
 * cumulative — inspect the parent `StatsTableTransferData` for
 * cumulative progress.
 */
final readonly class StatsLoopData
{
    public function __construct(
        public int $loopIndex,
        public int $chunkRows,
        public float $selectSeconds,
        public float $transformSeconds,
        public float $insertSeconds,
        public float $overallSeconds,
        public int $rowsDone,
        public int $rowsSkipped,
        public int $totalRows,
    ) {}
}
