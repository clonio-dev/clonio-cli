<?php

declare(strict_types=1);

namespace App\Services\Cloning;

interface KeyRemappingStoreInterface
{
    /**
     * Persist a full table's PK mapping.
     *
     * @param  array<string, string>  $mappings  [oldValue => newValue]
     */
    public function storeTable(string $table, array $mappings): void;

    /**
     * Look up the remapped value for an old PK/FK value.
     * Returns null when the value is not in the mapping.
     */
    public function lookup(string $table, string $oldValue): ?string;

    /**
     * Release all stored mappings and free any associated resources
     * (temp files, memory, etc.). Safe to call multiple times.
     */
    public function cleanup(): void;
}
