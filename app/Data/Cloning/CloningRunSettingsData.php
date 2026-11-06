<?php

declare(strict_types=1);

namespace App\Data\Cloning;

use App\Services\Cloning\KeyRemappingService;
use App\Services\SqlDump\SqlDumpService;
use Closure;

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
     * @param  (Closure(string): void)|null  $onTableStart  Optional. Fires once per table that enters `transferTable()`, regardless of whether the transfer ultimately succeeds (`Transferred`) or fails (`Failed`). Does NOT fire for tables resolved as `SkippedByFlag`, `SkippedByCascade`, `NotFound`, or `SkippedBySchemaFailure`.
     * @param  (Closure(int): void)|null  $onStart  Optional. Fires exactly once, before the transfer loop, with the number of tables the loop will iterate. Note: when `$breakOnFailure` aborts early, fewer terminal `onProgress` events fire than this count. Useful for sizing an overall progress bar.
     * @param  (Closure(string, StatsTableTransferData): void)|null  $onTableProgress  Optional. Fires while a table transfers: when each one-shot phase starts (row total, FK checks, clear) and after every chunk. The same live object is passed for the whole table and holds its final figures once the table's terminal `onProgress` event fires.
     */
    public function __construct(
        public bool $skipSchema = false,
        public array $skipTables = [],
        public array $onlyTables = [],
        public ?KeyRemappingService $keyRemapping = null,
        public bool $breakOnFailure = false,
        public ?SqlDumpService $dumpSink = null,
        public bool $trackRowTotals = false,
        public ?Closure $onTableStart = null,
        public ?Closure $onStart = null,
        public ?Closure $onTableProgress = null,
    ) {}
}
