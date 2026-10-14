<?php

declare(strict_types=1);

namespace App\Data\Cloning;

final readonly class CloningOptionsData
{
    public function __construct(
        public int $chunkSize,
        public bool $enforceColumnTypes,
        public bool $dropUnknownTables,
        public bool $dropExtraColumns,
        public bool $disableForeignKeyChecks,
        public string $fakerLocale,
        public bool $remapKeys = true,
    ) {}
}
