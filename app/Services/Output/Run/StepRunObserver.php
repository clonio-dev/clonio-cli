<?php

declare(strict_types=1);

namespace App\Services\Output\Run;

use App\Data\Cloning\StatsTableTransferData;
use App\Data\Cloning\TableRunStatus;
use App\Services\Output\VerboseStepRenderer;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Verbose output as step lines (`  users ....... ✓ (3 rows)`), for `-v`
 * runs without live progress bars.
 */
final class StepRunObserver extends VerboseRunObserver
{
    public function __construct(
        private readonly VerboseStepRenderer $step,
        private readonly OutputInterface $output,
        bool $debug,
    ) {
        parent::__construct($debug);
    }

    public function tableStarted(string $tableName): void
    {
        $this->step->start('  '.$tableName);
    }

    protected function sink(): OutputInterface
    {
        return $this->output;
    }

    protected function finishTableLine(string $tableName, TableRunStatus $status, string $rowsSummary, ?StatsTableTransferData $timings): void
    {
        if ($status === TableRunStatus::Transferred) {
            $this->step->success($rowsSummary);
        } elseif ($status === TableRunStatus::Failed) {
            $this->step->fail();
        }
    }
}
