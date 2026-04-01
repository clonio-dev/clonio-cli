<?php

declare(strict_types=1);

namespace App\Data\Schema;

final readonly class ForeignKeyData
{
    public function __construct(
        public string $columnName,
        public string $referencedTable,
        public string $referencedColumn,
    ) {}
}
