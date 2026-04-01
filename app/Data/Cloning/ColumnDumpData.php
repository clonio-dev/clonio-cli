<?php

declare(strict_types=1);

namespace App\Data\Cloning;

final readonly class ColumnDumpData
{
    /** @param list<scalar> $fakerArguments */
    public function __construct(
        public string $name,
        public string $strategy,
        public ?string $fakerMethod,
        public array $fakerArguments,
        public ?string $maskChar,
        public ?int $visibleChars,
        public ?bool $preserveFormat,
        public ?string $hashAlgorithm,
        public ?string $hashSalt,
        public ?string $staticValue,
        public bool $piiDetected,
        public ?string $piiCategory,
    ) {}
}
