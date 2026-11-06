<?php

declare(strict_types=1);

namespace App\Data\Cloning;

/**
 * Polymorphic foreign keys found in the source data.
 */
final readonly class PolymorphicDetectionData
{
    /**
     * @param  array<string, list<KeyRemappingForeignKeyData>>  $foreignKeysByTable  the foreign keys, keyed by the table they point at
     * @param  list<string>  $unresolved  type values that match no remapped table, e.g. `addresses.addressable_type = 'team'`
     */
    public function __construct(
        public array $foreignKeysByTable = [],
        public array $unresolved = [],
    ) {}
}
