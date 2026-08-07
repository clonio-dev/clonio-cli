<?php

declare(strict_types=1);

namespace App\Services\Cloning;

use App\Data\Cloning\CloningConfigData;
use App\Data\Cloning\CloningOptionsData;
use App\Data\Cloning\ColumnCloningConfigData;
use App\Data\Cloning\KeyRemappingConfigData;
use App\Data\Cloning\RunResultData;
use App\Data\Cloning\StatsLoopData;
use App\Data\Cloning\StatsTableTransferData;
use App\Data\Cloning\TableCloningConfigData;
use App\Data\Cloning\TableRunPhase;
use App\Data\Cloning\TableRunResultData;
use App\Data\Cloning\TableRunStatus;
use App\Data\ConnectionData;
use App\Data\Schema\ColumnSchemaData;
use App\Data\Schema\DatabaseSchemaData;
use App\Data\Schema\TableSchemaData;
use App\Enums\ClearMode;
use App\Enums\DatabaseConnectionType;
use App\Services\Database\DatabaseConnectionService;
use App\Services\SqlDump\SqlDumpService;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class CloningRunOrchestrator
{
    public function __construct(
        private readonly DatabaseConnectionService $connector,
        private readonly SchemaReplicator $replicator,
        private readonly DependencyResolver $resolver,
    ) {}

    /**
     * @param  list<string>  $skipTables  Tables to exclude (already validated as mutually exclusive with onlyTables)
     * @param  list<string>  $onlyTables  If non-empty, only these tables are transferred
     * @param  callable(string, TableRunStatus, int, int, list<SkippedRow>, ?StatsTableTransferData=): void  $onProgress
     * @param  (callable(string): void)|null  $onTableStart  Optional. Fires once per table that enters `transferTable()`, regardless of whether the transfer ultimately succeeds (`Transferred`) or fails (`Failed`). Does NOT fire for tables resolved as `SkippedByFlag`, `SkippedByCascade`, `NotFound`, or `SkippedBySchemaFailure`.
     * @param  (callable(int): void)|null  $onStart  Optional. Fires exactly once, before the transfer loop, with the number of tables the loop will iterate. Note: when `$breakOnFailure` aborts early, fewer terminal `onProgress` events fire than this count. Useful for sizing an overall progress bar.
     *
     * The `$onProgress` `$timings` argument is a live, mutable object reused across
     * every event for a table — read it synchronously inside the callback; do not
     * retain the reference expecting a point-in-time snapshot.
     * @param  bool  $trackRowTotals  When true, run a `SELECT COUNT(*)` per table (respecting the row limit) to size per-table progress. Off by default so non-interactive runs don't pay for a count nobody consumes.
     */
    public function run(
        CloningConfigData $config,
        ConnectionData $source,
        ConnectionData $target,
        DatabaseSchemaData $sourceSchema,
        bool $skipSchema,
        array $skipTables,
        array $onlyTables,
        callable $onProgress,
        ?KeyRemappingService $keyRemapping = null,
        bool $breakOnFailure = false,
        ?callable $onTableStart = null,
        ?SqlDumpService $dumpSink = null,
        ?callable $onStart = null,
        bool $trackRowTotals = false,
    ): RunResultData {
        $start = microtime(true);
        $tableNames = array_map(static fn (TableCloningConfigData $t): string => $t->tableName, $config->tables);

        // Compute excluded tables
        $explicitlyExcluded = [];

        if ($onlyTables !== []) {
            $explicitlyExcluded = array_values(array_filter($tableNames, static fn (string $t): bool => ! in_array($t, $onlyTables, true)));
        } else {
            $explicitlyExcluded = $skipTables;
        }

        // Compute cascade exclusions
        $cascadeExclusions = $this->resolver->computeCascadeExclusions($sourceSchema, $explicitlyExcluded, $tableNames);

        // Sort remaining tables
        $remaining = array_values(array_filter(
            $tableNames,
            static fn (string $t): bool => ! in_array($t, $explicitlyExcluded, true) && ! in_array($t, $cascadeExclusions, true)
        ));

        $sortedTables = $this->resolver->sort($sourceSchema, $remaining);

        // Announce the number of tables that will be attempted (each emits a
        // terminal onProgress event) so callers can size an overall progress bar.
        if ($onStart !== null) {
            $onStart(count($sortedTables));
        }

        // Replicate schema if not skipping
        /** @var array<string, string> $schemaFailures */
        $schemaFailures = [];

        if ($dumpSink instanceof SqlDumpService) {
            // Dump target: write header + DDL to the .sql file instead of a live PDO.
            $dumpSink->begin(
                $target,
                $source,
                $sourceSchema,
                $sortedTables,
                $config->options,
                new DateTimeImmutable('now'),
                ! $skipSchema,
            );
            Log::info('dump_schema_written', ['tables' => $sortedTables]);
        } elseif (! $skipSchema) {
            $schemaFailures = $this->replicator->replicate(
                $source,
                $target,
                $sourceSchema,
                $sortedTables,
                $config->options->enforceColumnTypes,
                $config->options->dropUnknownTables,
                $config->options->dropExtraColumns,
            );

            if ($schemaFailures === []) {
                Log::info('schema_replicated', ['tables' => $sortedTables]);
            } else {
                foreach ($schemaFailures as $failedTable => $errorMsg) {
                    Log::error('schema_table_failed', ['table' => $failedTable, 'error' => $errorMsg]);
                }
            }
        }

        // Single engine per run: shared per-run random salt across all tables
        // preserves intra-run hash joinability while defeating cross-run linkability.
        $engine = new AnonymizationEngine($config->options->fakerLocale);

        // Transfer data
        /** @var list<TableRunResultData> $tableResults */
        $tableResults = [];
        $totalRows = 0;
        $totalSkipped = 0;
        $success = true;

        // Add skipped tables to results
        foreach ($explicitlyExcluded as $tableName) {
            $reason = $onlyTables !== [] ? 'excluded by --only filter' : 'explicitly excluded via --skip';
            $tableResults[] = new TableRunResultData($tableName, TableRunStatus::SkippedByFlag, 0, 0, 0.0, $reason);
            Log::info('table_skipped_by_flag', ['table' => $tableName, 'reason' => $reason]);
        }

        foreach ($cascadeExclusions as $tableName) {
            $reason = 'excluded due to foreign key dependency';
            $tableResults[] = new TableRunResultData($tableName, TableRunStatus::SkippedByCascade, 0, 0, 0.0, $reason);
            Log::info('table_skipped_by_cascade', ['table' => $tableName, 'reason' => $reason]);
        }

        // Transfer each remaining table
        foreach ($sortedTables as $tableName) {
            $tableConfig = $config->getTable($tableName);

            if (! $tableConfig instanceof TableCloningConfigData) {
                continue;
            }

            // Skip data transfer for tables whose schema could not be created
            if (array_key_exists($tableName, $schemaFailures)) {
                $reason = $schemaFailures[$tableName];
                $tableResults[] = new TableRunResultData($tableName, TableRunStatus::SkippedBySchemaFailure, 0, 0, 0.0, $reason);
                Log::warning('table_skipped_schema_failure', ['table' => $tableName, 'reason' => $reason]);
                $success = false;
                ($onProgress)($tableName, TableRunStatus::SkippedBySchemaFailure, 0, 0, []);

                if ($breakOnFailure) {
                    break;
                }

                continue;
            }

            // Check if table exists in source
            if (! $sourceSchema->hasTable($tableName)) {
                $tableResults[] = new TableRunResultData($tableName, TableRunStatus::NotFound, 0, 0, 0.0, null);
                Log::warning('table_not_found', ['table' => $tableName]);
                ($onProgress)($tableName, TableRunStatus::NotFound, 0, 0, []);

                continue;
            }

            // Past this point a table will enter transferTable() — keep new pre-skip statuses above.
            if ($onTableStart !== null) {
                $onTableStart($tableName);
            }

            $tableStart = microtime(true);
            $sourceTable = $sourceSchema->getTable($tableName);
            $pkColumns = $sourceTable instanceof TableSchemaData
                ? array_values(array_map(
                    static fn (ColumnSchemaData $c): string => $c->name,
                    array_filter($sourceTable->columns, static fn (ColumnSchemaData $c): bool => $c->isPrimary)
                ))
                : [];

            [$rows, $skipped, $failed, $reason, $skippedRows, $timings] = $dumpSink instanceof SqlDumpService
                ? [...$this->dumpTable(
                    $config->options,
                    $tableConfig,
                    $source,
                    $engine,
                    $dumpSink,
                    $keyRemapping,
                    $config->keyRemapping,
                    $pkColumns,
                ), null]
                : $this->transferTable(
                    $config->options,
                    $tableConfig,
                    $source,
                    $target,
                    $pkColumns,
                    $engine,
                    $keyRemapping,
                    $config->keyRemapping,
                    $onProgress,
                    $trackRowTotals,
                );

            $tableDuration = microtime(true) - $tableStart;

            $status = $failed ? TableRunStatus::Failed : TableRunStatus::Transferred;

            if ($failed) {
                $success = false;
                Log::error('table_transfer_failed', ['table' => $tableName, 'reason' => $reason]);
            } else {
                Log::info('table_transferred', ['table' => $tableName, 'rows' => $rows, 'skipped' => $skipped]);
            }

            $tableResults[] = new TableRunResultData($tableName, $status, $rows, $skipped, $tableDuration, $reason);
            $totalRows += $rows;
            $totalSkipped += $skipped;
            ($onProgress)($tableName, $status, $rows, $skipped, $skippedRows, $timings);

            if ($failed && $breakOnFailure) {
                break;
            }

            if (! $failed && ! $dumpSink instanceof SqlDumpService && $sourceTable instanceof TableSchemaData) {
                $pkColumn = $this->findIntegerPkColumn($target, $sourceTable);
                if ($pkColumn !== null) {
                    try {
                        $this->replicator->correctAutoIncrement($target, $tableName, $pkColumn);
                    } catch (Throwable $e) {
                        Log::warning('auto_increment_correction_failed', ['table' => $tableName, 'error' => $e->getMessage()]);
                    }
                }
            }
        }

        $duration = microtime(true) - $start;

        return new RunResultData(
            success: $success,
            tables: $tableResults,
            totalRows: $totalRows,
            skippedRows: $totalSkipped,
            durationSeconds: $duration,
            failureReason: $success ? null : 'One or more tables failed to transfer',
        );
    }

    /**
     * Return the single integer PK column name, or null if AUTO_INCREMENT correction does not apply.
     * Only applies to MySQL/MariaDB targets with a single-column integer PK.
     */
    private function findIntegerPkColumn(ConnectionData $target, TableSchemaData $table): ?string
    {
        if (! in_array($target->type, [DatabaseConnectionType::Mysql, DatabaseConnectionType::MariaDB], true)) {
            return null;
        }

        $pkColumns = array_values(array_filter($table->columns, static fn (ColumnSchemaData $c): bool => $c->isPrimary));

        if (count($pkColumns) !== 1) {
            return null;
        }

        $intTypes = ['int', 'bigint', 'mediumint', 'smallint', 'tinyint', 'integer'];

        if (! in_array(strtolower($pkColumns[0]->type), $intTypes, true)) {
            return null;
        }

        return $pkColumns[0]->name;
    }

    /**
     * Transfer a single table. Returns [rowsTransferred, rowsSkipped, hasFailed, failureReason, skippedRows, timings].
     *
     * @param  list<string>  $pkColumns
     * @return array{int, int, bool, ?string, list<SkippedRow>, StatsTableTransferData}
     */
    private function transferTable(
        CloningOptionsData $options,
        TableCloningConfigData $tableConfig,
        ConnectionData $source,
        ConnectionData $target,
        array $pkColumns,
        AnonymizationEngine $engine,
        ?KeyRemappingService $keyRemapping,
        ?KeyRemappingConfigData $keyRemappingConfig,
        callable $onProgress,
        bool $trackRowTotals = false,
    ): array {

        $sourceConn = $this->connector->open($source);
        $targetConn = $this->connector->open($target);

        $rows = 0;
        $skipped = 0;
        $offset = 0;
        $chunkSize = $options->chunkSize;
        $firstInsertError = null;

        /** @var list<SkippedRow> $skippedRows */
        $skippedRows = [];

        /** @var list<array{key: string, lastValue: mixed}> $sortKeys */
        $sortKeys = [];

        $stats = new StatsTableTransferData;

        // Only pay for the row count when a consumer actually wants per-table
        // progress totals; otherwise skip it (totalRows stays 0 → indeterminate).
        if ($trackRowTotals) {
            $stats->setStatus(TableRunPhase::CountingRows);
            ($onProgress)($tableConfig->tableName, TableRunStatus::InProgress, $rows, $skipped, $skippedRows, $stats);

            $tCount = microtime(true);
            $stats->setTotalRows($this->countSourceRows($sourceConn, $tableConfig, $source));
            $stats->recordCountingRows(microtime(true) - $tCount);
        }

        $loopIndex = 0;

        try {
            if ($options->disableForeignKeyChecks) {
                $stats->setStatus(TableRunPhase::DisableFkChecks);
                ($onProgress)($tableConfig->tableName, TableRunStatus::InProgress, $rows, $skipped, $skippedRows, $stats);

                $t0 = microtime(true);
                $this->disableFkChecks($targetConn, $target);
                $stats->recordDisableFk(microtime(true) - $t0);
            }

            if ($tableConfig->rows->clear !== ClearMode::None) {
                $stats->setStatus(TableRunPhase::Clear);
                ($onProgress)($tableConfig->tableName, TableRunStatus::InProgress, $rows, $skipped, $skippedRows, $stats);

                $t0 = microtime(true);
                $this->clearTable($targetConn, $tableConfig->tableName, $tableConfig->rows->clear, $target);
                $stats->recordClearTable(microtime(true) - $t0);
            }

            do {
                $tOverall = microtime(true);
                $stats->setStatus(TableRunPhase::Select);
                $tSelect = microtime(true);
                $seeking = $sortKeys !== [];
                $sql = $this->buildChunkQuery($tableConfig, $source, $offset, $chunkSize, $pkColumns, $sortKeys);
                $bindings = $seeking ? array_map(static fn (array $k): mixed => $k['lastValue'], $sortKeys) : [];
                /** @var list<object> $chunk */
                $chunk = DB::connection($sourceConn)->select($sql, $bindings);
                $selectSeconds = microtime(true) - $tSelect;

                if ($chunk === []) {
                    break;
                }

                $stats->setStatus(TableRunPhase::Transform);
                $tTransform = microtime(true);
                $transformed = $this->transformChunk($chunk, $tableConfig, $engine, $keyRemapping, $keyRemappingConfig);
                $transformSeconds = microtime(true) - $tTransform;

                $chunkRowsAttempted = count($transformed);
                $loopRowsDone = 0;
                $loopRowsSkipped = 0;
                $stats->setStatus(TableRunPhase::Insert);
                $insertStart = microtime(true);
                // Bulk insert into target
                try {
                    DB::connection($targetConn)->table($tableConfig->tableName)->insert($transformed);
                    $loopRowsDone = $chunkRowsAttempted;
                } catch (Throwable $bulkError) {
                    if ($firstInsertError === null) {
                        $firstInsertError = $bulkError->getMessage();
                    }

                    // Fall back to row-by-row
                    foreach ($transformed as $rowIndexInChunk => $row) {
                        try {
                            DB::connection($targetConn)->table($tableConfig->tableName)->insert($row);
                            $loopRowsDone++;
                        } catch (Throwable $rowError) {
                            $loopRowsSkipped++;
                            /** @var array<string, mixed> $sourceRow */
                            $sourceRow = (array) $chunk[$rowIndexInChunk];
                            $pkSnapshot = $this->extractPkSnapshot($sourceRow, $pkColumns);
                            $skippedRow = new SkippedRow(
                                tableName: $tableConfig->tableName,
                                chunkOffset: $offset,
                                rowIndex: $rowIndexInChunk,
                                pkSnapshot: $pkSnapshot,
                                sqlError: $rowError->getMessage(),
                            );
                            $skippedRows[] = $skippedRow;
                            Log::warning('row_skipped', [
                                'table' => $skippedRow->tableName,
                                'chunk_offset' => $skippedRow->chunkOffset,
                                'row_index' => $skippedRow->rowIndex,
                                'pk' => $skippedRow->pkSnapshot,
                                'error' => $skippedRow->sqlError,
                            ]);
                        }
                    }
                }

                $insertSeconds = microtime(true) - $insertStart;

                $rows += $loopRowsDone;
                $skipped += $loopRowsSkipped;

                $overallSeconds = microtime(true) - $tOverall;

                $stats->recordLoop(new StatsLoopData(
                    loopIndex: $loopIndex,
                    chunkRows: $chunkRowsAttempted,
                    selectSeconds: $selectSeconds,
                    transformSeconds: $transformSeconds,
                    insertSeconds: $insertSeconds,
                    overallSeconds: $overallSeconds,
                    rowsDone: $loopRowsDone,
                    rowsSkipped: $loopRowsSkipped,
                    totalRows: $stats->totalRows,
                ));

                ($onProgress)($tableConfig->tableName, TableRunStatus::InProgress, $rows, $skipped, [], $stats);

                // Advance the keyset cursor to the last row of this chunk (if seeking).
                if ($sortKeys !== []) {
                    $lastRow = (array) array_last($chunk);
                    foreach ($sortKeys as $i => $sortKey) {
                        $sortKeys[$i]['lastValue'] = $lastRow[$sortKey['key']] ?? null;
                    }
                }

                $offset += count($chunk);
                $loopIndex++;
            } while (count($chunk) === $chunkSize);

            if ($rows === 0 && $skipped > 0) {
                $reason = sprintf('All %d rows failed to insert', $skipped);
                if ($firstInsertError !== null) {
                    $reason .= sprintf(': %s', $firstInsertError);
                }

                return [0, $skipped, true, $reason, $skippedRows, $stats];
            }

            return [$rows, $skipped, false, null, $skippedRows, $stats];
        } catch (Throwable $throwable) {
            return [$rows, $skipped, true, $throwable->getMessage(), $skippedRows, $stats];
        } finally {
            if ($options->disableForeignKeyChecks) {
                $this->enableFkChecks($targetConn, $target);
            }

            DB::purge($sourceConn);
            DB::purge($targetConn);
        }
    }

    /**
     * Read source chunks, transform + key-remap each row, and stream the result as
     * INSERT batches into the dump file. Mirrors transferTable() minus the live
     * target connection. Returns [rowsWritten, 0, hasFailed, failureReason, []].
     *
     * @param  list<string>  $pkColumns
     * @return array{int, int, bool, ?string, list<SkippedRow>}
     */
    private function dumpTable(
        CloningOptionsData $options,
        TableCloningConfigData $tableConfig,
        ConnectionData $source,
        AnonymizationEngine $engine,
        SqlDumpService $dumpSink,
        ?KeyRemappingService $keyRemapping = null,
        ?KeyRemappingConfigData $keyRemappingConfig = null,
        array $pkColumns = [],
    ): array {
        $sourceConn = $this->connector->open($source);

        $rows = 0;
        $offset = 0;
        $chunkSize = $options->chunkSize;

        /** @var list<array{key: string, lastValue: mixed}> $sortKeys */
        $sortKeys = [];

        try {
            do {
                $seeking = $sortKeys !== [];
                $sql = $this->buildChunkQuery($tableConfig, $source, $offset, $chunkSize, $pkColumns, $sortKeys);
                $bindings = $seeking ? array_map(static fn (array $k): mixed => $k['lastValue'], $sortKeys) : [];
                /** @var list<object> $chunk */
                $chunk = DB::connection($sourceConn)->select($sql, $bindings);

                if ($chunk === []) {
                    break;
                }

                $transformed = $this->transformChunk($chunk, $tableConfig, $engine, $keyRemapping, $keyRemappingConfig);
                $dumpSink->writeRows($tableConfig->tableName, $transformed);
                $rows += count($transformed);

                if ($sortKeys !== []) {
                    $lastRow = (array) array_last($chunk);
                    foreach ($sortKeys as $i => $sortKey) {
                        $sortKeys[$i]['lastValue'] = $lastRow[$sortKey['key']] ?? null;
                    }
                }

                $offset += count($chunk);
            } while (count($chunk) === $chunkSize);

            return [$rows, 0, false, null, []];
        } catch (Throwable $throwable) {
            return [$rows, 0, true, $throwable->getMessage(), []];
        } finally {
            DB::purge($sourceConn);
        }
    }

    /**
     * Apply per-column anonymization and key remapping to a chunk of source rows.
     *
     * @param  list<object>  $chunk
     * @return list<array<string, mixed>>
     */
    private function transformChunk(
        array $chunk,
        TableCloningConfigData $tableConfig,
        AnonymizationEngine $engine,
        ?KeyRemappingService $keyRemapping,
        ?KeyRemappingConfigData $keyRemappingConfig,
    ): array {
        /** @var list<array<string, mixed>> $transformed */
        $transformed = [];

        foreach ($chunk as $row) {
            $rowArray = (array) $row;
            $transformedRow = [];

            foreach ($rowArray as $col => $val) {
                if (! is_string($col)) {
                    continue;
                }

                $colConfig = $tableConfig->getColumn($col);
                $transformedRow[$col] = $colConfig instanceof ColumnCloningConfigData ? $engine->transform($val, $colConfig) : $val;
            }

            if ($keyRemapping instanceof KeyRemappingService && $keyRemappingConfig instanceof KeyRemappingConfigData) {
                $transformedRow = $keyRemapping->applyToRow($transformedRow, $tableConfig->tableName, $keyRemappingConfig);
            }

            $transformed[] = $transformedRow;
        }

        return $transformed;
    }

    /**
     * @param  array<string, mixed>  $sourceRow
     * @param  list<string>  $pkColumns
     * @return array<string, mixed>|null
     */
    private function extractPkSnapshot(array $sourceRow, array $pkColumns): ?array
    {
        if ($pkColumns === []) {
            return null;
        }

        $snapshot = [];
        foreach ($pkColumns as $col) {
            if (array_key_exists($col, $sourceRow)) {
                $snapshot[$col] = $sourceRow[$col];
            }
        }

        return $snapshot === [] ? null : $snapshot;
    }

    /**
     * Best-effort SELECT COUNT(*) on the source table respecting the row
     * strategy limit. Returns 0 on failure so progress stays optional.
     */
    private function countSourceRows(string $sourceConn, TableCloningConfigData $config, ConnectionData $source): int
    {
        try {
            $quoted = $this->quoteTable($config->tableName, $source->type);
            $result = DB::connection($sourceConn)->selectOne(sprintf('SELECT COUNT(*) AS c FROM %s', $quoted));
            $count = 0;
            if (is_object($result) && property_exists($result, 'c') && is_numeric($result->c)) {
                $count = (int) $result->c;
            } elseif (is_array($result) && array_key_exists('c', $result) && is_numeric($result['c'])) {
                $count = (int) $result['c'];
            }

            $limit = $config->rows->limit;
            if ($limit !== null && $limit >= 0 && $limit < $count) {
                return $limit;
            }

            return $count;
        } catch (Throwable $throwable) {
            Log::warning('source_row_count_failed', ['table' => $config->tableName, 'error' => $throwable->getMessage()]);

            return 0;
        }
    }

    /**
     * Build the SELECT for the next chunk.
     *
     * When the driver and row strategy allow keyset (seek) pagination and the source
     * has a usable ordering, `$sortKeys` is populated with the ordering columns on the
     * first call (each `lastValue` null) and the caller fills every `lastValue` from the
     * last fetched row before the next call; the query then seeks past that row with a
     * bound row-value comparison instead of a growing OFFSET. When keyset does not apply
     * (no PK, SQL Server) `$sortKeys` is cleared and the legacy LIMIT/OFFSET SQL is used.
     *
     * @param  list<string>  $pkColumns
     * @param  list<array{key: string, lastValue: mixed}>  $sortKeys  by-reference seek cursor
     */
    private function buildChunkQuery(TableCloningConfigData $config, ConnectionData $source, int $offset, int $limit, array $pkColumns, array &$sortKeys): string
    {
        $keyColumns = $this->keysetColumns($config, $source->type, $pkColumns);

        // Keyset not applicable → legacy OFFSET pagination.
        if ($keyColumns === []) {
            $sortKeys = [];

            return $this->offsetChunkQuery($config, $source, $offset, $limit);
        }

        $rows = $config->rows;
        $quotedTable = $this->quoteTable($config->tableName, $source->type);
        $direction = $rows->strategy === 'last' ? 'DESC' : 'ASC';
        $orderBy = implode(', ', array_map(
            fn (string $c): string => $this->quoteIdentifier($c, $source->type).' '.$direction,
            $keyColumns,
        ));

        // Respect the row-strategy limit for the ordered (non-full) strategies.
        $chunkLimit = $limit;
        if ($rows->strategy !== 'full') {
            $totalLimit = $rows->limit ?? PHP_INT_MAX;
            $chunkLimit = min($limit, max(0, $totalLimit - $offset));

            if ($chunkLimit <= 0) {
                $sortKeys = [];

                return sprintf('SELECT * FROM %s WHERE 1=0', $quotedTable);
            }
        }

        // First call: establish the cursor columns and page from the start (no WHERE).
        if ($sortKeys === []) {
            $sortKeys = array_map(static fn (string $c): array => ['key' => $c, 'lastValue' => null], $keyColumns);

            return sprintf('SELECT * FROM %s ORDER BY %s LIMIT %d', $quotedTable, $orderBy, $chunkLimit);
        }

        // Subsequent calls: seek past the last fetched row via a bound row-value comparison.
        $columns = implode(', ', array_map(fn (array $k): string => $this->quoteIdentifier($k['key'], $source->type), $sortKeys));
        $placeholders = implode(', ', array_fill(0, count($sortKeys), '?'));
        $operator = $direction === 'DESC' ? '<' : '>';

        return sprintf(
            'SELECT * FROM %s WHERE (%s) %s (%s) ORDER BY %s LIMIT %d',
            $quotedTable,
            $columns,
            $operator,
            $placeholders,
            $orderBy,
            $chunkLimit,
        );
    }

    /**
     * The ordering columns to seek by, or [] when keyset pagination does not apply
     * (SQL Server, or no primary key to guarantee a total order).
     *
     * @param  list<string>  $pkColumns
     * @return list<string>
     */
    private function keysetColumns(TableCloningConfigData $config, DatabaseConnectionType $type, array $pkColumns): array
    {
        if ($pkColumns === [] || ! $this->supportsKeysetPagination($type)) {
            return [];
        }

        if ($config->rows->strategy === 'full') {
            return $pkColumns;
        }

        // Ordered strategies: sort column first, PK columns appended as a tiebreaker so
        // the ordering is total even when the sort column has duplicate values.
        $keys = [$config->rows->sortBy ?? 'id'];
        foreach ($pkColumns as $pk) {
            if (! in_array($pk, $keys, true)) {
                $keys[] = $pk;
            }
        }

        return $keys;
    }

    private function supportsKeysetPagination(DatabaseConnectionType $type): bool
    {
        return in_array($type, [
            DatabaseConnectionType::Mysql,
            DatabaseConnectionType::MariaDB,
            DatabaseConnectionType::PostgreSQL,
            DatabaseConnectionType::Sqlite,
        ], true);
    }

    /**
     * Legacy LIMIT/OFFSET (or OFFSET/FETCH) chunk query — used when keyset does not apply.
     */
    private function offsetChunkQuery(TableCloningConfigData $config, ConnectionData $source, int $offset, int $limit): string
    {
        $table = $config->tableName;
        $rows = $config->rows;
        $driver = $source->type->value;

        $quotedTable = $this->quoteTable($table, $source->type);

        if ($rows->strategy === 'full') {
            return match ($driver) {
                'sqlsrv' => sprintf('SELECT * FROM [%s] ORDER BY (SELECT NULL) OFFSET %d ROWS FETCH NEXT %d ROWS ONLY', $table, $offset, $limit),
                'pgsql', 'sqlite' => sprintf('SELECT * FROM "%s" LIMIT %d OFFSET %d', $table, $limit, $offset),
                default => sprintf('SELECT * FROM `%s` LIMIT %d OFFSET %d', $table, $limit, $offset),
            };
        }

        $totalLimit = $rows->limit ?? PHP_INT_MAX;
        $actualLimit = min($limit, max(0, $totalLimit - $offset));

        if ($actualLimit <= 0) {
            return sprintf('SELECT * FROM %s WHERE 1=0', $quotedTable);
        }

        $sortCol = $rows->sortBy ?? 'id';
        $direction = $rows->strategy === 'last' ? 'DESC' : 'ASC';

        return match ($driver) {
            'pgsql', 'sqlite' => sprintf('SELECT * FROM "%s" ORDER BY "%s" %s LIMIT %d OFFSET %d', $table, $sortCol, $direction, $actualLimit, $offset),
            'sqlsrv' => sprintf('SELECT * FROM [%s] ORDER BY [%s] %s OFFSET %d ROWS FETCH NEXT %d ROWS ONLY', $table, $sortCol, $direction, $offset, $actualLimit),
            default => sprintf('SELECT * FROM `%s` ORDER BY `%s` %s LIMIT %d OFFSET %d', $table, $sortCol, $direction, $actualLimit, $offset),
        };
    }

    private function quoteTable(string $name, DatabaseConnectionType $driver): string
    {
        return $this->quoteIdentifier($name, $driver);
    }

    private function quoteIdentifier(string $name, DatabaseConnectionType $driver): string
    {
        return match ($driver) {
            DatabaseConnectionType::Mysql, DatabaseConnectionType::MariaDB => '`'.$name.'`',
            DatabaseConnectionType::SqlServer => '['.$name.']',
            default => '"'.$name.'"',
        };
    }

    private function clearTable(string $connName, string $tableName, ClearMode $method, ConnectionData $connection): void
    {
        if ($method === ClearMode::Truncate) {
            // SQLite does not support TRUNCATE; fall back to DELETE
            if ($connection->type->value === 'sqlite') {
                DB::connection($connName)->table($tableName)->delete();
            } else {
                $quotedTable = $this->quoteTable($tableName, $connection->type);
                DB::connection($connName)->statement(sprintf('TRUNCATE TABLE %s', $quotedTable));
            }
        } elseif ($method === ClearMode::Delete) {
            DB::connection($connName)->table($tableName)->delete();
        }
    }

    private function disableFkChecks(string $connName, ConnectionData $connection): void
    {
        try {
            match ($connection->type->value) {
                'mysql', 'mariadb' => DB::connection($connName)->statement('SET FOREIGN_KEY_CHECKS=0'),
                'pgsql' => DB::connection($connName)->statement('SET session_replication_role = replica'),
                default => null,
            };
        } catch (Throwable) {
        }
    }

    private function enableFkChecks(string $connName, ConnectionData $connection): void
    {
        try {
            match ($connection->type->value) {
                'mysql', 'mariadb' => DB::connection($connName)->statement('SET FOREIGN_KEY_CHECKS=1'),
                'pgsql' => DB::connection($connName)->statement('SET session_replication_role = DEFAULT'),
                default => null,
            };
        } catch (Throwable) {
        }
    }
}
