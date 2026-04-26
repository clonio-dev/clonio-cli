<?php

declare(strict_types=1);

namespace App\Services\Cloning;

final readonly class SkippedRow
{
    /**
     * @param  array<string, mixed>|null  $pkSnapshot
     */
    public function __construct(
        public string $tableName,
        public int $chunkOffset,
        public int $rowIndex,
        public ?array $pkSnapshot,
        public string $sqlError,
    ) {}
}
