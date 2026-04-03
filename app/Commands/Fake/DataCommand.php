<?php

declare(strict_types=1);

namespace App\Commands\Fake;

use App\Enums\ExitCode;
use App\Fake\FakeDataSeeder;
use App\Fake\FakeSchemaBuilder;
use App\Services\Config\ConfigService;
use App\Services\Database\DatabaseConnectionService;
use Illuminate\Support\Facades\DB;
use LaravelZero\Framework\Commands\Command;
use Throwable;

/**
 * Seed a configured database connection with realistic demo data.
 *
 * Schema (created automatically):
 *   Task management  — users, user_login_history, projects, issues, comments
 *   Product catalog  — categories, tags, products, product_tags
 *
 * Primary key variety:
 *   UUID   — users, projects, products
 *   bigint — user_login_history, issues, comments, categories, tags
 *   composite (UUID + bigint) — product_tags
 *
 * @codeCoverageIgnore
 */
class DataCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'fake:data
        {connection        : Name of the database connection from clonio.json}
        {rows=1000         : Number of rows to insert per table (e.g. 1000000)}
        {--fresh           : Drop existing tables and recreate schema before seeding}';

    /**
     * @var string
     */
    protected $description = 'Seed a database connection with realistic fake data for local testing';

    public function handle(ConfigService $config, DatabaseConnectionService $connector): int
    {
        // ── 1. Resolve connection ─────────────────────────────────────────────

        // 'connection' is a required argument; Larastan infers string
        $connectionName = $this->argument('connection');

        $connectionData = $config->getConnection($connectionName);

        if ($connectionData === null) {
            $this->error(sprintf("Connection '%s' not found in clonio.json.", $connectionName));
            $this->line('Run <info>connection:list</info> to see available connections.');

            return ExitCode::ConfigError->value;
        }

        // ── 2. Parse row count ────────────────────────────────────────────────

        $rowsArg = $this->argument('rows');
        $rowCount = filter_var($rowsArg, FILTER_VALIDATE_INT);

        if ($rowCount === false || $rowCount < 1) {
            $this->error('rows must be a positive integer.');

            return ExitCode::ValidationError->value;
        }

        $fresh = (bool) $this->option('fresh');

        // ── 3. Open connection ────────────────────────────────────────────────

        $this->line(sprintf(
            'Connecting to <info>%s</info> (%s) …',
            $connectionName,
            $connectionData->type->label(),
        ));

        try {
            $dbName = $connector->open($connectionData);
        } catch (Throwable $e) {
            $this->error('Could not connect: '.$e->getMessage());

            return ExitCode::ConnectionError->value;
        }

        // ── 4. Schema ─────────────────────────────────────────────────────────

        $schema = new FakeSchemaBuilder($dbName, $connectionData->type);

        if ($fresh) {
            $this->line('Dropping existing tables (--fresh) …');

            try {
                $schema->dropAll();
            } catch (Throwable $e) {
                $this->error('Failed to drop tables: '.$e->getMessage());
                DB::purge($dbName);

                return ExitCode::GeneralError->value;
            }
        }

        $this->line('Creating tables (if not exist) …');

        try {
            $schema->createAll();
        } catch (Throwable $e) {
            $this->error('Failed to create schema: '.$e->getMessage());
            DB::purge($dbName);

            return ExitCode::GeneralError->value;
        }

        // ── 5. Seed ───────────────────────────────────────────────────────────

        $this->line(sprintf(
            'Seeding <info>%s</info> rows per table across 9 tables …',
            number_format($rowCount),
        ));
        $this->newLine();

        $seeder = new FakeDataSeeder($dbName, $rowCount);

        $activeTable = '';
        $bar = null;

        try {
            $counts = $seeder->seed(
                function (string $table, int $inserted, int $total) use (&$activeTable, &$bar): void {
                    if ($activeTable !== $table) {
                        if ($bar !== null) {
                            $bar->finish();
                            $this->newLine();
                        }

                        $bar = $this->output->createProgressBar($total);
                        $bar->setFormat(sprintf(' %-22s [%%bar%%] %%current%%/%%max%%', $table));
                        $bar->start();
                        $activeTable = $table;
                    }

                    $bar?->setMaxSteps($total);
                    $bar?->setProgress($inserted);
                }
            );
        } catch (Throwable $e) {
            if ($bar !== null) {
                $bar->finish();
                $this->newLine();
            }

            $this->error('Seeding failed: '.$e->getMessage());
            DB::purge($dbName);

            return ExitCode::GeneralError->value;
        } finally {
            DB::purge($dbName);
        }

        if ($bar !== null) {
            $bar->finish();
            $this->newLine();
        }

        $this->newLine(2);

        // ── 6. Summary ────────────────────────────────────────────────────────

        $rows = [];
        foreach ($counts as $table => $n) {
            $rows[] = [$table, number_format($n)];
        }

        $total = array_sum($counts);
        $rows[] = ['<info>TOTAL</info>', '<info>'.number_format($total).'</info>'];

        $this->table(['Table', 'Rows inserted'], $rows);
        $this->info('Done.');

        return ExitCode::Success->value;
    }
}
