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
use App\Data\Schema\ForeignKeyData;
use App\Data\Schema\TableSchemaData;
use App\Enums\ClearMode;
use App\Enums\DatabaseConnectionType;
use App\Services\Database\DatabaseConnectionService;
use App\Services\Schema\SchemaInspector;
use App\Services\SqlDump\SqlDumpService;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class CloningRunOrchestrator
{
    public function __construct(
        private readonly DatabaseConnectionService $connector,
        private readonly SchemaReplicator $replicator,
        private readonly DependencyResolver $resolver,
        private readonly SchemaInspector $inspector,
    ) {}

    /**
     * @param  list<string>  $skipTables  Tables to exclude, from the YAML and --skip-tables; they stay excluded with onlyTables
     * @param  list<string>  $onlyTables  If non-empty, only these tables (minus $skipTables) are transferred
     * @param  callable(string, TableRunStatus, int, int, list<SkippedRow>): void  $onProgress
     * @param  (callable(string): void)|null  $onTableStart  Optional. Fires once per table that enters `transferTable()`, regardless of whether the transfer ultimately succeeds (`Transferred`) or fails (`Failed`). Does NOT fire for tables resolved as `SkippedByFlag`, `SkippedByCascade`, `NotFound`, or `SkippedBySchemaFailure`.
     * @param  (callable(int): void)|null  $onStart  Optional. Fires exactly once, before the transfer loop, with the number of tables the loop will iterate. Note: when `$breakOnFailure` aborts early, fewer terminal `onProgress` events fire than this count. Useful for sizing an overall progress bar.
     * @param  bool  $trackRowTotals  When true, look up the expected row total per table (respecting the row limit) to size per-table progress: a catalog-statistics estimate on server databases, so the production source is never scanned, and `COUNT(*)` on SQLite. Off by default so non-interactive runs don't pay for a lookup nobody consumes.
     * @param  (callable(string, StatsTableTransferData): void)|null  $onTableProgress  Optional. Fires while a table transfers: when each one-shot phase starts (row total, FK checks, clear) and after every chunk. The same live object is passed for the whole table and holds its final figures once the table's terminal `onProgress` event fires.
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
        ?callable $onTableProgress = null,
    ): RunResultData {
        $start = microtime(true);
        $tableNames = array_map(static fn (TableCloningConfigData $t): string => $t->tableName, $config->tables);

        // Compute excluded tables, by flag and by cascade
        [$explicitlyExcluded, $cascadeExclusions] = $this->resolver->exclusions($sourceSchema, $tableNames, $skipTables, $onlyTables);

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
                $config->options->recreateTables,
            );

            if ($schemaFailures === []) {
                Log::info('schema_replicated', ['tables' => $sortedTables]);
            } else {
                foreach ($schemaFailures as $failedTable => $errorMsg) {
                    Log::error('schema_table_failed', ['table' => $failedTable, 'error' => $errorMsg]);
                }
            }
        }

        // Inspect the target after replication so generated columns that exist
        // only there (e.g. created by the app's own migrations) are known too.
        $targetSchema = $dumpSink instanceof SqlDumpService ? null : $this->inspectTarget($target);

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
            $reason = in_array($tableName, $skipTables, true) ? 'explicitly excluded via --skip' : 'excluded by --only filter';
            $tableResults[] = new TableRunResultData($tableName, TableRunStatus::SkippedByFlag, 0, 0, 0.0, $reason);
            Log::info('table_skipped_by_flag', ['table' => $tableName, 'reason' => $reason]);
        }

        foreach ($cascadeExclusions as $tableName) {
            $reason = 'excluded due to foreign key dependency';
            $tableResults[] = new TableRunResultData($tableName, TableRunStatus::SkippedByCascade, 0, 0, 0.0, $reason);
            Log::info('table_skipped_by_cascade', ['table' => $tableName, 'reason' => $reason]);
        }

        // Tables with rows.strategy 'schema' are created on the target but get no rows.
        $schemaOnlyTables = array_values(array_filter( // @pest-mutate-ignore: UnwrapArrayValues
            $sortedTables,
            static fn (string $t): bool => $config->getTable($t)?->rows->strategy === 'schema',
        ));

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
            $targetTable = $targetSchema?->getTable($tableName);

            $schemaOnly = $tableConfig->rows->strategy === 'schema';

            [$omittedColumns, $notes] = $schemaOnly
                ? [[], []]
                : $this->resolveOmittedColumns(
                    $tableConfig,
                    $sourceTable,
                    $targetTable,
                    $dumpSink instanceof SqlDumpService,
                );

            if (! $schemaOnly && $sourceTable instanceof TableSchemaData) { // @pest-mutate-ignore: InstanceOfToTrue
                $emptyParents = array_values(array_unique(array_filter( // @pest-mutate-ignore: UnwrapArrayValues
                    array_map(static fn (ForeignKeyData $fk): string => $fk->referencedTable, $sourceTable->foreignKeys),
                    static fn (string $parent): bool => $parent !== $tableName && in_array($parent, $schemaOnlyTables, true),
                )));

                if ($emptyParents !== []) {
                    $notes[] = sprintf(
                        "References tables copied without data ('schema' strategy): %s. Foreign key values may point to missing rows, or inserts fail when foreign key checks are enabled.",
                        implode(', ', $emptyParents),
                    );
                }
            }

            foreach ($notes as $note) {
                Log::warning('table_column_note', ['table' => $tableName, 'note' => $note]);
            }

            $selectColumns = $omittedColumns !== [] && $sourceTable instanceof TableSchemaData // @pest-mutate-ignore: InstanceOfToTrue
                ? array_values(array_filter( // @pest-mutate-ignore: UnwrapArrayValues
                    array_map(static fn (ColumnSchemaData $c): string => $c->name, $sourceTable->columns),
                    static fn (string $c): bool => ! in_array($c, $omittedColumns, true),
                ))
                : null;
            $pkColumns = $sourceTable instanceof TableSchemaData // @pest-mutate-ignore: InstanceOfToTrue
                ? $this->primaryKeyColumns($sourceTable)
                : [];

            $keyColumns = $this->keysetColumns($tableConfig, $source->type, $sourceTable, $pkColumns, $selectColumns);

            [$rows, $skipped, $failed, $reason, $skippedRows] = $dumpSink instanceof SqlDumpService
                ? $this->dumpTable(
                    $config->options,
                    $tableConfig,
                    $source,
                    $engine,
                    $dumpSink,
                    $keyRemapping,
                    $config->keyRemapping,
                    $selectColumns,
                    $keyColumns,
                )
                : $this->transferTable(
                    $config->options,
                    $tableConfig,
                    $source,
                    $target,
                    $pkColumns,
                    $engine,
                    $keyRemapping,
                    $config->keyRemapping,
                    $selectColumns,
                    $keyColumns,
                    $trackRowTotals,
                    $onTableProgress,
                );

            $tableDuration = microtime(true) - $tableStart;

            $status = $failed ? TableRunStatus::Failed : TableRunStatus::Transferred;

            if ($failed) {
                $success = false;
                Log::error('table_transfer_failed', ['table' => $tableName, 'reason' => $reason]);
            } else {
                Log::info('table_transferred', ['table' => $tableName, 'rows' => $rows, 'skipped' => $skipped]);
            }

            $tableResults[] = new TableRunResultData($tableName, $status, $rows, $skipped, $tableDuration, $reason, $notes);
            $totalRows += $rows;
            $totalSkipped += $skipped;
            ($onProgress)($tableName, $status, $rows, $skipped, $skippedRows);

            if ($failed && $breakOnFailure) {
                break;
            }

            if (! $failed && ! $dumpSink instanceof SqlDumpService && $sourceTable instanceof TableSchemaData) { // @pest-mutate-ignore: InstanceOfToTrue
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
     * Transfer a single table. Returns [rowsTransferred, rowsSkipped, hasFailed, failureReason, skippedRows].
     *
     * @param  list<string>  $pkColumns
     * @param  list<string>|null  $selectColumns  Explicit source columns to copy; null copies all
     * @param  list<string>  $keyColumns  Columns to seek by (keysetColumns()); empty reads with LIMIT/OFFSET
     * @param  (callable(string, StatsTableTransferData): void)|null  $onTableProgress
     * @return array{int, int, bool, ?string, list<SkippedRow>}
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
        ?array $selectColumns,
        array $keyColumns,
        bool $trackRowTotals,
        ?callable $onTableProgress,
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

        /** @var list<mixed>|null $cursor Key values of the last row read; null before the first chunk. */
        $cursor = null;

        $stats = new StatsTableTransferData;
        $reportProgress = static function () use ($onTableProgress, $tableConfig, $stats): void {
            if ($onTableProgress !== null) {
                $onTableProgress($tableConfig->tableName, $stats);
            }
        };

        try {
            // Only pay for the row estimate when a consumer actually wants per-table
            // progress totals and the table copies rows; otherwise totalRows stays 0
            // (unknown).
            if ($trackRowTotals && $tableConfig->rows->strategy !== 'schema') {
                $stats->setStatus(TableRunPhase::EstimatingRows);
                $reportProgress();

                $t0 = microtime(true);
                [$expectedRows, $isEstimate] = $this->estimateSourceRows($sourceConn, $tableConfig, $source);
                $stats->setTotalRows($expectedRows, $isEstimate);
                $stats->recordEstimatingRows(microtime(true) - $t0);
            }

            if ($options->disableForeignKeyChecks) {
                $stats->setStatus(TableRunPhase::DisableFkChecks);
                $reportProgress();

                $t0 = microtime(true);
                $this->disableFkChecks($targetConn, $target);
                $stats->recordDisableFk(microtime(true) - $t0);
            }

            if ($tableConfig->rows->clear !== ClearMode::None) {
                $stats->setStatus(TableRunPhase::Clear);
                $reportProgress();

                $t0 = microtime(true);
                $this->clearTable($targetConn, $tableConfig->tableName, $tableConfig->rows->clear, $target);
                $stats->recordClearTable(microtime(true) - $t0);
            }

            if ($tableConfig->rows->strategy === 'schema') {
                return [0, 0, false, null, []];
            }

            $stats->setStatus(TableRunPhase::Loop);
            $reportProgress();

            do {
                $tOverall = microtime(true);
                [$sql, $bindings] = $this->buildChunkQuery($tableConfig, $source, $offset, $chunkSize, $keyColumns, $cursor, $selectColumns);
                /** @var list<object> $chunk */
                $chunk = DB::connection($sourceConn)->select($sql, $bindings);
                $selectSeconds = microtime(true) - $tOverall;

                if ($chunk === []) {
                    break; // @pest-mutate-ignore: BreakToContinue
                }

                $tTransform = microtime(true);
                $transformed = $this->transformChunk($chunk, $tableConfig, $engine, $keyRemapping, $keyRemappingConfig);
                $transformSeconds = microtime(true) - $tTransform;

                // Count rows as they land, so a throw mid-chunk still reports them.
                $rowsBefore = $rows;
                $skippedBefore = $skipped;
                $insertStart = microtime(true);
                // Bulk insert into target
                try {
                    DB::connection($targetConn)->table($tableConfig->tableName)->insert($transformed);
                    $rows += count($transformed);
                } catch (Throwable $bulkError) {
                    $firstInsertError ??= $bulkError->getMessage();

                    // Fall back to row-by-row
                    foreach ($transformed as $rowIndexInChunk => $row) {
                        try {
                            DB::connection($targetConn)->table($tableConfig->tableName)->insert($row);
                            $rows++;
                        } catch (Throwable $rowError) {
                            $skipped++;
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
                $overallSeconds = microtime(true) - $tOverall;

                $stats->recordLoop(new StatsLoopData(
                    chunkRows: count($transformed),
                    selectSeconds: $selectSeconds,
                    transformSeconds: $transformSeconds,
                    insertSeconds: $insertSeconds,
                    overallSeconds: $overallSeconds,
                    rowsDone: $rows - $rowsBefore,
                    rowsSkipped: $skipped - $skippedBefore,
                ));

                $reportProgress();

                $cursor = $this->keysetCursor($chunk, $keyColumns, $tableConfig->tableName, $cursor);
                $offset += count($chunk);
            } while (count($chunk) === $chunkSize);

            if ($rows === 0 && $skipped > 0) {
                $reason = sprintf('All %d rows failed to insert', $skipped);
                if ($firstInsertError !== null) {
                    $reason .= sprintf(': %s', $firstInsertError);
                }

                return [0, $skipped, true, $reason, $skippedRows];
            }

            return [$rows, $skipped, false, null, $skippedRows];
        } catch (Throwable $throwable) {
            return [$rows, $skipped, true, $throwable->getMessage(), $skippedRows];
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
     * @param  list<string>|null  $selectColumns  Explicit source columns to copy; null copies all
     * @param  list<string>  $keyColumns  Columns to seek by (keysetColumns()); empty reads with LIMIT/OFFSET
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
        ?array $selectColumns = null,
        array $keyColumns = [],
    ): array {
        if ($tableConfig->rows->strategy === 'schema') {
            return [0, 0, false, null, []];
        }

        $sourceConn = $this->connector->open($source);

        $rows = 0;
        $offset = 0;
        $chunkSize = $options->chunkSize;

        /** @var list<mixed>|null $cursor Key values of the last row read; null before the first chunk. */
        $cursor = null;

        try {
            do {
                [$sql, $bindings] = $this->buildChunkQuery($tableConfig, $source, $offset, $chunkSize, $keyColumns, $cursor, $selectColumns);
                /** @var list<object> $chunk */
                $chunk = DB::connection($sourceConn)->select($sql, $bindings);

                if ($chunk === []) {
                    break; // @pest-mutate-ignore: BreakToContinue
                }

                $transformed = $this->transformChunk($chunk, $tableConfig, $engine, $keyRemapping, $keyRemappingConfig);
                $dumpSink->writeRows($tableConfig->tableName, $transformed);
                $rows += count($transformed);
                $cursor = $this->keysetCursor($chunk, $keyColumns, $tableConfig->tableName, $cursor);
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
            $rowArray = (array) $row; // @pest-mutate-ignore: RemoveArrayCast
            $transformedRow = [];

            foreach ($rowArray as $col => $val) {
                if (! is_string($col)) {
                    continue;
                }

                $colConfig = $tableConfig->getColumn($col);

                if ($colConfig instanceof ColumnCloningConfigData && $colConfig->strategy === 'exclude') {
                    continue;
                }

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
            return null; // @pest-mutate-ignore: RemoveEarlyReturn
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
     * Best-effort expected row total of the source table, respecting the row
     * strategy limit. Server databases answer from catalog statistics, so a
     * large production table is never scanned; the figure is then an estimate
     * that can be off in either direction. SQLite keeps no such statistics and
     * counts. Returns 0 (unknown) on failure so progress stays optional.
     *
     * @return array{int, bool} [rows, isEstimate]
     */
    private function estimateSourceRows(string $sourceConn, TableCloningConfigData $config, ConnectionData $source): array
    {
        [$sql, $bindings, $isEstimate] = match ($source->type) {
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
                $config->rows->strategy !== 'full' && $config->rows->limit !== null
                    ? sprintf('SELECT COUNT(*) AS c FROM (SELECT 1 FROM %s LIMIT %d)', $this->quoteTable($config->tableName, $source->type), $config->rows->limit)
                    : sprintf('SELECT COUNT(*) AS c FROM %s', $this->quoteTable($config->tableName, $source->type)),
                [],
                false,
            ],
        };

        try {
            $result = DB::connection($sourceConn)->selectOne($sql, $bindings);
        } catch (Throwable $throwable) {
            Log::warning('source_row_estimate_failed', ['table' => $config->tableName, 'error' => $throwable->getMessage()]);
            $result = null;
            $isEstimate = false;
        }

        $row = is_object($result) ? get_object_vars($result) : $result;
        $value = is_array($row) ? ($row['c'] ?? null) : null;
        // Unreadable means unknown (0). PostgreSQL reports -1 for a table that was
        // never analyzed; setTotalRows() clamps that to 0 as well.
        $count = is_numeric($value) ? (int) $value : 0; // @pest-mutate-ignore: DecrementInteger

        // Mirrors buildChunkQuery(): only 'first' / 'last' honour the row limit.
        if ($config->rows->strategy !== 'full' && $config->rows->limit !== null) {
            $count = min($config->rows->limit, $count);
        }

        return [$count, $isEstimate];
    }

    /**
     * Primary key column names in key order, which can differ from table order.
     * Columns whose driver reports no key position keep their table order.
     *
     * @return list<string>
     */
    private function primaryKeyColumns(TableSchemaData $table): array
    {
        $columns = array_values(array_filter($table->columns, static fn (ColumnSchemaData $c): bool => $c->isPrimary)); // @pest-mutate-ignore: UnwrapArrayValues
        usort($columns, static fn (ColumnSchemaData $a, ColumnSchemaData $b): int => ($a->primaryKeyPosition ?? PHP_INT_MAX) <=> ($b->primaryKeyPosition ?? PHP_INT_MAX));

        return array_map(static fn (ColumnSchemaData $c): string => $c->name, $columns);
    }

    /**
     * The columns to seek by when reading this table in chunks, or [] to read it
     * with LIMIT/OFFSET instead. Keyset (seek) pagination needs a total order on
     * columns whose last value can be bound back unchanged: the strategy's sort
     * column (for 'first' / 'last'), then the primary key in key order as a
     * tiebreaker. It is not used
     *  - on SQL Server,
     *  - without a primary key,
     *  - for a composite primary key whose key order the driver does not report,
     *  - when a key column is nullable, unknown to the source schema (e.g. a casing
     *    mismatch in `sort_by`) or not selected: a NULL cursor value would make the
     *    next seek match no row and silently end the table early,
     *  - when a key column's type does not round-trip (isSeekableType()).
     *
     * @param  list<string>  $primaryKey  In key order (primaryKeyColumns())
     * @param  list<string>|null  $selectColumns
     * @return list<string>
     */
    private function keysetColumns(TableCloningConfigData $config, DatabaseConnectionType $type, ?TableSchemaData $table, array $primaryKey, ?array $selectColumns): array
    {
        if (! $table instanceof TableSchemaData || $type === DatabaseConnectionType::SqlServer || $primaryKey === []) { // @pest-mutate-ignore: InstanceOfToTrue
            return [];
        }

        $keys = $config->rows->strategy === 'full' ? [] : [$config->rows->sortBy ?? 'id'];
        foreach ($primaryKey as $pk) {
            if (! in_array($pk, $keys, true)) {
                $keys[] = $pk;
            }
        }

        foreach ($keys as $key) {
            $column = array_find($table->columns, static fn (ColumnSchemaData $c): bool => $c->name === $key);

            if (! $column instanceof ColumnSchemaData
                || $column->nullable
                || ($selectColumns !== null && ! in_array($key, $selectColumns, true))
                || ! $this->isSeekableType($column->type, $type)
                || (count($primaryKey) > 1 && $column->isPrimary && $column->primaryKeyPosition === null)
            ) {
                return [];
            }
        }

        return $keys;
    }

    /**
     * Whether a column's last value survives the round trip through PHP unchanged
     * and compares the way it sorts, so it can be bound as the seek cursor. Not so
     * for floats (bound with 14 significant digits), binary values (bound as text;
     * a PostgreSQL bytea even arrives as an already consumed stream), MySQL ENUM /
     * SET (sorted by position, compared as text) and MySQL TIMESTAMP (ambiguous in
     * the hour repeated when daylight saving time ends).
     */
    private function isSeekableType(string $columnType, DatabaseConnectionType $driver): bool
    {
        $type = strtolower($columnType);

        return match ($driver) {
            // SQLite affinity rules: a declared type containing INT is INTEGER, one
            // containing CHAR, CLOB or TEXT is TEXT.
            DatabaseConnectionType::Sqlite => str_contains($type, 'int') || str_contains($type, 'char') || str_contains($type, 'clob') || str_contains($type, 'text'),
            DatabaseConnectionType::PostgreSQL => in_array($type, [
                'smallint', 'integer', 'bigint', 'numeric', 'character varying', 'character', 'text', 'uuid', 'date',
                'timestamp without time zone', 'timestamp with time zone',
            ], true),
            default => in_array($type, [
                'tinyint', 'smallint', 'mediumint', 'int', 'integer', 'bigint', 'decimal', 'numeric',
                'char', 'varchar', 'tinytext', 'text', 'mediumtext', 'longtext', 'date', 'datetime',
            ], true),
        };
    }

    /**
     * Build the SELECT for the next chunk and its bindings. With key columns it
     * seeks past `$cursor` (the key values of the last row read) instead of
     * skipping a growing OFFSET; without, it falls back to offsetChunkQuery().
     *
     * @param  list<string>  $keyColumns
     * @param  list<mixed>|null  $cursor
     * @param  list<string>|null  $columns  Explicit column list; null selects all columns
     * @return array{string, list<mixed>}
     */
    private function buildChunkQuery(TableCloningConfigData $config, ConnectionData $source, int $offset, int $limit, array $keyColumns, ?array $cursor, ?array $columns = null): array
    {
        $rows = $config->rows;
        $select = $columns === null
            ? '*'
            : implode(', ', array_map(fn (string $c): string => $this->quoteTable($c, $source->type), $columns));
        $direction = $rows->strategy === 'last' ? 'DESC' : 'ASC';

        // 'first' / 'last' copy at most rows.limit rows across all chunks.
        if ($rows->strategy !== 'full') {
            $limit = min($limit, ($rows->limit ?? PHP_INT_MAX) - $offset);

            if ($limit <= 0) {
                return [sprintf('SELECT %s FROM %s WHERE 1=0', $select, $this->quoteTable($config->tableName, $source->type)), []];
            }
        }

        if ($keyColumns === []) {
            return [$this->offsetChunkQuery($config, $source, $offset, $limit, $select, $direction), []];
        }

        $quotedTable = $this->quoteTable($config->tableName, $source->type);
        $orderBy = implode(', ', array_map(
            fn (string $c): string => $this->quoteTable($c, $source->type).' '.$direction,
            $keyColumns,
        ));

        if ($cursor === null) {
            return [sprintf('SELECT %s FROM %s ORDER BY %s LIMIT %d', $select, $quotedTable, $orderBy, $limit), []];
        }

        [$seek, $bindings] = $this->seekCondition($keyColumns, $cursor, $direction === 'DESC' ? '<' : '>', $source->type);

        return [sprintf('SELECT %s FROM %s WHERE %s ORDER BY %s LIMIT %d', $select, $quotedTable, $seek, $orderBy, $limit), $bindings];
    }

    /**
     * The WHERE condition that seeks past the cursor. PostgreSQL and SQLite
     * range-scan a row-value comparison `(a, b) > (?, ?)`; MySQL and MariaDB do
     * not reliably, so they get the expanded `(a > ?) OR (a = ? AND b > ?)`.
     *
     * @param  list<string>  $keyColumns
     * @param  list<mixed>  $cursor
     * @return array{string, list<mixed>}
     */
    private function seekCondition(array $keyColumns, array $cursor, string $operator, DatabaseConnectionType $type): array
    {
        $quoted = array_map(fn (string $c): string => $this->quoteTable($c, $type), $keyColumns);

        if ($type !== DatabaseConnectionType::Mysql && $type !== DatabaseConnectionType::MariaDB) {
            return [
                sprintf('(%s) %s (%s)', implode(', ', $quoted), $operator, implode(', ', array_map(static fn (): string => '?', $quoted))),
                $cursor,
            ];
        }

        $terms = [];
        $bindings = [];

        foreach ($quoted as $i => $column) {
            $parts = [];
            for ($j = 0; $j < $i; $j++) {
                $parts[] = $quoted[$j].' = ?';
                $bindings[] = $cursor[$j];
            }

            $parts[] = sprintf('%s %s ?', $column, $operator);
            $bindings[] = $cursor[$i];
            $terms[] = '('.implode(' AND ', $parts).')';
        }

        return [implode(' OR ', $terms), $bindings];
    }

    /**
     * The key values of the chunk's last row, to seek past on the next read
     * (empty when the table is read with LIMIT/OFFSET). A missing or NULL key
     * value, or a cursor that did not move past the previous chunk, fails the
     * table instead of ending it early or reading the same rows forever.
     *
     * @param  non-empty-list<object>  $chunk
     * @param  list<string>  $keyColumns
     * @param  list<mixed>|null  $previous
     * @return list<mixed>
     */
    private function keysetCursor(array $chunk, array $keyColumns, string $tableName, ?array $previous): array
    {
        $lastRow = (array) $chunk[count($chunk) - 1];

        $cursor = array_map(static function (string $key) use ($lastRow, $tableName): mixed {
            if (! isset($lastRow[$key])) {
                throw new RuntimeException(sprintf('Cannot page table %s past a row without a value in key column %s', $tableName, $key));
            }

            return $lastRow[$key];
        }, $keyColumns);

        if ($cursor !== [] && $cursor === $previous) {
            throw new RuntimeException(sprintf('Cannot page table %s: the key values of the last row did not move past the previous chunk', $tableName));
        }

        return $cursor;
    }

    /**
     * Legacy LIMIT/OFFSET (or OFFSET/FETCH) chunk query — used when keyset does
     * not apply. `$limit` is already capped at the row limit.
     */
    private function offsetChunkQuery(TableCloningConfigData $config, ConnectionData $source, int $offset, int $limit, string $select, string $direction): string
    {
        $table = $config->tableName;
        $rows = $config->rows;
        $driver = $source->type->value;

        if ($rows->strategy === 'full') {
            return match ($driver) {
                'sqlsrv' => sprintf('SELECT %s FROM [%s] ORDER BY (SELECT NULL) OFFSET %d ROWS FETCH NEXT %d ROWS ONLY', $select, $table, $offset, $limit),
                'pgsql', 'sqlite' => sprintf('SELECT %s FROM "%s" LIMIT %d OFFSET %d', $select, $table, $limit, $offset),
                default => sprintf('SELECT %s FROM `%s` LIMIT %d OFFSET %d', $select, $table, $limit, $offset),
            };
        }

        $sortCol = $rows->sortBy ?? 'id';

        return match ($driver) {
            'pgsql', 'sqlite' => sprintf('SELECT %s FROM "%s" ORDER BY "%s" %s LIMIT %d OFFSET %d', $select, $table, $sortCol, $direction, $limit, $offset),
            'sqlsrv' => sprintf('SELECT %s FROM [%s] ORDER BY [%s] %s OFFSET %d ROWS FETCH NEXT %d ROWS ONLY', $select, $table, $sortCol, $direction, $offset, $limit),
            default => sprintf('SELECT %s FROM `%s` ORDER BY `%s` %s LIMIT %d OFFSET %d', $select, $table, $sortCol, $direction, $limit, $offset),
        };
    }

    /**
     * Inspect the live target. Returns null when inspection fails; the run then
     * relies on source metadata and the YAML config alone.
     */
    private function inspectTarget(ConnectionData $target): ?DatabaseSchemaData
    {
        try {
            return $this->inspector->inspect($target);
        } catch (Throwable $throwable) {
            Log::warning('target_inspection_failed', ['error' => $throwable->getMessage()]);

            return null;
        }
    }

    /**
     * Decide which columns are left out of the transfer and why.
     *
     * A column is left out when it is generated in the source or in the target,
     * or when its strategy is 'exclude'. Generated source values are never
     * copied, even into a regular target column: they may be derived from PII
     * (e.g. full_name = CONCAT(first_name, last_name)) and would bypass the
     * anonymization of the columns they are computed from.
     *
     * @return array{list<string>, list<string>} [omittedColumns, notes]
     */
    private function resolveOmittedColumns(
        TableCloningConfigData $tableConfig,
        ?TableSchemaData $sourceTable,
        ?TableSchemaData $targetTable,
        bool $toDump,
    ): array {
        /** @var array<string, ColumnSchemaData> $targetColumns */
        $targetColumns = [];

        foreach ($targetTable->columns ?? [] as $col) {
            $targetColumns[$col->name] = $col;
        }

        $computedByTarget = [];
        $withoutExpression = [];
        $unknownTarget = [];
        $excluded = [];
        $notNullExcluded = [];

        foreach ($sourceTable->columns ?? [] as $col) {
            $targetCol = $targetColumns[$col->name] ?? null;
            $colConfig = $tableConfig->getColumn($col->name);

            if ($col->generated || $targetCol?->generated === true) {
                // Dumps never carry the expression; live targets lose it when the
                // column exists there as a regular column.
                if ($targetCol?->generated === true) {
                    $computedByTarget[] = $col->name;
                } elseif ($toDump || $targetCol instanceof ColumnSchemaData) {
                    $withoutExpression[] = $col->name;
                } else {
                    $unknownTarget[] = $col->name;
                }

                continue;
            }

            if ($colConfig instanceof ColumnCloningConfigData && $colConfig->strategy === 'exclude') {
                $excluded[] = $col->name;

                if ($targetCol instanceof ColumnSchemaData && ! $targetCol->nullable && $targetCol->default === null && ! $targetCol->isPrimary) {
                    $notNullExcluded[] = $col->name;
                }
            }
        }

        $notes = [];

        if ($computedByTarget !== []) {
            $notes[] = sprintf('Generated columns not copied, computed by the target: %s', implode(', ', $computedByTarget));
        }

        if ($withoutExpression !== []) {
            $notes[] = sprintf(
                'Generated columns not copied and left empty: %s. The generation expression was not replicated to the target.',
                implode(', ', $withoutExpression),
            );
        }

        if ($unknownTarget !== []) {
            $notes[] = sprintf('Generated columns not copied: %s', implode(', ', $unknownTarget));
        }

        if ($excluded !== []) {
            $notes[] = sprintf("Columns excluded by 'exclude' strategy: %s", implode(', ', $excluded));
        }

        if ($notNullExcluded !== []) {
            $notes[] = sprintf(
                'Excluded columns are NOT NULL without a default on the target, inserts may fail: %s',
                implode(', ', $notNullExcluded),
            );
        }

        return [array_merge($computedByTarget, $withoutExpression, $unknownTarget, $excluded), $notes];
    }

    private function quoteTable(string $name, DatabaseConnectionType $driver): string
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
