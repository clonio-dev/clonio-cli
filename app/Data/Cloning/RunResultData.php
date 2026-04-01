<?php

declare(strict_types=1);

namespace App\Data\Cloning;

final readonly class RunResultData
{
    /** @param list<TableRunResultData> $tables */
    public function __construct(
        public bool $success,
        public array $tables,
        public int $totalRows,
        public int $skippedRows,
        public float $durationSeconds,
        public ?string $failureReason,
    ) {}
}
