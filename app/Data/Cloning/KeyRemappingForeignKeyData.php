<?php

declare(strict_types=1);

namespace App\Data\Cloning;

/**
 * A column that points at a remapped primary key.
 *
 * For a polymorphic relation (e.g. Laravel's `addressable_type` +
 * `addressable_id`), `typeColumn` and `typeValue` limit the rewrite to the
 * rows whose type column holds `typeValue`.
 */
final readonly class KeyRemappingForeignKeyData
{
    public function __construct(
        public string $table,
        public string $column,
        public bool $selfReferential = false,
        public ?string $typeColumn = null,
        public ?string $typeValue = null,
    ) {}

    public function isPolymorphic(): bool
    {
        return $this->typeColumn !== null;
    }

    /**
     * Whether this foreign key applies to the row: always for a plain foreign
     * key, and only on a type match for a polymorphic one.
     *
     * @param  array<string, mixed>  $row
     */
    public function appliesTo(array $row): bool
    {
        if ($this->typeColumn === null) {
            return true;
        }

        $type = $row[$this->typeColumn] ?? null;

        return is_scalar($type) && (string) $type === $this->typeValue;
    }
}
