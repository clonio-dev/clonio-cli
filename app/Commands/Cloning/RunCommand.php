<?php

declare(strict_types=1);

namespace App\Commands\Cloning;

use App\Data\Cloning\CloningConfigData;
use App\Data\Cloning\CloningOptionsData;
use App\Data\Cloning\ColumnCloningConfigData;
use App\Data\Cloning\DryRunResultData;
use App\Data\Cloning\DryRunTableData;
use App\Data\Cloning\KeyRemappingConfigData;
use App\Data\Cloning\StatsTableTransferData;
use App\Data\Cloning\TableCloningConfigData;
use App\Data\Cloning\TableRunResultData;
use App\Data\Cloning\TableRunStatus;
use App\Data\ConnectionData;
use App\Data\Schema\DatabaseSchemaData;
use App\Data\Schema\SchemaDiffData;
use App\Data\SqlDump\DumpResultData;
use App\Enums\AuditChannelType;
use App\Enums\DatabaseConnectionType;
use App\Enums\ExitCode;
use App\Exceptions\KeyRemappingExhaustedException;
use App\Logging\AuditBuffer;
use App\Logging\ConsoleOutputHandler;
use App\Services\Audit\AuditDeliveryService;
use App\Services\Audit\AuditLogBuilder;
use App\Services\Audit\AuditLogRenderer;
use App\Services\Audit\AuditLogSigner;
use App\Services\Audit\EmailDeliveryAdapter;
use App\Services\Audit\LocalDeliveryAdapter;
use App\Services\Audit\NtfyDeliveryAdapter;
use App\Services\Audit\S3DeliveryAdapter;
use App\Services\Audit\StderrDeliveryAdapter;
use App\Services\Audit\StdoutDeliveryAdapter;
use App\Services\Audit\WebhookDeliveryAdapter;
use App\Services\Cloning\CloningRunOrchestrator;
use App\Services\Cloning\CloningYamlLoader;
use App\Services\Cloning\CloningYamlValidator;
use App\Services\Cloning\DependencyResolver;
use App\Services\Cloning\EncryptedFileKeyRemappingStore;
use App\Services\Cloning\KeyRemappingService;
use App\Services\Cloning\SkippedRow;
use App\Services\Cloning\TableConfigResolver;
use App\Services\Config\ConfigService;
use App\Services\Database\DatabaseConnectionService;
use App\Services\Output\CloningProgressDisplay;
use App\Services\Output\VerboseStepRenderer;
use App\Services\Schema\SchemaDiffService;
use App\Services\Schema\SchemaInspector;
use App\Services\SqlDump\DumpArchiver;
use App\Services\SqlDump\DumpDialectFactory;
use App\Services\SqlDump\SqlDumpService;
use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Log\Logger;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use LaravelZero\Framework\Commands\Command;
use Monolog\Handler\StreamHandler;
use Monolog\Level;
use Monolog\Logger as Monolog;
use RuntimeException;
use Symfony\Component\Console\Output\ConsoleOutput;
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
        {--remap-keys               : Override: remap_keys true for this run}
        {--no-remap-keys            : Override: remap_keys false for this run (skip key mapping generation and FK rewriting)}
        {--skip-remapping-keys      : Alias of --no-remap-keys}
        {--no-memory-limit          : Remove PHP memory limit before generating key mappings (useful for very large databases when --file-based is not an option)}
        {--file-based               : Store key mappings in AES-256-CBC encrypted temp files instead of RAM; keeps memory usage bounded to the largest single table}
        {--enforce-column-types     : Override: enforce_column_types true for this run}
        {--no-enforce-column-types  : Override: enforce_column_types false for this run}
        {--drop-unknown-tables      : Override: drop_unknown_tables true for this run}
        {--no-drop-unknown-tables   : Override: drop_unknown_tables false for this run}
        {--drop-extra-columns       : Override: drop_extra_columns true for this run}
        {--no-drop-extra-columns    : Override: drop_extra_columns false for this run}
        {--recreate-tables          : Override: recreate_tables true for this run}
        {--no-recreate-tables       : Override: recreate_tables false for this run}
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
        AuditBuffer $auditBuffer,
        TableConfigResolver $tableResolver,
        DependencyResolver $dependencyResolver,
    ): int {
        $ci = (bool) $this->option('ci');
        $verbosity = $this->getOutput()->getVerbosity();
        $isVerbose = $verbosity >= OutputInterface::VERBOSITY_VERBOSE;
        $isVeryVerbose = $verbosity >= OutputInterface::VERBOSITY_VERY_VERBOSE;
        $isDebug = $verbosity >= OutputInterface::VERBOSITY_DEBUG;

        // Live nested progress bars are driven by verbosity on an interactive TTY:
        // `-v` shows the bars (compact), `-vv` adds full per-phase throughput to the
        // per-table bar, `-vvv` additionally prints the per-table timing summary.
        $out = $this->output->getOutput();
        $showProgress = $isVerbose
            && ! $ci
            && $this->output->isDecorated()
            && $out instanceof ConsoleOutput;

        // Map Symfony verbosity to stderr log threshold so `-v` / `-vv` surface
        // info / debug events on stderr without needing a separate live-output path.
        // While live bars run, redirectLogsTo() moves warnings into the log section.
        $stderrLevel = $isVeryVerbose ? 'debug' : ($isVerbose ? 'info' : ($ci ? 'error' : 'warning'));
        config(['logging.channels.stderr.level' => $stderrLevel]);

        $step = new VerboseStepRenderer($this->output, $ci);

        // ─── Phase 1: YAML Validation ──────────────────────────────────────────
        $filePath = (string) $this->argument('file');

        if (! file_exists($filePath) && ! Storage::disk('local')->exists($filePath)) {
            $this->error(sprintf('File not found: %s', $filePath));

            return ExitCode::IoError->value;
        }

        $loader = new CloningYamlLoader;
        $validator = new CloningYamlValidator;

        try {
            $config = $step->run('Validating YAML', function () use ($loader, $validator, $filePath): CloningConfigData {
                $config = $loader->load($filePath);

                $content = Storage::disk('local')->get($filePath);
                $content ??= (string) file_get_contents($filePath); // @pest-mutate-ignore: RemoveStringCast

                $rawData = Yaml::parse($content);
                if (is_array($rawData)) {
                    /** @var array<string, mixed> $rawData */
                    $errors = $validator->validate($rawData);
                    if ($errors !== []) {
                        throw new RuntimeException(implode("\n", $errors));
                    }
                }

                return $config;
            });
        } catch (RuntimeException $validationError) {
            foreach (explode("\n", $validationError->getMessage()) as $validationLine) {
                $this->error($validationLine);
            }

            return ExitCode::ValidationError->value;
        } catch (Throwable $throwable) {
            $this->error(sprintf('Failed to parse or validate YAML: %s', $throwable->getMessage()));

            return ExitCode::ValidationError->value;
        }

        // Validate --skip-tables + --only-tables mutual exclusivity
        $skipTablesOpt = $this->option('skip-tables');
        $onlyTablesOpt = $this->option('only-tables');

        if ($skipTablesOpt !== null && $skipTablesOpt !== '' && $onlyTablesOpt !== null && $onlyTablesOpt !== '') {
            $this->error('--skip-tables and --only-tables are mutually exclusive.');

            return ExitCode::ValidationError->value;
        }

        /** @var list<string> $skipTables */
        $skipTables = ($skipTablesOpt !== null && $skipTablesOpt !== '') // @pest-mutate-ignore: BooleanAndToBooleanOr,EmptyStringToNotEmpty
            ? array_values(array_filter(array_map(trim(...), explode(',', (string) $skipTablesOpt)), static fn (string $t): bool => $t !== '')) // @pest-mutate-ignore: UnwrapArrayValues
            : [];

        /** @var list<string> $onlyTables */
        $onlyTables = ($onlyTablesOpt !== null && $onlyTablesOpt !== '') // @pest-mutate-ignore: BooleanAndToBooleanOr,EmptyStringToNotEmpty
            ? array_values(array_filter(array_map(trim(...), explode(',', (string) $onlyTablesOpt)), static fn (string $t): bool => $t !== '')) // @pest-mutate-ignore: UnwrapArrayValues
            : [];

        // Table options take table names only. A mistyped name excludes nothing
        // or, for --only-tables, everything; a regex that matches nothing would
        // leave --only-tables empty and so clone every table. So would a value
        // without any name, such as " , ".
        foreach (['skip-tables' => [$skipTablesOpt, $skipTables], 'only-tables' => [$onlyTablesOpt, $onlyTables]] as $option => [$value, $references]) {
            if ($references === [] && $value !== null && $value !== '') {
                $this->error(sprintf('--%s: no table name given', $option));

                return ExitCode::ValidationError->value;
            }

            foreach ($references as $reference) {
                if (TableConfigResolver::isRegex($reference)) {
                    $this->error(sprintf("--%s: '%s' is a regex; the option takes table names only, put regex in the YAML skip: list", $option, $reference));

                    return ExitCode::ValidationError->value;
                }
            }
        }

        $auditChannelOpt = $this->option('audit-channel');
        /** @var list<string> $auditChannels */
        $auditChannels = ($auditChannelOpt !== null && $auditChannelOpt !== '') // @pest-mutate-ignore: BooleanAndToBooleanOr,EmptyStringToNotEmpty
            ? array_values(array_filter(array_map(trim(...), explode(',', (string) $auditChannelOpt)))) // @pest-mutate-ignore: UnwrapArrayValues
            : [];

        // ─── Option overrides ──────────────────────────────────────────────────
        $enforceOverride = $this->option('enforce-column-types') ? true : ($this->option('no-enforce-column-types') ? false : null);
        $dropUnkOverride = $this->option('drop-unknown-tables') ? true : ($this->option('no-drop-unknown-tables') ? false : null);
        $dropExtOverride = $this->option('drop-extra-columns') ? true : ($this->option('no-drop-extra-columns') ? false : null);
        $recreateOverride = $this->option('recreate-tables') ? true : ($this->option('no-recreate-tables') ? false : null);
        $fkChecksOverride = $this->option('no-disable-fk-checks') ? false : ($this->option('disable-fk-checks') ? true : null);
        $remapKeysOverride = ($this->option('no-remap-keys') || $this->option('skip-remapping-keys')) ? false : ($this->option('remap-keys') ? true : null);

        if ($enforceOverride !== null || $dropUnkOverride !== null || $dropExtOverride !== null || $recreateOverride !== null || $fkChecksOverride !== null || $remapKeysOverride !== null) {
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
                    remapKeys: $remapKeysOverride ?? $opts->remapKeys,
                    recreateTables: $recreateOverride ?? $opts->recreateTables,
                ),
                tables: $config->tables,
                keyRemapping: $config->keyRemapping,
                skipTables: $config->skipTables,
            );
        }

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
            $targetName = is_string($chosen) ? $chosen : $otherNames[0]; // @pest-mutate-ignore: IncrementInteger,DecrementInteger
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

        $targetIsDump = $targetConnection->type === DatabaseConnectionType::Dump;

        // A dump target produces a SQL file — validate it instead of opening a PDO.
        if ($targetIsDump) {
            if ($sourceConnection->type === DatabaseConnectionType::Dump) {
                $this->error('Source connection must be a real database, not a dump.');

                return ExitCode::ValidationError->value;
            }

            $dumpDialect = $targetConnection->dialect;

            if (! $dumpDialect instanceof DatabaseConnectionType || ! $dumpDialect->isDialect()) {
                $this->error('Dump connection has no valid dialect. Valid dialects: '.implode(', ', DatabaseConnectionType::dialectValues()).'.');

                return ExitCode::ValidationError->value;
            }

            $cwd = getcwd();

            if ($cwd === false || ! is_writable($cwd)) {
                $this->error('Working directory is not writable: '.($cwd === false ? '(unknown)' : $cwd));

                return ExitCode::IoError->value;
            }
        }

        // Test connections (source always; target only when it is a live database)
        $connectLabel = $targetIsDump
            ? sprintf('Connecting to %s', $config->connectionName)
            : sprintf('Connecting to %s and %s', $config->connectionName, $targetName);

        try {
            $step->run($connectLabel, function () use ($connector, $sourceConnection, $targetConnection, $config, $targetName, $targetIsDump): void {
                try {
                    $sourceConnName = $connector->open($sourceConnection);
                    DB::purge($sourceConnName);
                } catch (Throwable $throwable) {
                    throw new RuntimeException(sprintf("Cannot connect to source '%s': %s", $config->connectionName, $throwable->getMessage()), $throwable->getCode(), $throwable);
                }

                if ($targetIsDump) {
                    return;
                }

                try {
                    $targetConnName = $connector->open($targetConnection);
                    DB::purge($targetConnName);
                } catch (Throwable $throwable) {
                    throw new RuntimeException(sprintf("Cannot connect to target '%s': %s", $targetName, $throwable->getMessage()), $throwable->getCode(), $throwable);
                }
            });
        } catch (RuntimeException $runtimeException) {
            $this->error($runtimeException->getMessage());

            return ExitCode::ConnectionError->value;
        }

        // ─── Phase 3: Dry-run ──────────────────────────────────────────────────
        if ((bool) $this->option('dry-run')) { // @pest-mutate-ignore: RemoveBooleanCast
            return $this->runDryRun($config, $sourceConnection, $targetConnection, $inspector, $tableResolver, $dependencyResolver, $skipTables, $onlyTables, $ci);
        }

        // ─── Phase 4-6: Full Run ───────────────────────────────────────────────
        $startedAt = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        Log::info('run_started', ['source' => $config->connectionName, 'target' => $targetName]);

        try {
            $sourceSchema = $step->run('Resolving table order', fn (): DatabaseSchemaData => $inspector->inspect($sourceConnection));
        } catch (Throwable $throwable) {
            $this->error(sprintf('Failed to inspect source schema: %s', $throwable->getMessage()));

            return ExitCode::ConnectionError->value;
        }

        // Expand regex table keys against the real source schema. From here on
        // $config->tables carry concrete table names, so the orchestrator, key
        // mapping and audit code all keep working against literal names.
        $resolved = $this->resolveTables($tableResolver, $config, $sourceSchema, $skipTables, $onlyTables);

        if ($resolved === null) {
            return ExitCode::ConfigError->value;
        }

        [$config, $skipTables, $onlyTables] = $resolved;

        // ─── Phase 5b: Key Mapping Generation ─────────────────────────────────
        $keyRemappingService = null;
        $keyRemappingConfig = $config->keyRemapping;
        $remappingConfigured = $keyRemappingConfig instanceof KeyRemappingConfigData && $keyRemappingConfig->isActive();
        $skipRemapping = ! $config->options->remapKeys;

        if ($remappingConfigured && $skipRemapping && ! $ci) {
            // Remapping is defined but disabled: say so, because primary/foreign
            // key values will be copied from the source unchanged.
            $this->line(sprintf(
                '  <comment>Key remapping disabled (%s) — primary/foreign key values are copied unchanged.</comment>',
                match (true) {
                    (bool) $this->option('no-remap-keys') => '--no-remap-keys',
                    (bool) $this->option('skip-remapping-keys') => '--skip-remapping-keys',
                    default => 'options.remap_keys: false',
                },
            ));
        }

        if ($keyRemappingConfig instanceof KeyRemappingConfigData && $keyRemappingConfig->isActive() && ! $skipRemapping) {
            if ((bool) $this->option('no-memory-limit')) { // @pest-mutate-ignore: RemoveBooleanCast
                ini_set('memory_limit', '-1');
            }

            $keyRemappingService = (bool) $this->option('file-based') // @pest-mutate-ignore: RemoveBooleanCast
                ? new KeyRemappingService($connector, new EncryptedFileKeyRemappingStore)
                : resolve(KeyRemappingService::class);
            // Schema-only tables copy no rows, so they need no key mappings.
            $sortedForMapping = array_values(array_map(
                static fn (TableCloningConfigData $t): string => $t->tableName,
                array_filter($config->tables, static fn (TableCloningConfigData $t): bool => $t->rows->strategy !== 'schema'),
            ));

            try {
                $counts = $step->run(
                    'Generating key mappings',
                    fn (): array => $keyRemappingService->generateMappings($keyRemappingConfig, $sourceConnection, $sourceSchema, $sortedForMapping),
                );
            } catch (KeyRemappingExhaustedException $exhausted) {
                return $this->handleKeyRemappingExhausted($exhausted, $filePath, $ci);
            } catch (Throwable $throwable) {
                if (str_contains($throwable->getMessage(), 'Allowed memory size') || str_contains($throwable->getMessage(), 'Out of memory')) {
                    $reRunCommand = $this->getOriginalCommandWithNoMemoryLimit();
                    $this->error(sprintf(
                        "Memory exhausted during key mapping generation: %s\n\nHint: Re-run with --no-memory-limit to remove the PHP memory limit:\n\n    %s\n",
                        $throwable->getMessage(),
                        $reRunCommand
                    ));
                } else {
                    throw $throwable;
                }

                return ExitCode::GeneralError->value;
            }

            if ($isVerbose) {
                foreach ($counts as $tbl => $cnt) {
                    $this->line(sprintf('  <info>✓</info>  Key mapping: %s (%s rows)', $tbl, number_format($cnt)));
                }
            }
        }

        $skipSchema = (bool) $this->option('skip-schema');

        if ($isVerbose && ! $ci && ! $targetIsDump) {
            $step->start('Comparing schema');

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

                    $step->success(sprintf('(differs: %s)', implode(', ', $parts)));
                } else {
                    $step->success('(target matches source)');
                }
            } catch (Throwable) {
                $step->fail('(unable to inspect target — non-fatal)');
            }
        }

        /** @var list<string> $notFoundTables */
        $notFoundTables = [];

        /** @var list<string> $schemaFailureTables */
        $schemaFailureTables = [];

        $dotColumn = 0;
        $maxDotColumns = 70;

        $progress = null;
        $restoreLogs = null;

        if ($showProgress) {
            $progress = CloningProgressDisplay::forConsole($out, phaseDetail: $isVeryVerbose);

            // Log records written to stderr would break the bars only when stderr is
            // the same terminal; redirected to a file, it keeps getting them.
            if ($out->getErrorOutput()->isDecorated()) {
                $restoreLogs = $this->redirectLogsTo($progress->log);
            }
        }

        // Live stats of the table currently transferring, kept for its terminal
        // event (the `-vvv` timing summary) and then reset.
        $currentTimings = null;

        $dumpSink = $targetIsDump
            ? new SqlDumpService(new DumpDialectFactory, new DumpArchiver)
            : null;

        try {
            $result = $orchestrator->run(
                config: $config,
                source: $sourceConnection,
                target: $targetConnection,
                sourceSchema: $sourceSchema,
                skipSchema: $skipSchema,
                skipTables: $skipTables,
                onlyTables: $onlyTables,
                onProgress: function (string $tableName, TableRunStatus $status, int $rows, int $skipped, array $skippedRows) use ($step, $isVerbose, $isDebug, $ci, &$notFoundTables, &$schemaFailureTables, &$dotColumn, $maxDotColumns, $progress, &$currentTimings): void {
                    $timings = $currentTimings;
                    $currentTimings = null;

                    // Record pre-skip outcomes for the final summary (every mode).
                    if ($status === TableRunStatus::NotFound) {
                        $notFoundTables[] = $tableName;
                    } elseif ($status === TableRunStatus::SkippedBySchemaFailure) {
                        $schemaFailureTables[] = $tableName;
                    }

                    if ($ci) {
                        return;
                    }

                    // ── Terminal status ──
                    // Quiet mode (not verbose): compact one-char dot indicators.
                    if (! $isVerbose) {
                        $indicator = match ($status) {
                            TableRunStatus::Transferred => $skipped > 0 ? 'F' : '.',
                            TableRunStatus::Failed => 'E',
                            TableRunStatus::NotFound => '?',
                            TableRunStatus::SkippedBySchemaFailure => 'S',
                            default => null,
                        };

                        if ($indicator !== null) {
                            $this->output->write($indicator);
                            $dotColumn++;

                            if ($dotColumn >= $maxDotColumns) {
                                $this->output->writeln('');
                                $dotColumn = 0;
                            }
                        }

                        return;
                    }

                    // Verbose. With live bars, scrolling detail goes to the log section (so it
                    // never corrupts them); otherwise the step renderer finalises the table
                    // line that onTableStart opened.
                    $sink = $progress instanceof CloningProgressDisplay ? $progress->log : $this->output;
                    $rowsSummary = sprintf('(%s rows%s)', number_format($rows), $skipped > 0 ? ', '.$skipped.' skipped' : '');

                    if ($progress instanceof CloningProgressDisplay) {
                        // Only Transferred/Failed tables ever started a per-table bar
                        // (via onTableStart); NotFound/SchemaFailure never did.
                        if ($status === TableRunStatus::Transferred || $status === TableRunStatus::Failed) {
                            $progress->finishTable($status === TableRunStatus::Transferred, $timings);
                        }

                        if ($status === TableRunStatus::Transferred) {
                            $sink->writeln(sprintf('  <info>✓</info>  %s  %s', $tableName, $rowsSummary));
                        } elseif ($status === TableRunStatus::Failed) {
                            $sink->writeln(sprintf('  <error>✗</error>  %s  — transfer failed', $tableName));
                        }
                    } elseif ($status === TableRunStatus::Transferred) {
                        $step->success($rowsSummary);
                    } elseif ($status === TableRunStatus::Failed) {
                        $step->fail();
                    }

                    if ($status === TableRunStatus::NotFound) {
                        $sink->writeln(sprintf('  <comment>?</comment>  %s  — not found in source, skipped', $tableName));
                    } elseif ($status === TableRunStatus::SkippedBySchemaFailure) {
                        $sink->writeln(sprintf('  <error>S</error>  %s  — schema replication failed, skipped', $tableName));
                    }

                    if ($status === TableRunStatus::Transferred || $status === TableRunStatus::Failed) {
                        $this->renderSkipGroups($sink, $skippedRows);

                        // `-vvv`: per-table timing summary table.
                        if ($isDebug && $timings instanceof StatsTableTransferData) {
                            CloningProgressDisplay::renderTimingSummary($sink, $timings);
                        }
                    }

                    $progress?->advanceOverall();
                },
                keyRemapping: $keyRemappingService,
                breakOnFailure: (bool) $this->option('break-on-failure'),
                onTableStart: match (true) {
                    $progress instanceof CloningProgressDisplay => $progress->startTable(...),
                    $isVerbose && ! $ci => fn (string $tableName) => $step->start('  '.$tableName),
                    default => null,
                },
                dumpSink: $dumpSink,
                onStart: $progress instanceof CloningProgressDisplay ? $progress->start(...) : null,
                trackRowTotals: $progress instanceof CloningProgressDisplay,
                onTableProgress: $progress instanceof CloningProgressDisplay || ($isDebug && ! $ci)
                    ? static function (string $tableName, StatsTableTransferData $stats) use (&$currentTimings, $progress): void {
                        $currentTimings = $stats;
                        $progress?->advanceTable($stats);
                    }
                : null,
            );
        } finally {
            // Also on an exception: the bars must not stay open, and logging must
            // go back to stderr before the error is reported.
            $progress?->finish();

            if ($restoreLogs instanceof Closure) {
                $restoreLogs();
            }
        }

        $finishedAt = new DateTimeImmutable('now', new DateTimeZone('UTC'));

        // Finalise the dump: write postamble, compress to ZIP, delete the .sql.
        $dumpResult = null;

        if ($dumpSink instanceof SqlDumpService) {
            try {
                $dumpResult = $dumpSink->finish();
                Log::info('dump_archived', ['file' => $dumpResult->zipFileName, 'bytes' => $dumpResult->zipBytes]);
            } catch (Throwable $throwable) {
                $this->error('Failed to finalise dump archive: '.$throwable->getMessage());

                return ExitCode::GeneralError->value;
            }
        }

        Log::info('run_finished', ['success' => $result->success, 'rows' => $result->totalRows]);

        // ─── Phase 6b: Key Mapping Cleanup ────────────────────────────────────
        if ($keyRemappingService instanceof KeyRemappingService) {
            $keyRemappingService->cleanup();
            Log::info('key_mapping_cleanup_completed', []);
        }

        // ─── Phase 7: Audit ────────────────────────────────────────────────────
        $cloningConfig = $configService->load();
        $rawAuditConfig = $cloningConfig['audit'] ?? null;
        /** @var array<string, mixed>|null $auditConfig */
        $auditConfig = is_array($rawAuditConfig) ? $rawAuditConfig : null;

        if ($auditConfig !== null || $auditChannels !== []) {
            $signer = new AuditLogSigner;
            $builder = new AuditLogBuilder($signer);
            $renderer = new AuditLogRenderer;
            $deliveryService = new AuditDeliveryService(
                localAdapter: new LocalDeliveryAdapter,
                stdoutAdapter: new StdoutDeliveryAdapter,
                stderrAdapter: new StderrDeliveryAdapter,
                s3Adapter: new S3DeliveryAdapter,
                emailAdapter: new EmailDeliveryAdapter,
                teamsAdapter: new WebhookDeliveryAdapter(AuditChannelType::MsTeams),
                slackAdapter: new WebhookDeliveryAdapter(AuditChannelType::Slack),
                ntfyAdapter: new NtfyDeliveryAdapter,
            );

            $yamlFileName = basename($filePath);

            /**
             * @var array{auditArtefacts: array<string, string>, templateVars: array<string, string>, processLogContent: string} $auditPayload
             */
            $auditPayload = $step->run('Generating audit log', function () use ($builder, $renderer, $signer, $config, $result, $targetName, $startedAt, $finishedAt, $yamlFileName, $auditBuffer, $sourceConnection, $targetConnection, $dumpResult): array {
                $auditRecord = $builder->build(
                    config: $config,
                    result: $result,
                    targetConnection: $targetName,
                    startedAt: $startedAt,
                    finishedAt: $finishedAt,
                    yamlFileName: $yamlFileName,
                    sourceConnectionData: $sourceConnection,
                    targetConnectionData: $targetConnection,
                    dumpDialect: $targetConnection->dialect?->value,
                    dumpResult: $dumpResult,
                );

                [$canonicalJson, , $hmacSignature] = $signer->sign($auditRecord);
                $htmlContent = $renderer->render($auditRecord, $canonicalJson);
                $pdfContent = $renderer->renderPdf($auditRecord, $canonicalJson);

                $timestamp = $startedAt->format('Y-m-d\TH-i-s\Z');
                $baseFilename = sprintf('%s_%s_%s', $config->connectionName, $targetName, $timestamp);
                $auditArtefacts = [
                    $baseFilename.'_audit.html' => $htmlContent,
                    $baseFilename.'_audit.pdf' => $pdfContent,
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

                $processLogContent = $auditBuffer->flush();

                return [
                    'auditArtefacts' => $auditArtefacts,
                    'templateVars' => $templateVars,
                    'processLogContent' => $processLogContent,
                ];
            });

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

            $deliveryLabel = $channelTypes !== []
                ? sprintf('Delivering via %s', implode(', ', $channelTypes))
                : 'Delivering audit log';

            $step->run($deliveryLabel, function () use ($deliveryService, $auditConfig, $auditChannels, $auditPayload): void {
                $deliveryService->deliver(
                    auditConfig: $auditConfig,
                    auditArtefacts: $auditPayload['auditArtefacts'],
                    processLogContent: $auditPayload['processLogContent'],
                    channelOverride: $auditChannels,
                    templateVars: $auditPayload['templateVars'],
                );
            });
        }

        // ─── Phase 8: Summary ──────────────────────────────────────────────────
        if (! $isVerbose && ! $ci && $dotColumn > 0) { // @pest-mutate-ignore: BooleanAndToBooleanOr
            $this->line('');
        }

        // Derive not-found from result (merging with any collected during progress)
        $notFoundFromResult = array_values(array_map( // @pest-mutate-ignore: UnwrapArrayValues
            static fn (TableRunResultData $t): string => $t->tableName,
            array_filter($result->tables, static fn (TableRunResultData $t): bool => $t->status === TableRunStatus::NotFound)
        ));
        $notFoundTables = array_values(array_unique(array_merge($notFoundTables, $notFoundFromResult))); // @pest-mutate-ignore: UnwrapArrayValues

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
        $schemaFailuresFromResult = array_values(array_map( // @pest-mutate-ignore: UnwrapArrayValues
            static fn (TableRunResultData $t): string => $t->tableName,
            array_filter($result->tables, static fn (TableRunResultData $t): bool => $t->status === TableRunStatus::SkippedBySchemaFailure)
        ));
        $schemaFailureTables = array_values(array_unique(array_merge($schemaFailureTables, $schemaFailuresFromResult))); // @pest-mutate-ignore: UnwrapArrayValues

        if ($schemaFailureTables !== []) {
            $this->line('');
            $this->line(sprintf(
                '  <error>Error: %d table%s skipped due to schema replication failure (%s)</error>',
                count($schemaFailureTables),
                count($schemaFailureTables) === 1 ? '' : 's',
                implode(', ', $schemaFailureTables),
            ));
        }

        foreach ($result->tables as $tableResult) {
            foreach ($tableResult->notes as $note) {
                $this->line(sprintf('  <comment>Note: %s: %s</comment>', $tableResult->tableName, $note));
            }
        }

        $transferredCount = count(array_filter($result->tables, static fn (TableRunResultData $t): bool => $t->status === TableRunStatus::Transferred));
        $totalCount = count($result->tables);
        $duration = $this->formatDuration($result->durationSeconds);

        if (! $ci) {
            $this->line('');
            $this->line(sprintf('  Tables: %d/%d  Rows: %d  Duration: %s', $transferredCount, $totalCount, $result->totalRows, $duration));
            $this->line('');
        }

        if ($dumpResult instanceof DumpResultData) {
            $this->line(sprintf(
                '  Dump: %s  (%s%s)',
                $dumpResult->zipFileName,
                $this->formatBytes($dumpResult->zipBytes),
                $dumpResult->encrypted ? ', AES-256' : '',
            ));

            if (! $ci) {
                $this->line('');
            }
        }

        if (! $result->success && ! (bool) $this->option('allow-failure')) { // @pest-mutate-ignore: RemoveBooleanCast
            return ExitCode::GeneralError->value;
        }

        return ExitCode::Success->value;
    }

    /**
     * @param  list<string>  $skipTables
     * @param  list<string>  $onlyTables
     */
    private function runDryRun(
        CloningConfigData $config,
        ConnectionData $source,
        ConnectionData $target,
        SchemaInspector $inspector,
        TableConfigResolver $tableResolver,
        DependencyResolver $dependencyResolver,
        array $skipTables,
        array $onlyTables,
        bool $ci,
    ): int {
        try {
            $schema = $inspector->inspect($source);
        } catch (Throwable $throwable) {
            $this->error(sprintf('Failed to inspect source schema: %s', $throwable->getMessage()));

            return ExitCode::ConnectionError->value;
        }

        // Expand regex table keys like the full run does, so the estimate covers
        // the tables they match instead of counting the patterns as not found.
        $resolved = $this->resolveTables($tableResolver, $config, $schema, $skipTables, $onlyTables);

        if ($resolved === null) {
            return ExitCode::ConfigError->value;
        }

        [$config, $skipTables, $onlyTables] = $resolved;

        // Leave out the tables the run would skip, by flag or by cascade, the
        // way the orchestrator does; they need no row count either.
        $tableNames = array_map(static fn (TableCloningConfigData $t): string => $t->tableName, $config->tables);
        [$byFlag, $byCascade] = $dependencyResolver->exclusions($schema, $tableNames, $skipTables, $onlyTables);
        $excluded = [...$byFlag, ...$byCascade];

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

            if ($exists && in_array($tableName, $excluded, true)) {
                $dryRunTables[] = new DryRunTableData(
                    tableName: $tableName,
                    exists: true,
                    estimatedRows: 0,
                    rowStrategy: 'skip',
                    rowLimit: null,
                    transformedColumns: [],
                );

                continue;
            }

            if ($exists) {
                try {
                    $estimatedRows = $this->countRows($source, $tableName);
                } catch (Throwable) {
                    // Row count unavailable: leave the estimate empty.
                }

                if ($estimatedRows !== null) {
                    $strategy = $tableConfig->rows->strategy;
                    $limit = $tableConfig->rows->limit;

                    if (($strategy === 'first' || $strategy === 'last') && $limit !== null) {
                        $estimatedRows = min($estimatedRows, $limit);
                    } elseif ($strategy === 'schema') {
                        $estimatedRows = 0;
                    }

                    $totalEstimated += $estimatedRows;
                }
            } else {
                $notFoundCount++;
            }

            $transformedColumns = array_values(array_map( // @pest-mutate-ignore: UnwrapArrayValues
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

    /**
     * Resolve the table references of the config against the source schema,
     * and the names in --skip-tables and --only-tables like literal table keys
     * (a name that matches nothing stays as written). The config's skip list
     * (regex entries and rows.strategy: skip, now concrete names) is merged
     * into --skip-tables, deduplicated. A reference that names no source table
     * is always reported, --ci included, as it may leave tables unskipped.
     * Returns null, after reporting it, when a table pattern fails while
     * matching.
     *
     * @param  list<string>  $skipTables
     * @param  list<string>  $onlyTables
     * @return array{CloningConfigData, list<string>, list<string>}|null
     */
    private function resolveTables(TableConfigResolver $resolver, CloningConfigData $config, DatabaseSchemaData $schema, array $skipTables, array $onlyTables): ?array
    {
        $warn = function (string $reference, string $origin): void {
            $this->line(sprintf("  <comment>Warning: %s '%s' matches no source table</comment>", $origin, $reference));
        };

        try {
            $config = $resolver->resolve($config, $schema, $warn);
            $skipTables = $resolver->resolveReferences($skipTables, $schema, '--skip-tables', $warn);
            $onlyTables = $resolver->resolveReferences($onlyTables, $schema, '--only-tables', $warn);
        } catch (RuntimeException $runtimeException) {
            $this->error($runtimeException->getMessage());

            return null;
        }

        return [$config, array_values(array_unique(array_merge($skipTables, $config->skipTables))), $onlyTables]; // @pest-mutate-ignore: UnwrapArrayValues
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
                ? (in_array($table->rowStrategy, ['first', 'last'], true) ? $table->rowStrategy.' '.number_format((int) $table->rowLimit) : $table->rowStrategy) // @pest-mutate-ignore: RemoveIntegerCast
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
            '  %-'.$tableWidth.'s %-'.$rowsWidth.'s', // @pest-mutate-ignore: ConcatRemoveRight
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

        $minutes = (int) ($seconds / 60); // @pest-mutate-ignore: RemoveIntegerCast
        $remaining = $seconds % 60;

        return sprintf('%dm %ds', $minutes, $remaining);
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes.' B';
        }

        $units = ['KB', 'MB', 'GB'];
        $value = $bytes / 1024;
        $unit = 0;

        while ($value >= 1024 && $unit < count($units) - 1) {
            $value /= 1024;
            $unit++;
        }

        return sprintf('%.1f %s', $value, $units[$unit]);
    }

    /**
     * Aggregate skipped rows by SQL error message, sorted by descending count.
     *
     * @param  list<SkippedRow>  $rows
     * @return list<array{count: int, message: string}>
     */
    private function aggregateSkipReasons(array $rows): array
    {
        $byMessage = [];
        foreach ($rows as $row) {
            $byMessage[$row->sqlError] = ($byMessage[$row->sqlError] ?? 0) + 1;
        }

        arsort($byMessage);

        $result = [];
        foreach ($byMessage as $message => $count) {
            $result[] = ['count' => $count, 'message' => (string) $message]; // @pest-mutate-ignore: RemoveStringCast
        }

        return $result;
    }

    /**
     * While live bars own the terminal, a raw stderr write would corrupt them:
     * silence the stderr log handlers and show the same records in `$section`
     * instead, at the level stderr had. Returns the callback that restores both.
     *
     * Skipped rows are left out of the section: the bars mode already lists them
     * grouped by reason under each table, and one line per row could pile up
     * hundreds of thousands of lines in the section's memory.
     *
     * A logging setup without a plain stderr handler of its own (e.g. one that
     * wraps its handlers) is left alone rather than shown twice.
     *
     * @return Closure(): void
     */
    private function redirectLogsTo(OutputInterface $section): Closure
    {
        $logger = Log::driver();
        $monolog = $logger instanceof Logger ? $logger->getLogger() : $logger;

        if (! $monolog instanceof Monolog) {
            return static function (): void {};
        }

        /** @var list<array{StreamHandler, Level}> $silenced */
        $silenced = [];

        foreach ($monolog->getHandlers() as $handler) {
            if ($handler instanceof StreamHandler && $handler->getUrl() === 'php://stderr') {
                $silenced[] = [$handler, $handler->getLevel()];
            }
        }

        if ($silenced === []) {
            return static function (): void {};
        }

        $monolog->pushHandler(new ConsoleOutputHandler($section, $silenced[0][1], ignoredMessages: ['row_skipped']));

        foreach ($silenced as [$handler]) {
            $handler->setLevel(Level::Emergency);
        }

        return static function () use ($monolog, $silenced): void {
            $monolog->popHandler();

            foreach ($silenced as [$handler, $level]) {
                $handler->setLevel($level);
            }
        };
    }

    /**
     * @param  list<SkippedRow>  $skippedRows
     */
    private function renderSkipGroups(OutputInterface $sink, array $skippedRows): void
    {
        if ($skippedRows === []) {
            return;
        }

        $groups = $this->aggregateSkipReasons($skippedRows);
        $shown = array_slice($groups, 0, 10);
        foreach ($shown as $group) {
            $sink->writeln(sprintf('     └ %d× %s', $group['count'], $group['message']));
        }

        $rest = count($groups) - count($shown);
        if ($rest > 0) {
            $sink->writeln(sprintf('     └ … and %d more error types', $rest));
        }
    }

    /**
     * Reconstruct the original command line, adding --no-memory-limit if not already present.
     */
    private function getOriginalCommandWithNoMemoryLimit(): string
    {
        return $this->getOriginalCommand(['--no-memory-limit']);
    }

    /**
     * Reconstruct the original command line, optionally appending extra flags.
     *
     * @param  list<string>  $extraFlags  Additional flags to append (e.g. ['--no-memory-limit'])
     */
    private function getOriginalCommand(array $extraFlags = []): string
    {
        $parts = ['clonio', 'cloning:run', (string) $this->argument('file')]; // @pest-mutate-ignore: RemoveStringCast

        // Add all options that affect the command behavior
        $options = [
            'target' => '--target',
            'allow-failure' => '--allow-failure',
            'dry-run' => '--dry-run', // @pest-mutate-ignore: RemoveArrayItem
            'ci' => '--ci',
            'skip-schema' => '--skip-schema',
            'skip-tables' => '--skip-tables',
            'only-tables' => '--only-tables',
            'audit-channel' => '--audit-channel',
            'remap-keys' => '--remap-keys',
            'no-remap-keys' => '--no-remap-keys', // @pest-mutate-ignore: RemoveArrayItem
            'skip-remapping-keys' => '--skip-remapping-keys', // @pest-mutate-ignore: RemoveArrayItem
            'file-based' => '--file-based',
            'enforce-column-types' => '--enforce-column-types',
            'no-enforce-column-types' => '--no-enforce-column-types',
            'drop-unknown-tables' => '--drop-unknown-tables',
            'no-drop-unknown-tables' => '--no-drop-unknown-tables',
            'drop-extra-columns' => '--drop-extra-columns',
            'no-drop-extra-columns' => '--no-drop-extra-columns',
            'recreate-tables' => '--recreate-tables',
            'no-recreate-tables' => '--no-recreate-tables',
            'disable-fk-checks' => '--disable-fk-checks',
            'no-disable-fk-checks' => '--no-disable-fk-checks',
            'break-on-failure' => '--break-on-failure',
        ];

        foreach ($options as $option => $flag) {
            $value = $this->option($option);

            if (in_array($option, ['skip-tables', 'only-tables', 'audit-channel', 'target'], true)) {
                // Options with values
                if ($value !== null && $value !== '') {
                    $parts[] = $flag.'='.$value;
                }
            } elseif ((bool) $value) { // @pest-mutate-ignore: RemoveBooleanCast
                // Boolean flags
                $parts[] = $flag;
            }
        }

        foreach ($extraFlags as $extraFlag) {
            $parts[] = $extraFlag;
        }

        return implode(' ', $parts);
    }

    /**
     * Handle KeyRemappingExhaustedException with a friendly recovery flow.
     *
     * CI mode: print prose with hint, exit GeneralError.
     * Interactive: prompt to switch the offending column to `keep` strategy via cloning:column:edit.
     *   On accept: invoke editor in-process, exit Success with re-run hint.
     *   On decline: exit Success without changes.
     */
    private function handleKeyRemappingExhausted(KeyRemappingExhaustedException $exhausted, string $filePath, bool $ci): int
    {
        $columnTypeLabel = sprintf('%s (%s, ceiling %d)', strtoupper($exhausted->columnType), $exhausted->unsigned ? 'unsigned' : 'signed', $exhausted->typeMax);
        $slotSummary = sprintf('%d (%d above MAX(id), %d gaps below)', $exhausted->upperSlots + $exhausted->gapSlots, $exhausted->upperSlots, $exhausted->gapSlots);

        $editCommand = sprintf(
            'clonio cloning:column:edit %s --table=%s --column=%s --strategy=keep',
            $filePath,
            $exhausted->table,
            $exhausted->column,
        );

        if ($ci) {
            $this->line(sprintf('Cannot remap %s.%s: key remapping exhausted.', $exhausted->table, $exhausted->column));
            $this->line(sprintf('  Column type: %s', $columnTypeLabel));
            $this->line(sprintf('  Rows requested: %d', $exhausted->rowCount));
            $this->line(sprintf('  Slots available: %s', $slotSummary));
            $this->line('');
            $this->line("Hint: switch the column's strategy to 'keep' to skip remapping for this table, or widen the column type.");
            $this->line('');
            $this->line('    '.$editCommand);
            $this->line('');

            return ExitCode::GeneralError->value;
        }

        $this->line('');
        $this->line(sprintf('  <error>Cannot remap %s.%s — key remapping exhausted.</error>', $exhausted->table, $exhausted->column));
        $this->line(sprintf('  Column type: %s', $columnTypeLabel));
        $this->line(sprintf('  Rows requested: %d', $exhausted->rowCount));
        $this->line(sprintf('  Slots available: %s', $slotSummary));
        $this->line('');
        $this->line("  Switching the column to the 'keep' strategy will skip remapping for this");
        $this->line('  table and let the run continue with the original primary-key values.');
        $this->line('');

        $proceed = $this->confirm(sprintf(
            "Switch %s.%s strategy to 'keep' in %s?",
            $exhausted->table,
            $exhausted->column,
            $filePath,
        ), false);

        if (! $proceed) {
            $this->line('Cancelled. Re-run after editing the configuration.');

            return ExitCode::Success->value;
        }

        $editResult = Artisan::call('cloning:column:edit', [
            'file' => $filePath,
            '--table' => $exhausted->table,
            '--column' => $exhausted->column,
            '--strategy' => 'keep',
            '--no-interaction' => true, // @pest-mutate-ignore: TrueToFalse,RemoveArrayItem
        ]);

        if ($editResult !== ExitCode::Success->value) {
            $this->error(sprintf(
                "Failed to update column strategy via cloning:column:edit (exit code %d). Run the editor manually:\n\n    %s\n",
                $editResult,
                $editCommand,
            ));

            return ExitCode::GeneralError->value;
        }

        $this->line('');
        $this->info(sprintf('Updated %s.%s → strategy: keep in %s.', $exhausted->table, $exhausted->column, $filePath));
        $this->line(sprintf("\nConfiguration patched. Re-run the cloning command:\n\n    %s\n", $this->getOriginalCommand()));

        return ExitCode::Success->value;
    }
}
