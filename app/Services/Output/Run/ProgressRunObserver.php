<?php

declare(strict_types=1);

namespace App\Services\Output\Run;

use App\Data\Cloning\StatsTableTransferData;
use App\Data\Cloning\TableRunStatus;
use App\Services\Output\CloningProgressDisplay;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Verbose output with live progress bars, for `-v` runs on a terminal. The
 * per-table lines scroll in the display's log section above the bars.
 */
final class ProgressRunObserver extends VerboseRunObserver
{
    public function __construct(private readonly CloningProgressDisplay $progress, bool $debug)
    {
        parent::__construct($debug);
    }

    public function started(int $tableCount): void
    {
        $this->progress->start($tableCount);
    }

    public function tableStarted(string $tableName): void
    {
        $this->progress->startTable($tableName);
    }

    public function tableProgress(string $tableName, StatsTableTransferData $stats): void
    {
        parent::tableProgress($tableName, $stats);
        $this->progress->advanceTable($stats);
    }

    public function tableFinished(string $tableName, TableRunStatus $status, int $rows, int $skipped, array $skippedRows): void
    {
        parent::tableFinished($tableName, $status, $rows, $skipped, $skippedRows);
        $this->progress->advanceOverall();
    }

    public function finished(): void
    {
        $this->progress->finish();
    }

    protected function sink(): OutputInterface
    {
        return $this->progress->log;
    }

    protected function finishTableLine(string $tableName, TableRunStatus $status, string $rowsSummary, ?StatsTableTransferData $timings): void
    {
        // Only copied and failed tables started a per-table bar.
        if ($status === TableRunStatus::Transferred) {
            $this->progress->finishTable(true, $timings);
            $this->progress->log->writeln(sprintf('  <info>✓</info>  %s  %s', $tableName, $rowsSummary));
        } elseif ($status === TableRunStatus::Failed) {
            $this->progress->finishTable(false, $timings);
            $this->progress->log->writeln(sprintf('  <error>✗</error>  %s  — transfer failed', $tableName));
        }
    }
}
