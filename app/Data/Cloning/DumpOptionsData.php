<?php

declare(strict_types=1);

namespace App\Data\Cloning;

final readonly class DumpOptionsData
{
    public function __construct(
        public string $connectionName,
        public string $outputPath,
        public bool $force,
        public bool $onlyPii,
        public string $fakerLocale,
    ) {}
}
