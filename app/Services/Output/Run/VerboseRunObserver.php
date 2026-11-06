<?php

declare(strict_types=1);

namespace App\Services\Output\Run;

use App\Contracts\CloningRunObserver;
use App\Data\Cloning\StatsTableTransferData;
use App\Data\Cloning\TableRunStatus;
use App\Services\Cloning\SkippedRow;
use App\Services\Output\CloningProgressDisplay;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Prints one line per table for `-v` runs, plus the reasons for skipped
 * rows and, at `-vvv`, a timing summary. Subclasses decide how a table's
 * line starts and ends, and where the lines go.
 */
abstract class VerboseRunObserver implements CloningRunObserver
{
    /** Live stats of the table that is transferring; reset when it finishes. */
    private ?StatsTableTransferData $timings = null;

    public function __construct(private readonly bool $debug) {}

    public function started(int $tableCount): void {}

    public function tableProgress(string $tableName, StatsTableTransferData $stats): void
    {
        $this->timings = $stats;
    }

    public function tableFinished(string $tableName, TableRunStatus $status, int $rows, int $skipped, array $skippedRows): void
    {
        $timings = $this->timings;
        $this->timings = null;
        $sink = $this->sink();

        $this->finishTableLine(
            $tableName,
            $status,
            sprintf('(%s rows%s)', number_format($rows), $skipped > 0 ? ', '.$skipped.' skipped' : ''),
            $timings,
        );

        if ($status === TableRunStatus::NotFound) {
            $sink->writeln(sprintf('  <comment>?</comment>  %s  — not found in source, skipped', $tableName));
        } elseif ($status === TableRunStatus::SkippedBySchemaFailure) {
            $sink->writeln(sprintf('  <error>S</error>  %s  — schema replication failed, skipped', $tableName));
        }

        if ($status !== TableRunStatus::Transferred && $status !== TableRunStatus::Failed) {
            return;
        }

        $this->renderSkipGroups($sink, $skippedRows);

        if ($this->debug && $timings instanceof StatsTableTransferData) {
            CloningProgressDisplay::renderTimingSummary($sink, $timings);
        }
    }

    public function finished(): void {}

    /**
     * Where the per-table detail lines go.
     */
    abstract protected function sink(): OutputInterface;

    /**
     * Ends the line that tableStarted() opened, for copied and failed tables.
     */
    abstract protected function finishTableLine(string $tableName, TableRunStatus $status, string $rowsSummary, ?StatsTableTransferData $timings): void;

    /**
     * Prints the reasons rows were skipped, the most frequent first, at most ten.
     *
     * @param  list<SkippedRow>  $skippedRows
     */
    private function renderSkipGroups(OutputInterface $sink, array $skippedRows): void
    {
        $byMessage = [];

        foreach ($skippedRows as $skippedRow) {
            $byMessage[$skippedRow->sqlError] = ($byMessage[$skippedRow->sqlError] ?? 0) + 1;
        }

        arsort($byMessage);

        foreach (array_slice($byMessage, 0, 10, true) as $message => $count) {
            $sink->writeln(sprintf('     └ %d× %s', $count, $message));
        }

        $rest = count($byMessage) - 10;

        if ($rest > 0) {
            $sink->writeln(sprintf('     └ … and %d more error types', $rest));
        }
    }
}
