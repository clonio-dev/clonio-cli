<?php

declare(strict_types=1);

namespace App\Data\Cloning;

/**
 * A `<name>_type` + `<name>_id` column pair that may hold a polymorphic
 * relation, e.g. Laravel's `addressable_type` + `addressable_id`.
 */
final readonly class PolymorphicRelationData
{
    public function __construct(
        public string $table,
        public string $typeColumn,
        public string $idColumn,
    ) {}
}
