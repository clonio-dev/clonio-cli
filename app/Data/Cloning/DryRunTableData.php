<?php

declare(strict_types=1);

namespace App\Data\Cloning;

final readonly class DryRunTableData
{
    /** @param list<string> $transformedColumns */
    public function __construct(
        public string $tableName,
        public bool $exists,
        public ?int $estimatedRows,
        public string $rowStrategy,
        public ?int $rowLimit,
        public array $transformedColumns,
    ) {}
}
