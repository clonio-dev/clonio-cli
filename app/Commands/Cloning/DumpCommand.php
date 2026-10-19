<?php

declare(strict_types=1);

namespace App\Commands\Cloning;

use App\Data\Cloning\ColumnDumpData;
use App\Data\Cloning\DumpResultData;
use App\Data\Cloning\KeyRemappingConfigData;
use App\Data\Cloning\KeyRemappingForeignKeyData;
use App\Data\Cloning\KeyRemappingTableData;
use App\Data\Cloning\TableDumpData;
use App\Data\ConnectionData;
use App\Data\Pii\PiiMatcherData;
use App\Data\Schema\ColumnSchemaData;
use App\Data\Schema\DatabaseSchemaData;
use App\Enums\ExitCode;
use App\Enums\KeyRemappingStrategy;
use App\Services\Cloning\CloningYamlWriter;
use App\Services\Cloning\FrameworkTableCatalog;
use App\Services\Config\ConfigService;
use App\Services\Pii\PiiMatcherLoader;
use App\Services\Schema\SchemaInspector;
use Illuminate\Support\Facades\Storage;
use LaravelZero\Framework\Commands\Command;
use Throwable;

class DumpCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'cloning:dump
        {--connection=           : Name of the saved connection to inspect}
        {--output=               : Output file path (default: <connection-name>.cloning.yaml)}
        {--force                 : Overwrite an existing file without asking}
        {--only-pii              : Omit tables/columns with no PII match}
        {--all-columns           : Include all columns in the YAML, not just PII-detected ones}
        {--locale=               : FakerPHP locale for options.faker_locale (default: en_US)}
        {--enforce-column-types  : Set enforce_column_types: true in the generated YAML}
        {--drop-unknown-tables   : Set drop_unknown_tables: true in the generated YAML}
        {--drop-extra-columns    : Set drop_extra_columns: true in the generated YAML}
        {--recreate-tables       : Set recreate_tables: true in the generated YAML}
        {--no-disable-fk-checks  : Set disable_foreign_key_checks: false in the generated YAML}
        {--no-remap-keys         : Set remap_keys: false in the generated YAML}
        {--skip-remapping-keys   : Alias of --no-remap-keys}
        {--no-framework-tables   : Keep known framework tables (cache, sessions, jobs, ...) on the full row strategy}
        {--ci                    : CI mode — suppress non-error output}';

    /**
     * @var string
     */
    protected $description = 'Inspect a database and generate a cloning configuration YAML file';

    public function handle(
        ConfigService $config,
        SchemaInspector $schemaInspector,
        PiiMatcherLoader $piiMatcherLoader,
        CloningYamlWriter $yamlWriter,
        FrameworkTableCatalog $frameworkTables,
    ): int {
        // 1. Check config exists
        if (! $config->exists()) {
            $this->error('No clonio.json found in the current directory. Run `clonio init` first.');

            return ExitCode::ConfigError->value;
        }

        $ci = (bool) $this->option('ci');
        $onlyPii = (bool) $this->option('only-pii');
        $allColumns = (bool) $this->option('all-columns');

        if ($allColumns && $onlyPii) {
            $this->error('--all-columns and --only-pii cannot be used together.');

            return ExitCode::ValidationError->value;
        }

        // 2. Resolve connection
        $connectionOption = $this->option('connection');
        $connectionName = is_string($connectionOption) && $connectionOption !== '' ? $connectionOption : null;

        if ($connectionName === null) {
            if ($ci) {
                $this->error('--connection is required in --ci mode.');

                return ExitCode::ValidationError->value;
            }

            // Interactive: show choice list
            $connections = $config->getConnections();

            if ($connections === []) {
                $this->error('No connections defined. Run `clonio connection:add` first.');

                return ExitCode::ConfigError->value;
            }

            $names = array_keys($connections);
            $chosen = $this->choice('Select a connection to inspect', $names, 0);
            $connectionName = is_string($chosen) ? $chosen : $names[0];
        }

        $connection = $config->getConnection($connectionName);

        if (! $connection instanceof ConnectionData) {
            $this->error(sprintf("No connection named '%s' found.", $connectionName));

            return ExitCode::ConnectionError->value;
        }

        // 3. Resolve output path
        $outputOption = $this->option('output');
        $outputPath = is_string($outputOption) && $outputOption !== '' ? $outputOption : $connectionName.'.cloning.yaml';

        // 4. Check if file exists
        if (Storage::disk('local')->exists($outputPath) && ! $this->option('force')) {
            $confirmed = $this->confirm(sprintf('File %s already exists. Overwrite? [y/N]', $outputPath), false);

            if (! $confirmed) {
                return ExitCode::Success->value;
            }
        }

        // 5. Show progress
        $host = $connection->host ?? 'local';
        $port = $connection->port !== null ? ':'.$connection->port : '';
        $driver = $connection->type->value;

        if (! $ci) {
            $this->line('');
            $this->line(sprintf('  Inspecting "%s" (%s @ %s%s) ...', $connectionName, $driver, $host, $port));
        }

        // 6. Inspect schema
        try {
            $schema = $schemaInspector->inspect($connection);
        } catch (Throwable $throwable) {
            $this->error(sprintf('Failed to inspect database: %s', $throwable->getMessage()));

            return ExitCode::ConnectionError->value;
        }

        $totalColumns = 0;

        foreach ($schema->tables as $table) {
            $totalColumns += count($table->columns);
        }

        if (! $ci) {
            $this->line('');
            $this->line(sprintf('  Schema fetched: %d tables, %d columns', count($schema->tables), $totalColumns));
            $this->line('');
        }

        // 7. Load PII matcher set
        $piiMatcherSet = $piiMatcherLoader->load();

        // 8. Build table dump data
        $localeOption = $this->option('locale');
        $fakerLocale = is_string($localeOption) && $localeOption !== '' ? $localeOption : 'en_US';

        $tableDumps = [];
        $piiColumnsDetected = 0;

        foreach ($schema->tables as $table) {
            $columnDumps = [];

            foreach ($table->columns as $column) {
                $matcher = $piiMatcherSet->match($column->name);

                if ($matcher instanceof PiiMatcherData) {
                    $piiColumnsDetected++;
                    $transformation = $matcher->transformation;

                    $columnDump = new ColumnDumpData(
                        name: $column->name,
                        strategy: $transformation->strategy,
                        fakerMethod: $transformation->fakerMethod,
                        fakerArguments: $transformation->fakerArguments,
                        maskChar: $transformation->maskChar,
                        visibleChars: $transformation->visibleChars,
                        preserveFormat: $transformation->preserveFormat,
                        hashAlgorithm: $transformation->hashAlgorithm,
                        hashSalt: $transformation->hashSalt,
                        staticValue: $transformation->staticValue,
                        piiDetected: true,
                        piiCategory: $matcher->name,
                        template: $transformation->template,
                    );
                } else {
                    $columnDump = new ColumnDumpData(
                        name: $column->name,
                        strategy: 'keep',
                        fakerMethod: null,
                        fakerArguments: [],
                        maskChar: null,
                        visibleChars: null,
                        preserveFormat: null,
                        hashAlgorithm: null,
                        hashSalt: null,
                        staticValue: null,
                        piiDetected: false,
                        piiCategory: null,
                    );
                }

                $columnDumps[] = $columnDump;
            }

            $tableDump = new TableDumpData(
                name: $table->name,
                columns: $columnDumps,
                rowStrategy: 'full',
                rowLimit: null,
                sortBy: null,
            );

            $tableDumps[] = $tableDump;
        }

        // 8b. Suggest the 'schema' row strategy for known framework tables
        if (! $this->option('no-framework-tables')) {
            $tableDumps = $this->applyFrameworkTables($tableDumps, $frameworkTables, $ci);
        }

        // 9. Apply --only-pii filter
        if ($onlyPii) {
            $tableDumps = array_values(array_filter(
                $tableDumps,
                static fn (TableDumpData $t): bool => $t->rowStrategy === 'schema'
                    || array_any($t->columns, fn (ColumnDumpData $col): bool => $col->strategy !== 'keep')
            ));

            // Also filter columns in remaining tables
            $tableDumps = array_map(
                static function (TableDumpData $t): TableDumpData {
                    $filtered = array_values(array_filter(
                        $t->columns,
                        static fn (ColumnDumpData $col): bool => $col->strategy !== 'keep'
                    ));

                    return new TableDumpData(
                        name: $t->name,
                        columns: $filtered,
                        rowStrategy: $t->rowStrategy,
                        rowLimit: $t->rowLimit,
                        sortBy: $t->sortBy,
                    );
                },
                $tableDumps
            );
        }

        // 10. Collect transfer options
        $enforceColumnTypes = (bool) $this->option('enforce-column-types');
        $dropUnknownTables = (bool) $this->option('drop-unknown-tables');
        $dropExtraColumns = (bool) $this->option('drop-extra-columns');
        $recreateTables = (bool) $this->option('recreate-tables');
        $disableForeignKeyChecks = ! $this->option('no-disable-fk-checks');
        $remapKeys = ! $this->option('no-remap-keys') && ! $this->option('skip-remapping-keys');

        if (! $ci) {
            $this->line('  Transfer options:');
            $enforceColumnTypes = $this->confirm('    Enforce column types on target?', $enforceColumnTypes);
            $dropUnknownTables = $this->confirm('    Drop unknown tables on target?', $dropUnknownTables);
            $dropExtraColumns = $this->confirm('    Drop extra columns on target?', $dropExtraColumns);
            $recreateTables = $this->confirm('    Drop and re-create existing tables on target?', $recreateTables);
            $disableForeignKeyChecks = $this->confirm('    Disable foreign key checks?', $disableForeignKeyChecks);
            $remapKeys = $this->confirm('    Remap keys?', $remapKeys);
            $this->line('');
        }

        // 11. Build result
        $result = new DumpResultData(
            connectionName: $connectionName,
            tables: $tableDumps,
            totalColumns: $totalColumns,
            piiColumnsDetected: $piiColumnsDetected,
            outputPath: $outputPath,
            fakerLocale: $fakerLocale,
            keyRemapping: $onlyPii ? null : $this->buildKeyRemapping($schema),
            includeKeepColumns: $allColumns,
            enforceColumnTypes: $enforceColumnTypes,
            dropUnknownTables: $dropUnknownTables,
            dropExtraColumns: $dropExtraColumns,
            disableForeignKeyChecks: $disableForeignKeyChecks,
            remapKeys: $remapKeys,
            recreateTables: $recreateTables,
        );

        // 12. Write YAML
        $yamlWriter->writeToFile($result, $outputPath);

        // 13. Print summary
        if (! $ci) {
            $keepColumns = $totalColumns - $piiColumnsDetected;

            $this->line('  PII auto-detection:');
            $this->line(sprintf('    ✓  %d column%s matched across %d table%s',
                $piiColumnsDetected,
                $piiColumnsDetected === 1 ? '' : 's',
                count($schema->tables),
                count($schema->tables) === 1 ? '' : 's',
            ));
            $this->line(sprintf('    ○  %d column%s set to keep',
                $keepColumns,
                $keepColumns === 1 ? '' : 's',
            ));
            $this->line('');
            $this->line(sprintf('  Written: ./%s', $outputPath));
            $this->line('');
            $this->line('  Review the file, adjust strategies as needed, then run:');
            $this->line(sprintf('    clonio cloning:run %s --target <name>', $outputPath));
            $this->line('');
        }

        return ExitCode::Success->value;
    }

    /**
     * Switch known framework tables (cache, sessions, queues, tokens, logs) to
     * the 'schema' row strategy. Interactive runs ask first; CI runs apply it.
     *
     * @param  list<TableDumpData>  $tableDumps
     * @return list<TableDumpData>
     */
    private function applyFrameworkTables(array $tableDumps, FrameworkTableCatalog $catalog, bool $ci): array
    {
        /** @var array<string, string> $matches */
        $matches = [];

        foreach ($tableDumps as $tableDump) {
            $label = $catalog->match($tableDump->name);

            if ($label !== null) {
                $matches[$tableDump->name] = $label;
            }
        }

        if ($matches === []) {
            return $tableDumps;
        }

        if (! $ci) {
            $this->line('  Known framework tables (cache, sessions, queues, tokens, logs):');

            foreach ($matches as $name => $label) {
                $this->line(sprintf('    %s <fg=gray>(%s)</>', $name, $label));
            }

            $apply = $this->confirm('  Copy only the schema of these tables, without rows?', true);
            $this->line('');

            if (! $apply) {
                return $tableDumps;
            }
        }

        return array_map(
            static fn (TableDumpData $t): TableDumpData => array_key_exists($t->name, $matches)
                ? new TableDumpData(name: $t->name, columns: $t->columns, rowStrategy: 'schema', rowLimit: null, sortBy: null)
                : $t,
            $tableDumps,
        );
    }

    private function buildKeyRemapping(DatabaseSchemaData $schema): ?KeyRemappingConfigData
    {
        // Collect tables that have a single integer primary key.
        // Tables with composite primary keys (e.g. N:M pivot tables) are
        // skipped — their FK columns are remapped automatically via the
        // parent tables' foreign_keys entries.
        $intPkByTable = [];

        foreach ($schema->tables as $table) {
            $pkColumns = array_filter(
                $table->columns,
                static fn (ColumnSchemaData $col): bool => $col->isPrimary
            );

            if (count($pkColumns) !== 1) {
                continue;
            }

            $pkColumn = reset($pkColumns);

            if ($this->isIntegerType($pkColumn->type)) {
                $intPkByTable[$table->name] = $pkColumn->name;
            }
        }

        if ($intPkByTable === []) {
            return null;
        }

        // Build reverse FK index: referencedTable -> list of KeyRemappingForeignKeyData
        /** @var array<string, list<KeyRemappingForeignKeyData>> $fksByReferencedTable */
        $fksByReferencedTable = [];

        foreach ($schema->tables as $table) {
            foreach ($table->foreignKeys as $fk) {
                if (! isset($intPkByTable[$fk->referencedTable])) {
                    continue;
                }

                $fksByReferencedTable[$fk->referencedTable][] = new KeyRemappingForeignKeyData(
                    table: $table->name,
                    column: $fk->columnName,
                    selfReferential: $table->name === $fk->referencedTable,
                );
            }
        }

        $tables = [];

        foreach ($intPkByTable as $tableName => $primaryKey) {
            $tables[] = new KeyRemappingTableData(
                table: $tableName,
                primaryKey: $primaryKey,
                strategy: KeyRemappingStrategy::RandomInteger,
                foreignKeys: $fksByReferencedTable[$tableName] ?? [],
            );
        }

        return new KeyRemappingConfigData(tables: $tables);
    }

    private function isIntegerType(string $type): bool
    {
        $normalized = strtolower($type);

        return str_contains($normalized, 'int') || in_array($normalized, ['serial', 'bigserial'], true);
    }
}
