<?php

declare(strict_types=1);

namespace App\Data\Cloning;

final readonly class DumpResultData
{
    /** @param list<TableDumpData> $tables */
    public function __construct(
        public string $connectionName,
        public array $tables,
        public int $totalColumns,
        public int $piiColumnsDetected,
        public string $outputPath,
        public string $fakerLocale = 'en_US',
    ) {}
}
