<?php

declare(strict_types=1);

namespace App\Data\Audit;

use App\Data\Cloning\CloningOptionsData;
use DateTimeImmutable;

final readonly class AuditRecordData
{
    /**
     * @param  list<AuditTableRecordData>  $tables
     * @param  list<string>  $channels
     */
    public function __construct(
        public string $clonioVersion,
        public string $sourceConnection,
        public string $targetConnection,
        public string $yamlFileName,
        public DateTimeImmutable $startedAt,
        public DateTimeImmutable $finishedAt,
        public bool $success,
        public CloningOptionsData $options,
        public array $tables,
        public int $totalRowsTransferred,
        public int $totalRowsSkipped,
        public array $channels,
        public string $contentHash,
        public string $hmacSignature,
    ) {}
}
