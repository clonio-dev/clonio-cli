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
use App\Data\Schema\DatabaseSchemaData;
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
        if (! $skipSchema) {
            try {
                $this->replicator->replicate(
                    $source,
                    $target,
                    $sourceSchema,
                    $sortedTables,
                    $config->options->enforceColumnTypes,
                    $config->options->dropUnknownTables,
                );
                $this->runLog->log('info', 'schema_replicated', ['tables' => $sortedTables]);
            } catch (Throwable $e) {
                $this->runLog->log('error', 'schema_replication_failed', ['error' => $e->getMessage()]);
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
            $tableResults[] = new TableRunResultData($tableName, TableRunStatus::SkippedByFlag, 0, 0, 0.0, null);
            $this->runLog->log('info', 'table_skipped_by_flag', ['table' => $tableName]);
        }

        foreach ($cascadeExclusions as $tableName) {
            $tableResults[] = new TableRunResultData($tableName, TableRunStatus::SkippedByCascade, 0, 0, 0.0, null);
            $this->runLog->log('info', 'table_skipped_by_cascade', ['table' => $tableName]);
        }

        // Transfer each remaining table
        foreach ($sortedTables as $tableName) {
            $tableConfig = $config->getTable($tableName);

            if (! $tableConfig instanceof TableCloningConfigData) {
                continue;
            }

            // Check if table exists in source
            if (! $sourceSchema->hasTable($tableName)) {
                $tableResults[] = new TableRunResultData($tableName, TableRunStatus::NotFound, 0, 0, 0.0, null);
                $this->runLog->log('warning', 'table_not_found', ['table' => $tableName]);
                ($onProgress)($tableName, TableRunStatus::NotFound, 0, 0);

                continue;
            }

            $tableStart = microtime(true);
            [$rows, $skipped, $failed, $reason] = $this->transferTable($config->options, $tableConfig, $source, $target, $keyRemapping, $config->keyRemapping);
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
            ($onProgress)($tableName, $status, $rows, $skipped);
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
     * Transfer a single table. Returns [rowsTransferred, rowsSkipped, hasFailed, failureReason].
     *
     * @return array{int, int, bool, ?string}
     */
    private function transferTable(
        CloningOptionsData $options,
        TableCloningConfigData $tableConfig,
        ConnectionData $source,
        ConnectionData $target,
        ?KeyRemappingService $keyRemapping = null,
        ?KeyRemappingConfigData $keyRemappingConfig = null,
    ): array {
        $engine = new AnonymizationEngine($options->fakerLocale);
        $sourceConn = $this->connector->open($source);
        $targetConn = $this->connector->open($target);

        try {
            if ($options->disableForeignKeyChecks) {
                $this->disableFkChecks($targetConn, $target);
            }

            if ($tableConfig->rows->clear !== ClearMode::None) {
                $this->clearTable($targetConn, $tableConfig->tableName, $tableConfig->rows->clear, $target);
            }

            $rows = 0;
            $skipped = 0;
            $offset = 0;
            $chunkSize = $options->chunkSize;

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
                } catch (Throwable) {
                    // Fall back to row-by-row
                    foreach ($transformed as $row) {
                        try {
                            DB::connection($targetConn)->table($tableConfig->tableName)->insert($row);
                            $rows++;
                        } catch (Throwable) {
                            $skipped++;
                        }
                    }
                }

                $offset += count($chunk);
            } while (count($chunk) === $chunkSize);

            return [$rows, $skipped, false, null];
        } catch (Throwable $throwable) {
            return [0, 0, true, $throwable->getMessage()];
        } finally {
            if ($options->disableForeignKeyChecks) {
                $this->enableFkChecks($targetConn, $target);
            }

            DB::purge($sourceConn);
            DB::purge($targetConn);
        }
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
