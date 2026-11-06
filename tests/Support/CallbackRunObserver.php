<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Contracts\CloningRunObserver;
use App\Data\Cloning\StatsTableTransferData;
use App\Data\Cloning\TableRunStatus;
use App\Services\Cloning\SkippedRow;
use Closure;

/**
 * A run observer for tests that forwards each event to an optional closure.
 */
final readonly class CallbackRunObserver implements CloningRunObserver
{
    /**
     * @param  (Closure(string, TableRunStatus, int, int, list<SkippedRow>): void)|null  $onTableFinished
     * @param  (Closure(int): void)|null  $onStarted
     * @param  (Closure(string): void)|null  $onTableStarted
     * @param  (Closure(string, StatsTableTransferData): void)|null  $onTableProgress
     * @param  (Closure(): void)|null  $onFinished
     */
    public function __construct(
        private ?Closure $onTableFinished = null,
        private ?Closure $onStarted = null,
        private ?Closure $onTableStarted = null,
        private ?Closure $onTableProgress = null,
        private ?Closure $onFinished = null,
    ) {}

    public function started(int $tableCount): void
    {
        if ($this->onStarted instanceof Closure) {
            ($this->onStarted)($tableCount);
        }
    }

    public function tableStarted(string $tableName): void
    {
        if ($this->onTableStarted instanceof Closure) {
            ($this->onTableStarted)($tableName);
        }
    }

    public function tableProgress(string $tableName, StatsTableTransferData $stats): void
    {
        if ($this->onTableProgress instanceof Closure) {
            ($this->onTableProgress)($tableName, $stats);
        }
    }

    public function tableFinished(string $tableName, TableRunStatus $status, int $rows, int $skipped, array $skippedRows): void
    {
        if ($this->onTableFinished instanceof Closure) {
            ($this->onTableFinished)($tableName, $status, $rows, $skipped, $skippedRows);
        }
    }

    public function finished(): void
    {
        if ($this->onFinished instanceof Closure) {
            ($this->onFinished)();
        }
    }
}
