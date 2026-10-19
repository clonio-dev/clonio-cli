<?php

declare(strict_types=1);

namespace App\Data\Audit;

final readonly class AuditTableRecordData
{
    /**
     * @param  list<AuditColumnRecordData>  $transformedColumns
     * @param  list<string>  $notes  Remarks such as columns left out of the transfer
     */
    public function __construct(
        public string $tableName,
        public bool $existed,
        public bool $skippedByFlag,
        public string $rowStrategy,
        public ?int $rowLimit,
        public int $rowsTransferred,
        public int $rowsSkipped,
        public float $durationSeconds,
        public array $transformedColumns,
        public int $keptColumnCount,
        public array $notes = [],
    ) {}
}
