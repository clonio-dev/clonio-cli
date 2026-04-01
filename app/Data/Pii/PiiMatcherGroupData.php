<?php

declare(strict_types=1);

namespace App\Data\Pii;

final readonly class PiiMatcherGroupData
{
    /** @param list<PiiMatcherData> $matchers */
    public function __construct(
        public string $key,
        public string $name,
        public array $matchers,
    ) {}
}
