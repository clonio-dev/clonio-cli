<?php

declare(strict_types=1);

namespace App\Commands\Cloning;

use App\Data\Cloning\CloningConfigData;
use App\Data\Cloning\CloningOptionsData;
use App\Data\Cloning\ColumnCloningConfigData;
use App\Data\Cloning\DryRunResultData;
use App\Data\Cloning\DryRunTableData;
use App\Data\Cloning\KeyRemappingConfigData;
use App\Data\Cloning\TableCloningConfigData;
use App\Data\Cloning\TableRunResultData;
use App\Data\Cloning\TableRunStatus;
use App\Data\ConnectionData;
use App\Data\Schema\SchemaDiffData;
use App\Enums\ExitCode;
use App\Services\Audit\AuditDeliveryService;
use App\Services\Audit\AuditLogBuilder;
use App\Services\Audit\AuditLogRenderer;
use App\Services\Audit\AuditLogSigner;
use App\Services\Audit\LocalDeliveryAdapter;
use App\Services\Audit\StderrDeliveryAdapter;
use App\Services\Audit\StdoutDeliveryAdapter;
use App\Services\Cloning\CloningRunOrchestrator;
use App\Services\Cloning\CloningYamlLoader;
use App\Services\Cloning\CloningYamlValidator;
use App\Services\Cloning\EncryptedFileKeyRemappingStore;
use App\Services\Cloning\KeyRemappingService;
use App\Services\Cloning\RunLogWriter;
use App\Services\Config\ConfigService;
use App\Services\Database\DatabaseConnectionService;
use App\Services\Schema\SchemaDiffService;
use App\Services\Schema\SchemaInspector;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use LaravelZero\Framework\Commands\Command;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Yaml\Yaml;
use Throwable;

class RunCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'cloning:run
        {file                  : Path to the .cloning.yaml configuration file}
        {--target=             : Name of the target database connection (from clonio.json)}
        {--allow-failure       : Exit with code 0 even if the run fails}
        {--dry-run             : Validate, test connections, count rows — no data transferred}
        {--ci                  : CI mode — suppress all non-error output; strict exit codes}
        {--skip-schema         : Skip schema replication}
        {--skip-tables=        : Comma-separated list of table names to exclude}
        {--only-tables=        : Comma-separated list of table names to include}
        {--audit-channel=      : Comma-separated list of channel names}
        {--skip-remapping-keys      : Skip key mapping generation and FK rewriting}
        {--no-memory-limit          : Remove PHP memory limit before generating key mappings (useful for very large databases when --file-based is not an option)}
        {--file-based               : Store key mappings in AES-256-CBC encrypted temp files instead of RAM; keeps memory usage bounded to the largest single table}
        {--enforce-column-types     : Override: enforce_column_types true for this run}
        {--no-enforce-column-types  : Override: enforce_column_types false for this run}
        {--drop-unknown-tables      : Override: drop_unknown_tables true for this run}
        {--no-drop-unknown-tables   : Override: drop_unknown_tables false for this run}
        {--drop-extra-columns       : Override: drop_extra_columns true for this run}
        {--no-drop-extra-columns    : Override: drop_extra_columns false for this run}
        {--disable-fk-checks        : Override: disable_foreign_key_checks true for this run}
        {--no-disable-fk-checks     : Override: disable_foreign_key_checks false for this run}
        {--break-on-failure         : Abort the run immediately on the first table failure (schema or data)}';

    /**
     * @var string
     */
    protected $description = 'Transfer a database to a target environment using a cloning configuration';

    public function handle(
        ConfigService $configService,
        DatabaseConnectionService $connector,
        SchemaInspector $inspector,
        CloningRunOrchestrator $orchestrator,
        RunLogWriter $runLog,
    ): int {
        $ci = (bool) $this->option('ci');
        $verbose = $this->getOutput()->getVerbosity() >= OutputInterface::VERBOSITY_VERBOSE;

        // ─── Phase 1: YAML Validation ──────────────────────────────────────────
        $filePath = (string) $this->argument('file');

        if (! file_exists($filePath) && ! Storage::disk('local')->exists($filePath)) {
            $this->error(sprintf('File not found: %s', $filePath));

            return ExitCode::IoError->value;
        }

        $loader = new CloningYamlLoader;
        $validator = new CloningYamlValidator;

        try {
            $config = $loader->load($filePath);
        } catch (Throwable $throwable) {
            $this->error(sprintf('Failed to parse YAML: %s', $throwable->getMessage()));

            return ExitCode::ValidationError->value;
        }

        // Re-parse raw data for validator
        try {
            $content = Storage::disk('local')->get($filePath);

            if ($content === null) {
                $content = (string) file_get_contents($filePath);
            }

            $rawData = Yaml::parse($content);

            if (is_array($rawData)) {
                /** @var array<string, mixed> $rawData */
                $errors = $validator->validate($rawData);

                if ($errors !== []) {
                    foreach ($errors as $error) {
                        $this->error($error);
                    }

                    return ExitCode::ValidationError->value;
                }
            }
        } catch (Throwable $throwable) {
            $this->error(sprintf('Failed to validate YAML: %s', $throwable->getMessage()));

            return ExitCode::ValidationError->value;
        }

        if ($verbose) {
            $this->line('  <info>✓</info>  Validating YAML ...');
        }

        // Validate --skip-tables + --only-tables mutual exclusivity
        $skipTablesOpt = $this->option('skip-tables');
        $onlyTablesOpt = $this->option('only-tables');

        if ($skipTablesOpt !== null && $skipTablesOpt !== '' && $onlyTablesOpt !== null && $onlyTablesOpt !== '') {
            $this->error('--skip-tables and --only-tables are mutually exclusive.');

            return ExitCode::ValidationError->value;
        }

        /** @var list<string> $skipTables */
        $skipTables = ($skipTablesOpt !== null && $skipTablesOpt !== '')
            ? array_values(array_filter(array_map(trim(...), explode(',', (string) $skipTablesOpt))))
            : [];

        /** @var list<string> $onlyTables */
        $onlyTables = ($onlyTablesOpt !== null && $onlyTablesOpt !== '')
            ? array_values(array_filter(array_map(trim(...), explode(',', (string) $onlyTablesOpt))))
            : [];

        $auditChannelOpt = $this->option('audit-channel');
        /** @var list<string> $auditChannels */
        $auditChannels = ($auditChannelOpt !== null && $auditChannelOpt !== '')
            ? array_values(array_filter(array_map(trim(...), explode(',', (string) $auditChannelOpt))))
            : [];

        // ─── Option overrides ──────────────────────────────────────────────────
        $enforceOverride = $this->option('enforce-column-types') ? true : ($this->option('no-enforce-column-types') ? false : null);
        $dropUnkOverride = $this->option('drop-unknown-tables') ? true : ($this->option('no-drop-unknown-tables') ? false : null);
        $dropExtOverride = $this->option('drop-extra-columns') ? true : ($this->option('no-drop-extra-columns') ? false : null);
        $fkChecksOverride = $this->option('no-disable-fk-checks') ? false : ($this->option('disable-fk-checks') ? true : null);

        if ($enforceOverride !== null || $dropUnkOverride !== null || $dropExtOverride !== null || $fkChecksOverride !== null) {
            $opts = $config->options;
            $config = new CloningConfigData(
                version: $config->version,
                connectionName: $config->connectionName,
                options: new CloningOptionsData(
                    chunkSize: $opts->chunkSize,
                    enforceColumnTypes: $enforceOverride ?? $opts->enforceColumnTypes,
                    dropUnknownTables: $dropUnkOverride ?? $opts->dropUnknownTables,
                    dropExtraColumns: $dropExtOverride ?? $opts->dropExtraColumns,
                    disableForeignKeyChecks: $fkChecksOverride ?? $opts->disableForeignKeyChecks,
                    fakerLocale: $opts->fakerLocale,
                ),
                tables: $config->tables,
                keyRemapping: $config->keyRemapping,
                skipTables: $config->skipTables,
            );
        }

        // Merge YAML-level skipTables with CLI --skip-tables (additive, deduplicated)
        // Done here so both the dry-run and full-run paths see the merged list.
        $skipTables = array_values(array_unique(array_merge($skipTables, $config->skipTables)));

        // ─── Phase 2: Connection Checks ────────────────────────────────────────

        // Resolve source connection from config
        $sourceConnection = $configService->getConnection($config->connectionName);

        if (! $sourceConnection instanceof ConnectionData) {
            $this->error(sprintf("Source connection '%s' not found in clonio.json.", $config->connectionName));

            return ExitCode::ConnectionError->value;
        }

        // Resolve target connection
        $targetOption = $this->option('target');
        $targetName = is_string($targetOption) && $targetOption !== '' ? $targetOption : null;

        if ($targetName === null) {
            if ($ci) {
                $this->error('Target required in CI mode (use --target)');

                return ExitCode::ValidationError->value;
            }

            // Interactive: show choice list excluding source and production connections
            $connections = $configService->getConnections();
            $otherNames = array_values(array_filter(
                array_keys($connections),
                static fn (string $n): bool => $n !== $config->connectionName && ! $connections[$n]->isProduction
            ));

            if ($otherNames === []) {
                $this->error('No other connections available to use as target. Add a connection first.');

                return ExitCode::ConfigError->value;
            }

            $chosen = $this->choice('Select a target connection', $otherNames, 0);
            $targetName = is_string($chosen) ? $chosen : $otherNames[0];
        }

        $targetConnection = $configService->getConnection($targetName);

        if (! $targetConnection instanceof ConnectionData) {
            $this->error(sprintf("Target connection '%s' not found in clonio.json.", $targetName));

            return ExitCode::ConnectionError->value;
        }

        // Verify source != target
        if ($config->connectionName === $targetName) {
            $this->error('Source and target cannot be the same connection.');

            return ExitCode::ValidationError->value;
        }

        // Test both connections
        try {
            $sourceConnName = $connector->open($sourceConnection);
            DB::purge($sourceConnName);
        } catch (Throwable $throwable) {
            $this->error(sprintf("Cannot connect to source '%s': %s", $config->connectionName, $throwable->getMessage()));

            return ExitCode::ConnectionError->value;
        }

        try {
            $targetConnName = $connector->open($targetConnection);
            DB::purge($targetConnName);
        } catch (Throwable $throwable) {
            $this->error(sprintf("Cannot connect to target '%s': %s", $targetName, $throwable->getMessage()));

            return ExitCode::ConnectionError->value;
        }

        if ($verbose) {
            $this->line(sprintf('  <info>✓</info>  Connecting to %s and %s ...', $config->connectionName, $targetName));
        }

        // ─── Phase 3: Dry-run ──────────────────────────────────────────────────
        if ((bool) $this->option('dry-run')) {
            return $this->runDryRun($config, $sourceConnection, $targetConnection, $inspector, $ci);
        }

        // ─── Phase 4-6: Full Run ───────────────────────────────────────────────
        $startedAt = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $runLog->log('info', 'run_started', ['source' => $config->connectionName, 'target' => $targetName]);

        try {
            $sourceSchema = $inspector->inspect($sourceConnection);
        } catch (Throwable $throwable) {
            $this->error(sprintf('Failed to inspect source schema: %s', $throwable->getMessage()));

            return ExitCode::ConnectionError->value;
        }

        if ($verbose) {
            $this->line('  <info>✓</info>  Resolving table order ...');
        }

        // ─── Phase 5b: Key Mapping Generation ─────────────────────────────────
        $keyRemappingService = null;
        $skipRemapping = (bool) $this->option('skip-remapping-keys');
        $keyRemappingConfig = $config->keyRemapping;

        if ($keyRemappingConfig instanceof KeyRemappingConfigData && $keyRemappingConfig->isActive() && ! $skipRemapping) {
            if ((bool) $this->option('no-memory-limit')) {
                ini_set('memory_limit', '-1');
            }

            if ($verbose) {
                $this->line('  <info>✓</info>  Generating key mappings ...');
            }

            $keyRemappingService = (bool) $this->option('file-based')
                ? new KeyRemappingService($connector, new EncryptedFileKeyRemappingStore)
                : new KeyRemappingService($connector);
            $sortedForMapping = array_map(
                static fn (TableCloningConfigData $t): string => $t->tableName,
                $config->tables
            );
            $counts = $keyRemappingService->generateMappings($keyRemappingConfig, $sourceConnection, $sortedForMapping);

            if ($verbose) {
                foreach ($counts as $tbl => $cnt) {
                    $this->line(sprintf('  <info>✓</info>  Key mapping: %s (%s rows)', $tbl, number_format($cnt)));
                }
            }
        }

        $skipSchema = (bool) $this->option('skip-schema');

        if ($verbose) {
            try {
                $targetSchema = $inspector->inspect($targetConnection);
                $schemaDiff = (new SchemaDiffService)->diff($sourceSchema, $targetSchema);

                if ($schemaDiff->hasDifferences()) {
                    $parts = [];

                    if ($schemaDiff->missingTables !== []) {
                        $parts[] = count($schemaDiff->missingTables).' missing';
                    }

                    if ($schemaDiff->modifiedTables !== []) {
                        $parts[] = count($schemaDiff->modifiedTables).' modified';
                    }

                    if ($schemaDiff->extraTables !== []) {
                        $parts[] = count($schemaDiff->extraTables).' extra on target';
                    }

                    $this->line(sprintf('  <comment>~</comment>  Schema diff: %s', implode(', ', $parts)));
                } else {
                    $this->line('  <info>✓</info>  Schema diff: target matches source');
                }
            } catch (Throwable) {
                // non-fatal: skip diff output if target schema cannot be inspected
            }
        }

        if ($verbose && ! $skipSchema) {
            $this->line('  <info>✓</info>  Replicating schema ...');
        }

        /** @var list<string> $notFoundTables */
        $notFoundTables = [];

        /** @var list<string> $schemaFailureTables */
        $schemaFailureTables = [];

        $result = $orchestrator->run(
            config: $config,
            source: $sourceConnection,
            target: $targetConnection,
            sourceSchema: $sourceSchema,
            skipSchema: $skipSchema,
            skipTables: $skipTables,
            onlyTables: $onlyTables,
            onProgress: function (string $tableName, TableRunStatus $status, int $rows, int $skipped) use ($verbose, &$notFoundTables, &$schemaFailureTables): void {
                if ($verbose) {
                    if ($status === TableRunStatus::Transferred) {
                        $this->line(sprintf('  <info>✓</info>  %s  (%d rows)', $tableName, $rows));
                    } elseif ($status === TableRunStatus::NotFound) {
                        $this->line(sprintf('  <comment>✗</comment>  %s  — not found in source, skipped', $tableName));
                        $notFoundTables[] = $tableName;
                    } elseif ($status === TableRunStatus::Failed) {
                        $this->line(sprintf('  <error>✗</error>  %s  — failed', $tableName));
                    } elseif ($status === TableRunStatus::SkippedBySchemaFailure) {
                        $this->line(sprintf('  <error>S</error>  %s  — schema replication failed, skipped', $tableName));
                        $schemaFailureTables[] = $tableName;
                    }
                } elseif ($status === TableRunStatus::Transferred) {
                    $indicator = $skipped > 0 ? 'F' : '.';
                    $this->output->write($indicator);
                } elseif ($status === TableRunStatus::Failed) {
                    $this->output->write('E');
                } elseif ($status === TableRunStatus::NotFound) {
                    $this->output->write('?');
                    $notFoundTables[] = $tableName;
                } elseif ($status === TableRunStatus::SkippedBySchemaFailure) {
                    $this->output->write('S');
                    $schemaFailureTables[] = $tableName;
                }
            },
            keyRemapping: $keyRemappingService,
            breakOnFailure: (bool) $this->option('break-on-failure'),
        );

        $finishedAt = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $runLog->log('info', 'run_finished', ['success' => $result->success, 'rows' => $result->totalRows]);

        // ─── Phase 6b: Key Mapping Cleanup ────────────────────────────────────
        if ($keyRemappingService instanceof KeyRemappingService) {
            $keyRemappingService->cleanup();
            $runLog->log('info', 'key_mapping_cleanup_completed', []);
        }

        // ─── Phase 7: Audit ────────────────────────────────────────────────────
        $cloningConfig = $configService->load();
        $rawAuditConfig = $cloningConfig['audit'] ?? null;
        /** @var array<string, mixed>|null $auditConfig */
        $auditConfig = is_array($rawAuditConfig) ? $rawAuditConfig : null;

        if ($auditConfig !== null || $auditChannels !== []) {
            if ($verbose) {
                $this->line('  <info>✓</info>  Generating audit log ...');
            }

            $signer = new AuditLogSigner;
            $builder = new AuditLogBuilder($signer);
            $renderer = new AuditLogRenderer;
            $localAdapter = new LocalDeliveryAdapter;
            $deliveryService = new AuditDeliveryService($localAdapter, new StdoutDeliveryAdapter, new StderrDeliveryAdapter, $runLog);

            $yamlFileName = basename($filePath);
            $auditRecord = $builder->build(
                config: $config,
                result: $result,
                targetConnection: $targetName,
                startedAt: $startedAt,
                finishedAt: $finishedAt,
                yamlFileName: $yamlFileName,
            );

            [$canonicalJson, $contentHash, $hmacSignature] = $signer->sign($auditRecord);
            $htmlContent = $renderer->render($auditRecord, $canonicalJson);

            $timestamp = $startedAt->format('Y-m-d\TH-i-s\Z');
            $baseFilename = sprintf('%s_%s_%s', $config->connectionName, $targetName, $timestamp);
            $auditArtefacts = [
                $baseFilename.'_audit.html' => $htmlContent,
                $baseFilename.'_audit.sig' => 'sha256:'.$hmacSignature,
            ];

            $templateVars = [
                'year' => $startedAt->format('Y'),
                'month' => $startedAt->format('m'),
                'day' => $startedAt->format('d'),
                'source' => $config->connectionName,
                'target' => $targetName,
                'timestamp' => $timestamp,
            ];

            $processLogContent = $runLog->flush();

            $deliveryService->deliver(
                auditConfig: $auditConfig,
                auditArtefacts: $auditArtefacts,
                processLogContent: $processLogContent,
                channelOverride: $auditChannels,
                templateVars: $templateVars,
            );

            if ($verbose) {
                $channelTypes = [];

                if (is_array($auditConfig)) {
                    /** @var array<string, mixed> $channels */
                    $channels = is_array($auditConfig['channels'] ?? null) ? $auditConfig['channels'] : [];

                    foreach ($channels as $channelConfig) {
                        if (is_array($channelConfig) && isset($channelConfig['type']) && is_string($channelConfig['type'])) {
                            $channelTypes[] = $channelConfig['type'];
                        }
                    }
                }

                if ($channelTypes !== []) {
                    $this->line(sprintf('  <info>✓</info>  Delivering via %s ...', implode(', ', $channelTypes)));
                }
            }
        }

        // ─── Phase 8: Summary ──────────────────────────────────────────────────
        if (! $verbose && ! $ci) {
            $this->line('');
        }

        // Derive not-found from result (merging with any collected during progress)
        $notFoundFromResult = array_values(array_map(
            static fn (TableRunResultData $t): string => $t->tableName,
            array_filter($result->tables, static fn (TableRunResultData $t): bool => $t->status === TableRunStatus::NotFound)
        ));
        $notFoundTables = array_values(array_unique(array_merge($notFoundTables, $notFoundFromResult)));

        // Always show not-found warnings
        if ($notFoundTables !== []) {
            $this->line('');
            $this->line(sprintf(
                '  <comment>Warning: %d table%s not found in source (%s)</comment>',
                count($notFoundTables),
                count($notFoundTables) === 1 ? '' : 's',
                implode(', ', $notFoundTables),
            ));
        }

        // Collect schema failure tables from result (merge with those caught in onProgress)
        $schemaFailuresFromResult = array_values(array_map(
            static fn (TableRunResultData $t): string => $t->tableName,
            array_filter($result->tables, static fn (TableRunResultData $t): bool => $t->status === TableRunStatus::SkippedBySchemaFailure)
        ));
        $schemaFailureTables = array_values(array_unique(array_merge($schemaFailureTables, $schemaFailuresFromResult)));

        if ($schemaFailureTables !== []) {
            $this->line('');
            $this->line(sprintf(
                '  <error>Error: %d table%s skipped due to schema replication failure (%s)</error>',
                count($schemaFailureTables),
                count($schemaFailureTables) === 1 ? '' : 's',
                implode(', ', $schemaFailureTables),
            ));
        }

        $transferredCount = count(array_filter($result->tables, static fn (TableRunResultData $t): bool => $t->status === TableRunStatus::Transferred));
        $totalCount = count($result->tables);
        $duration = $this->formatDuration($result->durationSeconds);

        if (! $ci) {
            $this->line('');
            $this->line(sprintf('  Tables: %d/%d  Rows: %d  Duration: %s', $transferredCount, $totalCount, $result->totalRows, $duration));
            $this->line('');
        }

        if (! $result->success && ! (bool) $this->option('allow-failure')) {
            return ExitCode::GeneralError->value;
        }

        return ExitCode::Success->value;
    }

    private function runDryRun(
        CloningConfigData $config,
        ConnectionData $source,
        ConnectionData $target,
        SchemaInspector $inspector,
        bool $ci,
    ): int {
        try {
            $schema = $inspector->inspect($source);
        } catch (Throwable $throwable) {
            $this->error(sprintf('Failed to inspect source schema: %s', $throwable->getMessage()));

            return ExitCode::ConnectionError->value;
        }

        $schemaDiff = null;

        try {
            $targetSchema = $inspector->inspect($target);
            $schemaDiff = (new SchemaDiffService)->diff($schema, $targetSchema);
        } catch (Throwable) {
            // non-fatal: proceed without diff if target cannot be inspected
        }

        /** @var list<DryRunTableData> $dryRunTables */
        $dryRunTables = [];
        $totalEstimated = 0;
        $notFoundCount = 0;

        foreach ($config->tables as $tableConfig) {
            $tableName = $tableConfig->tableName;
            $exists = $schema->hasTable($tableName);
            $estimatedRows = null;

            if ($exists) {
                try {
                    $estimatedRows = $this->countRows($source, $tableName);
                } catch (Throwable) {
                    $estimatedRows = null;
                }

                if ($estimatedRows !== null) {
                    $strategy = $tableConfig->rows->strategy;
                    $limit = $tableConfig->rows->limit;

                    if (($strategy === 'first' || $strategy === 'last') && $limit !== null) {
                        $estimatedRows = min($estimatedRows, $limit);
                    }

                    $totalEstimated += $estimatedRows;
                }
            } else {
                $notFoundCount++;
            }

            $transformedColumns = array_values(array_map(
                static fn (ColumnCloningConfigData $col): string => $col->columnName,
                array_filter($tableConfig->columns, static fn (ColumnCloningConfigData $col): bool => $col->strategy !== 'keep')
            ));

            $dryRunTables[] = new DryRunTableData(
                tableName: $tableName,
                exists: $exists,
                estimatedRows: $estimatedRows,
                rowStrategy: $tableConfig->rows->strategy,
                rowLimit: $tableConfig->rows->limit,
                transformedColumns: $transformedColumns,
            );
        }

        $dryRunResult = new DryRunResultData(
            tables: $dryRunTables,
            totalEstimatedRows: $totalEstimated,
            notFoundCount: $notFoundCount,
        );

        if (! $ci) {
            $this->renderDryRunTable($dryRunResult, $config->connectionName, $schemaDiff);
        }

        return ExitCode::Success->value;
    }

    private function countRows(ConnectionData $connection, string $tableName): ?int
    {
        $connector = resolve(DatabaseConnectionService::class);

        try {
            $connName = $connector->open($connection);

            try {
                $driver = $connection->type->value;
                $quotedTable = match ($driver) {
                    'mysql', 'mariadb' => '`'.$tableName.'`',
                    'sqlsrv' => '['.$tableName.']',
                    default => '"'.$tableName.'"',
                };
                $sql = 'SELECT COUNT(*) as count FROM '.$quotedTable;
                /** @var list<object> $rows */
                $rows = DB::connection($connName)->select($sql);
                $first = $rows[0] ?? null;

                if ($first === null) {
                    return null;
                }

                $row = (array) $first;
                $count = $row['count'] ?? 0;

                return is_numeric($count) ? (int) $count : 0;
            } finally {
                DB::purge($connName);
            }
        } catch (Throwable) {
            return null;
        }
    }

    private function renderSchemaDiff(SchemaDiffData $diff): void
    {
        if ($diff->isIdentical()) {
            $this->line('  Schema diff: target matches source');
            $this->line('');

            return;
        }

        $this->line('  Schema diff: source → target');
        $this->line('');

        if ($diff->missingTables !== []) {
            $this->line(sprintf('  Missing tables (%d):   %s', count($diff->missingTables), implode(', ', $diff->missingTables)));
        }

        if ($diff->extraTables !== []) {
            $this->line(sprintf('  Extra tables (%d):     %s', count($diff->extraTables), implode(', ', $diff->extraTables)));
        }

        foreach ($diff->modifiedTables as $tableDiff) {
            $parts = [];

            if ($tableDiff->missingColumns !== []) {
                $parts[] = sprintf('+%d col%s (%s)', count($tableDiff->missingColumns), count($tableDiff->missingColumns) === 1 ? '' : 's', implode(', ', $tableDiff->missingColumns));
            }

            if ($tableDiff->extraColumns !== []) {
                $parts[] = sprintf('-%d col%s (%s)', count($tableDiff->extraColumns), count($tableDiff->extraColumns) === 1 ? '' : 's', implode(', ', $tableDiff->extraColumns));
            }

            foreach ($tableDiff->modifiedColumns as $colDiff) {
                $parts[] = sprintf('~%s (%s → %s%s)', $colDiff->name, $colDiff->sourceType, $colDiff->targetType, $colDiff->sourceNullable !== $colDiff->targetNullable ? ', nullable changed' : '');
            }

            $this->line(sprintf('  Modified table:        %s: %s', $tableDiff->tableName, implode(', ', $parts)));
        }

        $this->line('');
    }

    private function renderDryRunTable(DryRunResultData $result, string $sourceName, ?SchemaDiffData $schemaDiff = null): void
    {
        $this->line('');
        $this->line(sprintf('  Dry-run: %s', $sourceName));
        $this->line('');

        if ($schemaDiff instanceof SchemaDiffData) {
            $this->renderSchemaDiff($schemaDiff);
        }

        $tableWidth = 24;
        $rowsWidth = 14;
        $strategyWidth = 10;

        $header = sprintf(
            '  %-'.$tableWidth.'s %-'.$rowsWidth.'s %-'.$strategyWidth.'s %s',
            'Table',
            'Rows (est.)',
            'Strategy',
            'Transformations'
        );

        $separator = '  '.str_repeat('─', $tableWidth + $rowsWidth + $strategyWidth + 20);

        $this->line($header);
        $this->line($separator);

        foreach ($result->tables as $table) {
            $rowsLabel = $table->exists
                ? ($table->estimatedRows !== null ? number_format($table->estimatedRows) : '?')
                : 'NOT FOUND';

            $strategyLabel = $table->exists
                ? ($table->rowStrategy === 'full' ? 'full' : $table->rowStrategy.' '.number_format((int) $table->rowLimit))
                : ('—');

            $transformLabel = $table->transformedColumns !== []
                ? implode(', ', $table->transformedColumns)
                : '—';

            $this->line(sprintf(
                '  %-'.$tableWidth.'s %-'.$rowsWidth.'s %-'.$strategyWidth.'s %s',
                $table->tableName,
                $rowsLabel,
                $strategyLabel,
                $transformLabel,
            ));
        }

        $this->line($separator);

        $notFoundMsg = $result->notFoundCount > 0
            ? sprintf(', %d not found, will be skipped', $result->notFoundCount)
            : '';

        $this->line(sprintf(
            '  %-'.$tableWidth.'s %-'.$rowsWidth.'s',
            'Total',
            number_format($result->totalEstimatedRows).' rows across '.count($result->tables).' tables'.$notFoundMsg,
        ));

        $this->line('');
        $this->line('  No data will be transferred. Run without --dry-run to execute.');
        $this->line('');
    }

    private function formatDuration(float $seconds): string
    {
        if ($seconds < 60) {
            return sprintf('%.0fs', $seconds);
        }

        $minutes = (int) ($seconds / 60);
        $remaining = $seconds % 60;

        return sprintf('%dm %ds', $minutes, $remaining);
    }
}
