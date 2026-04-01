<?php

declare(strict_types=1);

namespace App\Data\Cloning;

final readonly class NewMatcherEntryData
{
    public function __construct(
        public string $groupKey,
        public string $groupName,
        public string $matcherKey,
        public string $matcherName,
        public bool $groupIsNew,
    ) {}
}
