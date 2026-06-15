<?php

declare(strict_types=1);

namespace App\Data\SqlDump;

final readonly class DumpResultData
{
    public function __construct(
        public string $zipPath,
        public string $zipFileName,
        public string $sha256,
        public bool $encrypted,
        public int $zipBytes,
    ) {}
}
