<?php

declare(strict_types=1);

namespace App\Services\Cloning;

use App\Contracts\CloningRunObserver;
use App\Data\Cloning\StatsTableTransferData;
use App\Data\Cloning\TableRunStatus;

/**
 * Ignores every event, for runs without progress output (CI mode).
 */
final class NullCloningRunObserver implements CloningRunObserver
{
    public function started(int $tableCount): void {}

    public function tableStarted(string $tableName): void {}

    public function tableProgress(string $tableName, StatsTableTransferData $stats): void {}

    public function tableFinished(string $tableName, TableRunStatus $status, int $rows, int $skipped, array $skippedRows): void {}

    public function finished(): void {}
}
