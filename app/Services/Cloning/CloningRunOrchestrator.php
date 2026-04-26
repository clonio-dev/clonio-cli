<?php

declare(strict_types=1);

namespace App\Services\Cloning;

use App\Data\Cloning\CloningConfigData;
use App\Data\Cloning\CloningOptionsData;
use App\Data\Cloning\ColumnCloningConfigData;
use App\Data\Cloning\KeyRemappingConfigData;
use App\Data\Cloning\RunResultData;
use App\Data\Cloning\TableCloningConfigData;
use App\Data\Cloning\TableRunResultData;
use App\Data\Cloning\TableRunStatus;
use App\Data\ConnectionData;
use App\Data\Schema\ColumnSchemaData;
use App\Data\Schema\DatabaseSchemaData;
use App\Data\Schema\TableSchemaData;
use App\Enums\ClearMode;
use App\Enums\DatabaseConnectionType;
use App\Services\Database\DatabaseConnectionService;
use Illuminate\Support\Facades\DB;
use Throwable;

class CloningRunOrchestrator
{
    public function __construct(
        private readonly DatabaseConnectionService $connector,
        private readonly SchemaReplicator $replicator,
        private readonly DependencyResolver $resolver,
        private readonly RunLogWriter $runLog,
    ) {}

    /**
     * @param  list<string>  $skipTables  Tables to exclude (already validated as mutually exclusive with onlyTables)
     * @param  list<string>  $onlyTables  If non-empty, only these tables are transferred
     * @param  callable(string, TableRunStatus, int, int, list<SkippedRow>): void  $onProgress
     * @param  (callable(string): void)|null  $onTableStart  Optional. Fires once per table that enters `transferTable()`, regardless of whether the transfer ultimately succeeds (`Transferred`) or fails (`Failed`). Does NOT fire for tables resolved as `SkippedByFlag`, `SkippedByCascade`, `NotFound`, or `SkippedBySchemaFailure`.
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

        // Replicate schema if not skipping
        /** @var array<string, string> $schemaFailures */
        $schemaFailures = [];

        if (! $skipSchema) {
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
                $this->runLog->log('info', 'schema_replicated', ['tables' => $sortedTables]);
            } else {
                foreach ($schemaFailures as $failedTable => $errorMsg) {
                    $this->runLog->log('error', 'schema_table_failed', ['table' => $failedTable, 'error' => $errorMsg]);
                }
            }
        }

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
            $this->runLog->log('info', 'table_skipped_by_flag', ['table' => $tableName, 'reason' => $reason]);
        }

        foreach ($cascadeExclusions as $tableName) {
            $reason = 'excluded due to foreign key dependency';
            $tableResults[] = new TableRunResultData($tableName, TableRunStatus::SkippedByCascade, 0, 0, 0.0, $reason);
            $this->runLog->log('info', 'table_skipped_by_cascade', ['table' => $tableName, 'reason' => $reason]);
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
                $this->runLog->log('warning', 'table_skipped_schema_failure', ['table' => $tableName, 'reason' => $reason]);
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
                $this->runLog->log('warning', 'table_not_found', ['table' => $tableName]);
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

            [$rows, $skipped, $failed, $reason, $skippedRows] = $this->transferTable(
                $config->options,
                $tableConfig,
                $source,
                $target,
                $pkColumns,
                $keyRemapping,
                $config->keyRemapping,
            );

            $tableDuration = microtime(true) - $tableStart;

            $status = $failed ? TableRunStatus::Failed : TableRunStatus::Transferred;

            if ($failed) {
                $success = false;
                $this->runLog->log('error', 'table_transfer_failed', ['table' => $tableName, 'reason' => $reason]);
            } else {
                $this->runLog->log('info', 'table_transferred', ['table' => $tableName, 'rows' => $rows, 'skipped' => $skipped]);
            }

            $tableResults[] = new TableRunResultData($tableName, $status, $rows, $skipped, $tableDuration, $reason);
            $totalRows += $rows;
            $totalSkipped += $skipped;
            ($onProgress)($tableName, $status, $rows, $skipped, $skippedRows);

            if ($failed && $breakOnFailure) {
                break;
            }

            if (! $failed && $sourceTable instanceof TableSchemaData) {
                $pkColumn = $this->findIntegerPkColumn($target, $sourceTable);
                if ($pkColumn !== null) {
                    try {
                        $this->replicator->correctAutoIncrement($target, $tableName, $pkColumn);
                    } catch (Throwable $e) {
                        $this->runLog->log('warning', 'auto_increment_correction_failed', ['table' => $tableName, 'error' => $e->getMessage()]);
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
     * @return array{int, int, bool, ?string, list<SkippedRow>}
     */
    private function transferTable(
        CloningOptionsData $options,
        TableCloningConfigData $tableConfig,
        ConnectionData $source,
        ConnectionData $target,
        array $pkColumns,
        ?KeyRemappingService $keyRemapping = null,
        ?KeyRemappingConfigData $keyRemappingConfig = null,
    ): array {
        $engine = new AnonymizationEngine($options->fakerLocale);
        $sourceConn = $this->connector->open($source);
        $targetConn = $this->connector->open($target);

        $rows = 0;
        $skipped = 0;
        $offset = 0;
        $chunkSize = $options->chunkSize;
        $firstInsertError = null;

        /** @var list<SkippedRow> $skippedRows */
        $skippedRows = [];

        try {
            if ($options->disableForeignKeyChecks) {
                $this->disableFkChecks($targetConn, $target);
            }

            if ($tableConfig->rows->clear !== ClearMode::None) {
                $this->clearTable($targetConn, $tableConfig->tableName, $tableConfig->rows->clear, $target);
            }

            do {
                /** @var list<object> $chunk */
                $chunk = DB::connection($sourceConn)->select(
                    $this->buildChunkQuery($tableConfig, $source, $offset, $chunkSize)
                );

                if ($chunk === []) {
                    break;
                }

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

                // Bulk insert into target
                try {
                    DB::connection($targetConn)->table($tableConfig->tableName)->insert($transformed);
                    $rows += count($transformed);
                } catch (Throwable $bulkError) {
                    if ($firstInsertError === null) {
                        $firstInsertError = $bulkError->getMessage();
                    }

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
                            $this->runLog->log('warning', 'row_skipped', [
                                'table' => $skippedRow->tableName,
                                'chunk_offset' => $skippedRow->chunkOffset,
                                'row_index' => $skippedRow->rowIndex,
                                'pk' => $skippedRow->pkSnapshot,
                                'error' => $skippedRow->sqlError,
                            ]);
                        }
                    }
                }

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

    private function buildChunkQuery(TableCloningConfigData $config, ConnectionData $source, int $offset, int $limit): string
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
