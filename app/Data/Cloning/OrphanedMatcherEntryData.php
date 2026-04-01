<?php

declare(strict_types=1);

namespace App\Data\Cloning;

final readonly class OrphanedMatcherEntryData
{
    public function __construct(
        public string $groupKey,
        public string $matcherKey,
        public string $matcherName,
    ) {}
}
