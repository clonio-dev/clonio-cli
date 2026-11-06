<?php

declare(strict_types=1);

namespace App\Commands\Cloning;

use App\Data\Cloning\CloningConfigData;
use App\Data\Cloning\CloningRunSettingsData;
use App\Data\Cloning\ColumnCloningConfigData;
use App\Data\Cloning\DryRunResultData;
use App\Data\Cloning\DryRunTableData;
use App\Data\Cloning\KeyRemappingConfigData;
use App\Data\Cloning\RunOptionsData;
use App\Data\Cloning\TableCloningConfigData;
use App\Data\ConnectionData;
use App\Data\Schema\DatabaseSchemaData;
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
use App\Services\Cloning\EncryptedFileKeyRemappingStore;
use App\Services\Cloning\KeyRemappingPreflight;
use App\Services\Cloning\KeyRemappingService;
use App\Services\Cloning\NullCloningRunObserver;
use App\Services\Cloning\SourceRowEstimator;
use App\Services\Cloning\TableNameResolver;
use App\Services\Config\ConfigService;
use App\Services\Database\DatabaseConnectionService;
use App\Services\Output\CloningProgressDisplay;
use App\Services\Output\Run\DotRunObserver;
use App\Services\Output\Run\DryRunRenderer;
use App\Services\Output\Run\ProgressRunObserver;
use App\Services\Output\Run\RunSummaryRenderer;
use App\Services\Output\Run\StepRunObserver;
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
use InvalidArgumentException;
use LaravelZero\Framework\Commands\Command;
use Monolog\Handler\HandlerInterface;
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
    ): int {
        try {
            /** @var array<string, mixed> $rawOptions */
            $rawOptions = $this->options();
            $options = RunOptionsData::fromOptions((string) $this->argument('file'), $rawOptions);
        } catch (InvalidArgumentException $invalidArgumentException) {
            $this->error($invalidArgumentException->getMessage());

            return ExitCode::ValidationError->value;
        }

        $ci = $options->ci;
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
        $filePath = $options->yamlPath;

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

        $config = $options->applyTo($config);
        $skipTables = $options->skipTables;
        $onlyTables = $options->onlyTables;
        $auditChannels = $options->auditChannels;

        // ─── Phase 2: Connection Checks ────────────────────────────────────────

        // Resolve source connection from config
        $sourceConnection = $configService->getConnection($config->connectionName);

        if (! $sourceConnection instanceof ConnectionData) {
            $this->error(sprintf("Source connection '%s' not found in clonio.json.", $config->connectionName));

            return ExitCode::ConnectionError->value;
        }

        // Resolve target connection
        $targetName = $options->targetConnectionName;

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
        if ($options->dryRun) {
            return $this->runDryRun($config, $sourceConnection, $targetConnection, $inspector, $ci);
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

        // Table names in the YAML and the flags resolve to source tables like
        // the database does: exact match first, then a unique case-insensitive one.
        $tableNameResolver = new TableNameResolver($sourceSchema);

        try {
            $config = $this->resolveTableNames($tableNameResolver, $config);
            $cliSkipTables = $tableNameResolver->resolveAll($skipTables);
            $onlyTables = $tableNameResolver->resolveAll($onlyTables);
        } catch (InvalidArgumentException $invalidArgumentException) {
            $this->error($invalidArgumentException->getMessage());

            return ExitCode::ValidationError->value;
        }

        // YAML skip entries add to --skip-tables.
        $skipTables = array_values(array_unique(array_merge($cliSkipTables, $config->skipTables))); // @pest-mutate-ignore: UnwrapArrayValues

        // A filter name that matches no configured table does nothing: say so.
        $configuredTables = array_map(static fn (TableCloningConfigData $t): string => $t->tableName, $config->tables);
        $unmatchedOnly = $this->warnUnmatchedFilter('only-tables', $onlyTables, $configuredTables);
        $this->warnUnmatchedFilter('skip-tables', $cliSkipTables, $configuredTables);

        if ($onlyTables !== [] && count($unmatchedOnly) === count($onlyTables)) {
            $this->error('--only-tables matches no table in the config; nothing would be cloned.');

            return ExitCode::ValidationError->value;
        }

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
                $options->remapKeysDisabledBy ?? 'options.remap_keys: false',
            ));
        }

        if ($keyRemappingConfig instanceof KeyRemappingConfigData && $keyRemappingConfig->isActive() && ! $skipRemapping) {
            if ($this->reportRemappingProblems($config, $sourceSchema)) {
                return ExitCode::ValidationError->value;
            }

            if ($options->noMemoryLimit) {
                ini_set('memory_limit', '-1');
            }

            $keyRemappingService = $options->fileBased
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

        $skipSchema = $options->skipSchema;

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

        $observer = match (true) {
            $progress instanceof CloningProgressDisplay => new ProgressRunObserver($progress, $isDebug),
            $ci => new NullCloningRunObserver,
            $isVerbose => new StepRunObserver($step, $this->output, $isDebug),
            default => new DotRunObserver($this->output),
        };

        $dumpSink = $targetIsDump
            ? new SqlDumpService(new DumpDialectFactory, new DumpArchiver)
            : null;

        try {
            $result = $orchestrator->run(
                config: $config,
                source: $sourceConnection,
                target: $targetConnection,
                sourceSchema: $sourceSchema,
                observer: $observer,
                settings: new CloningRunSettingsData(
                    skipSchema: $skipSchema,
                    skipTables: $skipTables,
                    onlyTables: $onlyTables,
                    keyRemapping: $keyRemappingService,
                    breakOnFailure: $options->breakOnFailure,
                    dumpSink: $dumpSink,
                    trackRowTotals: $progress instanceof CloningProgressDisplay,
                ),
            );
        } finally {
            // Also on an exception: logging must go back to stderr before the
            // error is reported. The orchestrator has closed the bars by now.
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
        new RunSummaryRenderer($this->output)->render($result, $dumpResult, $ci);

        if (! $result->success && ! $options->allowFailure) {
            return ExitCode::GeneralError->value;
        }

        return ExitCode::Success->value;
    }

    /**
     * Warns once per filter name that matches no `tables:` entry and logs it
     * for the audit trail.
     *
     * @param  list<string>  $names
     * @param  list<string>  $configuredTables
     * @return list<string> The unmatched names
     */
    private function warnUnmatchedFilter(string $flag, array $names, array $configuredTables): array
    {
        $unmatched = array_values(array_diff($names, $configuredTables)); // @pest-mutate-ignore: UnwrapArrayValues

        foreach ($unmatched as $name) {
            $this->line(sprintf('  <comment>Warning: --%s name "%s" matches no table in the config</comment>', $flag, $name));
            Log::warning('table_filter_unmatched', ['flag' => '--'.$flag, 'table' => $name]);
        }

        return $unmatched;
    }

    /**
     * Resolves the config's table names and warns about every pattern that
     * matches no table.
     *
     * @throws InvalidArgumentException
     */
    private function resolveTableNames(TableNameResolver $resolver, CloningConfigData $config): CloningConfigData
    {
        $resolved = $resolver->resolveConfig($config);

        foreach ($resolver->unmatchedPatterns($config, $resolved) as $pattern) {
            $this->line(sprintf('  <comment>Warning: table pattern "%s" matches no table</comment>', $pattern));
            Log::warning('table_pattern_unmatched', ['pattern' => $pattern]);
        }

        return $resolved;
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

        try {
            $config = $this->resolveTableNames(new TableNameResolver($schema), $config);
        } catch (InvalidArgumentException $invalidArgumentException) {
            $this->error($invalidArgumentException->getMessage());

            return ExitCode::ValidationError->value;
        }

        if ($config->options->remapKeys && $this->reportRemappingProblems($config, $schema)) {
            return ExitCode::ValidationError->value;
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
        $connector = resolve(DatabaseConnectionService::class);
        $estimator = resolve(SourceRowEstimator::class);
        $connName = null;

        try {
            foreach ($config->tables as $tableConfig) {
                $tableName = $tableConfig->tableName;
                $exists = $schema->hasTable($tableName);
                $estimatedRows = null;
                $estimated = false;

                if (! $exists) {
                    $notFoundCount++;
                } elseif ($tableConfig->rows->strategy === 'schema') {
                    $estimatedRows = 0;
                } else {
                    try {
                        $connName ??= $connector->open($source);
                        [$estimatedRows, $estimated] = $estimator->estimate($connName, $tableConfig, $source->type);
                    } catch (Throwable) {
                        // Source unreachable: leave the estimate empty.
                    }
                }

                $totalEstimated += $estimatedRows ?? 0;

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
                    estimated: $estimatedRows !== null && $estimated,
                );
            }
        } finally {
            if ($connName !== null) {
                DB::purge($connName);
            }
        }

        $dryRunResult = new DryRunResultData(
            tables: $dryRunTables,
            totalEstimatedRows: $totalEstimated,
            notFoundCount: $notFoundCount,
        );

        if (! $ci) {
            new DryRunRenderer($this->output)->render($dryRunResult, $config->connectionName, $schemaDiff);
        }

        return ExitCode::Success->value;
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

        $pushed = new ConsoleOutputHandler($section, $silenced[0][1], ignoredMessages: ['row_skipped']);
        $monolog->pushHandler($pushed);

        foreach ($silenced as [$handler]) {
            $handler->setLevel(Level::Emergency);
        }

        return static function () use ($monolog, $pushed, $silenced): void {
            // Remove exactly the handler pushed above, even when another one was
            // pushed after it. setHandlers() wants a list; keys do not matter.
            $monolog->setHandlers(array_values(array_filter( // @pest-mutate-ignore: UnwrapArrayValues
                $monolog->getHandlers(),
                static fn (HandlerInterface $handler): bool => $handler !== $pushed,
            )));

            foreach ($silenced as [$handler, $level]) {
                $handler->setLevel($level);
            }
        };
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
     * Prints every key remapping entry that cannot work for the source schema.
     *
     * @return bool true when there is at least one problem
     */
    private function reportRemappingProblems(CloningConfigData $config, DatabaseSchemaData $sourceSchema): bool
    {
        $problems = new KeyRemappingPreflight()->problems($config, $sourceSchema);

        foreach ($problems as $problem) {
            $this->error($problem);
        }

        return $problems !== [];
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
