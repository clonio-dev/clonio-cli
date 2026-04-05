<?php

declare(strict_types=1);

namespace App\Data\Schema;

final readonly class ColumnDiffData
{
    public function __construct(
        public string $name,
        public string $sourceType,
        public string $targetType,
        public bool $sourceNullable,
        public bool $targetNullable,
    ) {}
}
