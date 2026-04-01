<?php

declare(strict_types=1);

namespace App\Data\Audit;

final readonly class AuditColumnRecordData
{
    public function __construct(
        public string $columnName,
        public string $strategy,
    ) {}
}
