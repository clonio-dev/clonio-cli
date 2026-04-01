<?php

declare(strict_types=1);

namespace App\Data\Cloning;

final readonly class DryRunResultData
{
    /** @param list<DryRunTableData> $tables */
    public function __construct(
        public array $tables,
        public int $totalEstimatedRows,
        public int $notFoundCount,
    ) {}
}
