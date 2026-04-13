<?php

declare(strict_types=1);

namespace App\Data\Cloning;

enum TableRunStatus: string
{
    case Transferred = 'transferred';
    case SkippedByFlag = 'skipped_by_flag';
    case SkippedByCascade = 'skipped_by_cascade';
    case NotFound = 'not_found';
    case SkippedBySchemaFailure = 'skipped_by_schema_failure';
    case Failed = 'failed';
}
