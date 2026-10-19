<?php

declare(strict_types=1);

namespace App\Data\Cloning;

final readonly class TableRunResultData
{
    /**
     * @param  list<string>  $notes  Human-readable remarks for the audit log
     *                               (e.g. columns left out of the transfer).
     */
    public function __construct(
        public string $tableName,
        public TableRunStatus $status,
        public int $rowsTransferred,
        public int $rowsSkipped,
        public float $durationSeconds,
        public ?string $failureReason,
        public array $notes = [],
    ) {}
}
