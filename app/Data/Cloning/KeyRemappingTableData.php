<?php

declare(strict_types=1);

namespace App\Data\Cloning;

use App\Enums\KeyRemappingStrategy;

final readonly class KeyRemappingTableData
{
    /** @param list<KeyRemappingForeignKeyData> $foreignKeys */
    public function __construct(
        public string $table,
        public string $primaryKey,
        public KeyRemappingStrategy $strategy,
        public array $foreignKeys,
    ) {}
}
