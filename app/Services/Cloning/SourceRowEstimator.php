<?php

declare(strict_types=1);

namespace App\Services\Cloning;

use App\Data\Cloning\TableCloningConfigData;
use App\Enums\DatabaseConnectionType;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Best-effort expected row total of a source table, respecting the row
 * strategy limit. Shared by the progress totals of a run and by --dry-run.
 *
 * Server databases answer from catalog statistics, so a large production
 * table is never scanned; the figure is then an estimate that can be off in
 * either direction. SQLite keeps no such statistics and counts, bounded by
 * the row limit of a 'first' / 'last' table.
 */
final readonly class SourceRowEstimator
{
    /**
     * @return array{?int, bool} [rows, isEstimate]; rows is null when unknown
     *                           (query failed, unreadable result, or a
     *                           PostgreSQL table that was never analyzed)
     */
    public function estimate(string $connName, TableCloningConfigData $config, DatabaseConnectionType $driver): array
    {
        $limited = $config->rows->strategy !== 'full' && $config->rows->limit !== null;

        [$sql, $bindings, $isEstimate] = match ($driver) {
            DatabaseConnectionType::Mysql, DatabaseConnectionType::MariaDB => [
                'SELECT TABLE_ROWS AS c FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
                [$config->tableName],
                true,
            ],
            DatabaseConnectionType::PostgreSQL => [
                'SELECT c.reltuples::bigint AS c FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace WHERE n.nspname = current_schema() AND c.relname = ?',
                [$config->tableName],
                true,
            ],
            DatabaseConnectionType::SqlServer => [
                'SELECT SUM(p.rows) AS c FROM sys.partitions p WHERE p.object_id = OBJECT_ID(?) AND p.index_id IN (0, 1)',
                [$config->tableName],
                true,
            ],
            // Bounded by the row limit, so a 'first' / 'last' table is not counted in full.
            default => [
                $limited
                    ? sprintf('SELECT COUNT(*) AS c FROM (SELECT 1 FROM "%s" LIMIT %d)', $config->tableName, $config->rows->limit)
                    : sprintf('SELECT COUNT(*) AS c FROM "%s"', $config->tableName),
                [],
                false,
            ],
        };

        try {
            $result = DB::connection($connName)->selectOne($sql, $bindings);
        } catch (Throwable $throwable) {
            Log::warning('source_row_estimate_failed', ['table' => $config->tableName, 'error' => $throwable->getMessage()]);

            return [null, false];
        }

        $row = is_object($result) ? get_object_vars($result) : $result;
        $value = is_array($row) ? ($row['c'] ?? null) : null;

        // PostgreSQL reports -1 for a table that was never analyzed.
        if (! is_numeric($value) || (int) $value < 0) { // @pest-mutate-ignore: RemoveIntegerCast
            return [null, $isEstimate];
        }

        $count = (int) $value;

        // Mirrors the chunk query: only 'first' / 'last' honour the row limit.
        if ($limited) {
            $count = min((int) $config->rows->limit, $count); // @pest-mutate-ignore: RemoveIntegerCast
        }

        return [$count, $isEstimate];
    }
}
