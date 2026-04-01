<?php

declare(strict_types=1);

namespace App\Data\Cloning;

final readonly class MatchersUpdateDiffData
{
    /**
     * @param  list<NewMatcherEntryData>  $additions
     * @param  list<OrphanedMatcherEntryData>  $orphans
     */
    public function __construct(
        public array $additions,
        public array $orphans,
        public bool $hasChanges,
    ) {}
}
