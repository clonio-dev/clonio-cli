<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Data\Cloning\StatsTableTransferData;
use App\Data\Cloning\TableRunStatus;
use App\Services\Cloning\SkippedRow;

/**
 * Gets told what a CloningRunOrchestrator::run() call does, table by table.
 */
interface CloningRunObserver
{
    /**
     * Called once before the first table, with the number of tables the run
     * will attempt. When the run breaks off on a failure, fewer tables finish.
     */
    public function started(int $tableCount): void;

    /**
     * Called when a table starts to transfer. Not called for tables that are
     * not found in the source or whose schema failed; those only finish.
     */
    public function tableStarted(string $tableName): void;

    /**
     * Called while a table transfers: when each one-shot phase starts (row
     * total, FK checks, clear) and after every chunk. The same object is
     * passed for the whole table and holds its final figures when the table
     * finishes.
     */
    public function tableProgress(string $tableName, StatsTableTransferData $stats): void;

    /**
     * Called once per attempted table with its outcome.
     *
     * @param  list<SkippedRow>  $skippedRows
     */
    public function tableFinished(string $tableName, TableRunStatus $status, int $rows, int $skipped, array $skippedRows): void;

    /**
     * Called once when the run ends, also when it ends with an exception.
     */
    public function finished(): void;
}
