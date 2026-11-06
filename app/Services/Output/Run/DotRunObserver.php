<?php

declare(strict_types=1);

namespace App\Services\Output\Run;

use App\Contracts\CloningRunObserver;
use App\Data\Cloning\StatsTableTransferData;
use App\Data\Cloning\TableRunStatus;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Prints one character per finished table, for runs without `-v`:
 * `.` copied, `F` copied with skipped rows, `E` failed, `?` not found,
 * `S` schema failed. Wraps after 70 characters.
 */
final class DotRunObserver implements CloningRunObserver
{
    private const int MAX_COLUMNS = 70;

    private int $column = 0;

    public function __construct(private readonly OutputInterface $output) {}

    public function started(int $tableCount): void {}

    public function tableStarted(string $tableName): void {}

    public function tableProgress(string $tableName, StatsTableTransferData $stats): void {}

    public function tableFinished(string $tableName, TableRunStatus $status, int $rows, int $skipped, array $skippedRows): void
    {
        $indicator = match ($status) {
            TableRunStatus::Transferred => $skipped > 0 ? 'F' : '.',
            TableRunStatus::Failed => 'E',
            TableRunStatus::NotFound => '?',
            TableRunStatus::SkippedBySchemaFailure => 'S',
            default => null,
        };

        if ($indicator === null) {
            return;
        }

        $this->output->write($indicator);
        $this->column++;

        if ($this->column >= self::MAX_COLUMNS) {
            $this->output->writeln('');
            $this->column = 0;
        }
    }

    public function finished(): void
    {
        if ($this->column > 0) {
            $this->output->writeln('');
        }
    }
}
