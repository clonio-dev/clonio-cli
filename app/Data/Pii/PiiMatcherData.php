<?php

declare(strict_types=1);

namespace App\Data\Pii;

use App\Data\Cloning\ColumnCloningConfigData;

final readonly class PiiMatcherData
{
    /** @param list<string> $patterns */
    public function __construct(
        public string $key,
        public string $group,
        public string $name,
        public bool $enabled,
        public array $patterns,
        public ColumnCloningConfigData $transformation,
        public bool $isBaseline,
        public ?string $exampleValue = null,
    ) {}
}
