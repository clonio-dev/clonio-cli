<?php

declare(strict_types=1);

namespace App\Data\Cloning;

final readonly class TableDumpData
{
    /** @param list<ColumnDumpData> $columns */
    public function __construct(
        public string $name,
        public array $columns,
        public string $rowStrategy,
        public ?int $rowLimit,
        public ?string $sortBy,
    ) {}
}
