<?php

declare(strict_types=1);

namespace App\Data\Cloning;

/**
 * Mutable running aggregate for a single per-chunk timing phase. Updated
 * in O(1) via `record()`; derived throughput figures are exposed as
 * virtual (computed) properties.
 */
final class StatsPhaseAggregateData
{
    public private(set) int $count = 0;

    public private(set) float $sum = 0.0;

    public private(set) ?float $min = null;

    public private(set) ?float $max = null;

    public private(set) ?float $last = null;

    public private(set) ?int $lastRows = null;

    public private(set) int $rowsProcessed = 0;

    /** Mean seconds per sample, or null when no sample has been recorded. */
    public ?float $averageSeconds {
        get => $this->count > 0 ? $this->sum / $this->count : null;
    }

    /**
     * Aggregate seconds per row across all samples,
     * or null when no rows have been processed.
     */
    public ?float $pace {
        get => $this->rowsProcessed > 0
            ? ($this->sum / $this->rowsProcessed)
            : null;
    }

    /**
     * Aggregate seconds per 1,000,000 rows across all samples,
     * or null when no rows have been processed.
     */
    public ?float $pacePerMillion {
        get => ($pace = $this->pace) !== null
            ? $pace * 1_000_000.0
            : null;
    }

    public ?float $latestPace {
        get => $this->last !== null && $this->lastRows !== null && $this->lastRows > 0
            ? $this->last / $this->lastRows
            : null;
    }

    /** Latest-sample seconds per 1,000,000 rows, or null when unavailable. */
    public ?float $latestPacePerMillion {
        get => ($pace = $this->latestPace) !== null
            ? $pace * 1_000_000.0
            : null;
    }

    public static function withRecord(float $seconds, int $rows): self
    {
        $instance = new self;

        $instance->record($seconds, $rows);

        return $instance;
    }

    public function record(float $seconds, int $rows): void
    {
        $this->count++;
        $this->sum += $seconds;
        $this->min = $this->min === null ? $seconds : min($this->min, $seconds);
        $this->max = $this->max === null ? $seconds : max($this->max, $seconds);
        $this->last = $seconds;
        $this->lastRows = $rows;
        $this->rowsProcessed += max(0, $rows);
    }
}
