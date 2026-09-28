<?php

declare(strict_types=1);

namespace App\Data\Cloning;

use Illuminate\Support\Collection;

/**
 * Per-table timing container. One instance travels through every chunk
 * loop of a single `transferTable()` invocation.
 *
 * Loops are pushed via `recordLoop(StatsLoopData)`; per-phase running
 * aggregates (`selectAggregate` / `transformAggregate` / `insertAggregate`)
 * are updated in O(1) so the derived throughput figures never re-scan
 * `$loops`.
 */
final class StatsTableTransferData
{
    public private(set) ?TableRunPhase $status = null;

    /** Wall-clock seconds for each one-shot phase; null until (and unless) it runs. */
    public private(set) ?float $countingRowsSeconds = null;

    public private(set) ?float $disableFkSeconds = null;

    public private(set) ?float $clearTableSeconds = null;

    /** @var Collection<int, StatsLoopData> */
    public private(set) Collection $loops;

    /** @var Collection<int, StatsLoopSnapshotData> */
    public private(set) Collection $statsOverTime;

    public private(set) int $totalRows = 0;

    public private(set) int $rowsDone = 0;

    public private(set) int $rowsSkipped = 0;

    /** Rows accounted for so far (transferred + skipped). */
    public int $rowsProcessed {
        get => $this->rowsDone + $this->rowsSkipped;
    }

    /** Rows still outstanding against `totalRows` (never negative). */
    public int $rowsRemaining {
        get => max(0, $this->totalRows - $this->rowsProcessed);
    }

    /** Completion ratio in the range [0, 100], or null when the total is unknown. */
    public ?float $percentComplete {
        get => $this->totalRows > 0
            ? min(100.0, ($this->rowsProcessed / $this->totalRows) * 100.0)
            : null;
    }

    /**
     * Estimated wall-clock seconds until this table finishes, from the latest
     * loop pace × outstanding rows. 0.0 once no rows remain, and 0.0 until a
     * row total and at least one completed loop are known.
     */
    public float $estimatedSecondsRemaining {
        get {
            if ($this->rowsRemaining <= 0) {
                return 0.0;
            }

            return $this->loopAggregate->latestPace * $this->rowsRemaining;
        }
    }

    public private(set) StatsPhaseAggregateData $selectAggregate;

    public private(set) StatsPhaseAggregateData $transformAggregate;

    public private(set) StatsPhaseAggregateData $insertAggregate;

    /**
     * Per-loop aggregate over each chunk's wall-clock time
     * (`StatsLoopData::$overallSeconds`, rows = chunkRows). Useful for
     * loop throughput including inter-phase overhead.
     */
    public private(set) StatsPhaseAggregateData $loopAggregate;

    public function __construct()
    {
        $this->loops = new Collection;
        $this->statsOverTime = new Collection;
        $this->selectAggregate = new StatsPhaseAggregateData;
        $this->transformAggregate = new StatsPhaseAggregateData;
        $this->insertAggregate = new StatsPhaseAggregateData;
        $this->loopAggregate = new StatsPhaseAggregateData;
    }

    public function setStatus(TableRunPhase $status): void
    {
        $this->status = $status;
    }

    public function setTotalRows(int $totalRows): void
    {
        $this->totalRows = max(0, $totalRows);
    }

    public function recordCountingRows(float $seconds): void
    {
        $this->countingRowsSeconds = $seconds;
    }

    public function recordDisableFk(float $seconds): void
    {
        $this->disableFkSeconds = $seconds;
    }

    public function recordClearTable(float $seconds): void
    {
        $this->clearTableSeconds = $seconds;
    }

    /**
     * Append a completed chunk loop, update running aggregates in O(1),
     * and append a stats-over-time snapshot.
     */
    public function recordLoop(StatsLoopData $loop): void
    {
        $this->loops->push($loop);

        $this->selectAggregate->record($loop->selectSeconds, $loop->chunkRows);
        $this->transformAggregate->record($loop->transformSeconds, $loop->chunkRows);
        $this->insertAggregate->record($loop->insertSeconds, $loop->chunkRows);
        $this->loopAggregate->record($loop->overallSeconds, $loop->chunkRows);

        $this->rowsDone += $loop->rowsDone;
        $this->rowsSkipped += $loop->rowsSkipped;

        $this->statsOverTime->push(new StatsLoopSnapshotData(
            loopIndex: $loop->loopIndex,
            loopsRecorded: $this->loops->count(),
            rowsDoneCumulative: $this->rowsDone,
            rowsSkippedCumulative: $this->rowsSkipped,
            percentComplete: $this->percentComplete,
            selectPacePerMillion: $this->selectAggregate->pacePerMillion,
            transformPacePerMillion: $this->transformAggregate->pacePerMillion,
            insertPacePerMillion: $this->insertAggregate->pacePerMillion,
            loopPacePerMillion: $this->loopAggregate->pacePerMillion,
        ));
    }

    public function aggregate(TableRunPhase $phase): StatsPhaseAggregateData
    {
        return match ($phase) {
            TableRunPhase::CountingRows => $this->oneShotAggregate($this->countingRowsSeconds),
            TableRunPhase::DisableFkChecks => $this->oneShotAggregate($this->disableFkSeconds),
            TableRunPhase::Clear => $this->oneShotAggregate($this->clearTableSeconds),
            TableRunPhase::Select => $this->selectAggregate,
            TableRunPhase::Transform => $this->transformAggregate,
            TableRunPhase::Insert => $this->insertAggregate,
            TableRunPhase::Loop => $this->loopAggregate,
        };
    }

    /**
     * Wrap a one-shot phase duration as a single-sample aggregate. A phase that
     * never ran (null) yields an empty (count-0) aggregate so consumers can skip it.
     */
    private function oneShotAggregate(?float $seconds): StatsPhaseAggregateData
    {
        return $seconds === null
            ? new StatsPhaseAggregateData
            : StatsPhaseAggregateData::withRecord($seconds, $this->totalRows);
    }
}
