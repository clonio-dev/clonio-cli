<?php

declare(strict_types=1);

namespace App\Data\Cloning;

use App\Services\Cloning\KeyRemappingService;
use App\Services\SqlDump\SqlDumpService;

/**
 * Optional settings for a single CloningRunOrchestrator::run() call.
 */
final readonly class CloningRunSettingsData
{
    /**
     * @param  bool  $skipSchema  When true, the target schema is not replicated (a dump still writes its header, without DDL).
     * @param  list<string>  $skipTables  Tables to exclude (already validated as mutually exclusive with onlyTables)
     * @param  list<string>  $onlyTables  If non-empty, only these tables are transferred
     * @param  KeyRemappingService|null  $keyRemapping  Optional. Rewrites primary/foreign keys per the config's key remapping.
     * @param  bool  $breakOnFailure  When true, the run stops after the first failed table.
     * @param  SqlDumpService|null  $dumpSink  Optional. When set, rows and DDL go to a .sql dump instead of a live target.
     * @param  bool  $trackRowTotals  When true, look up the expected row total per table (respecting the row limit) to size per-table progress: a catalog-statistics estimate on server databases, so the production source is never scanned, and `COUNT(*)` on SQLite. Off by default so non-interactive runs don't pay for a lookup nobody consumes.
     */
    public function __construct(
        public bool $skipSchema = false,
        public array $skipTables = [],
        public array $onlyTables = [],
        public ?KeyRemappingService $keyRemapping = null,
        public bool $breakOnFailure = false,
        public ?SqlDumpService $dumpSink = null,
        public bool $trackRowTotals = false,
    ) {}
}
