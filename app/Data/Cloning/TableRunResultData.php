<?php

declare(strict_types=1);

namespace App\Data\Cloning;

final readonly class TableRunResultData
{
    public function __construct(
        public string $tableName,
        public TableRunStatus $status,
        public int $rowsTransferred,
        public int $rowsSkipped,
        public float $durationSeconds,
        public ?string $failureReason,
    ) {}
}
