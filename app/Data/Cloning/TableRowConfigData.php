<?php

declare(strict_types=1);

namespace App\Data\Cloning;

use App\Enums\ClearMode;

final readonly class TableRowConfigData
{
    public function __construct(
        public string $strategy,
        public ?int $limit,
        public ?string $sortBy,
        public ClearMode $clear = ClearMode::None,
    ) {}
}
