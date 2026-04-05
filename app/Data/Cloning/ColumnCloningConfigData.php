<?php

declare(strict_types=1);

namespace App\Data\Cloning;

final readonly class ColumnCloningConfigData
{
    /**
     * @param  list<scalar>  $fakerArguments
     * @param  list<KeyRemappingForeignKeyData>|null  $remappingForeignKeys
     */
    public function __construct(
        public string $columnName,
        public string $strategy,
        public ?string $fakerMethod,
        public array $fakerArguments,
        public ?string $hashAlgorithm,
        public ?string $hashSalt,
        public ?string $maskChar,
        public ?int $visibleChars,
        public ?bool $preserveFormat,
        public ?string $staticValue,
        public ?string $remappingUse = null,
        public ?int $remappingMin = null,
        public ?int $remappingMax = null,
        public ?array $remappingForeignKeys = null,
    ) {}
}
