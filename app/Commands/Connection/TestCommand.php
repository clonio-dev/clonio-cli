<?php

declare(strict_types=1);

namespace App\Commands\Connection;

use App\Data\ConnectionData;
use App\Enums\DatabaseConnectionType;
use App\Enums\ExitCode;
use App\Services\Config\ConfigService;
use App\Services\Database\DatabaseConnectionService;
use Illuminate\Support\Facades\DB;
use LaravelZero\Framework\Commands\Command;
use RuntimeException;
use Throwable;

class TestCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'connection:test
        {name? : The name of the connection to test}
        {--ci : Suppress non-error output}';

    /**
     * @var string
     */
    protected $description = 'Test one or all saved database connections';

    public function handle(ConfigService $config, DatabaseConnectionService $connector): int
    {
        $name = $this->argument('name');
        $ci = (bool) $this->option('ci');

        if (is_string($name) && $name !== '') {
            return $this->testSingle($config, $connector, $name, $ci);
        }

        return $this->testAll($config, $connector, $ci);
    }

    private function testSingle(ConfigService $config, DatabaseConnectionService $connector, string $name, bool $ci): int
    {
        $connection = $config->getConnection($name);

        if (! $connection instanceof ConnectionData) {
            $this->error(sprintf("No connection named '%s' found.", $name));

            return ExitCode::ConfigError->value;
        }

        [$ok, $message, $elapsed, $exitCode] = $this->testConnection($connection, $connector);

        if ($ok) {
            if (! $ci) {
                $this->line(sprintf('%s: OK (%dms)', $name, $elapsed));
            }

            return ExitCode::Success->value;
        }

        $this->error(sprintf('%s: FAILED — %s', $name, $message));

        return $exitCode->value;
    }

    private function testAll(ConfigService $config, DatabaseConnectionService $connector, bool $ci): int
    {
        $connections = $config->getConnections();

        if ($connections === []) {
            $this->error('No connections defined.');

            return ExitCode::ConfigError->value;
        }

        $rows = [];
        $failCount = 0;
        $worstExitCode = ExitCode::Success;

        foreach ($connections as $name => $connection) {
            [$ok, $message, $elapsed, $exitCode] = $this->testConnection($connection, $connector);

            if (! $ok) {
                $failCount++;
                $worstExitCode = $exitCode;
            }

            $rows[] = [
                'Connection' => $name,
                'Driver' => $connection->type->label(),
                'Status' => $ok ? 'OK' : 'FAILED — '.$message,
                'Time' => $ok ? $elapsed.'ms' : '-',
            ];
        }

        $total = count($connections);

        if (! $ci) {
            $this->table(['Connection', 'Driver', 'Status', 'Time'], $rows);
        } else {
            foreach ($rows as $row) {
                if (str_starts_with($row['Status'], 'FAILED')) {
                    $this->error(sprintf('%s: %s', $row['Connection'], $row['Status']));
                }
            }
        }

        if ($failCount === 0) {
            $this->line(sprintf('All %d connection', $total).($total === 1 ? '' : 's').' OK.');
        } else {
            $this->line(sprintf('%d of %d connection', $failCount, $total).($total === 1 ? '' : 's').' failed.');
        }

        return $failCount === 0
            ? ExitCode::Success->value
            : $worstExitCode->value;
    }

    /**
     * Test a single connection and return [bool $ok, string $message, int $elapsedMs, ExitCode].
     *
     * @return array{bool, string, int, ExitCode}
     */
    private function testConnection(ConnectionData $connection, DatabaseConnectionService $connector): array
    {
        $start = hrtime(true);

        if ($connection->type === DatabaseConnectionType::Sqlite) {
            return $this->testSqlite($connection, $start);
        }

        return $this->testNetwork($connection, $start, $connector);
    }

    /**
     * @return array{bool, string, int, ExitCode}
     */
    private function testSqlite(ConnectionData $connection, int $start): array
    {
        $path = (string) $connection->database;

        if (! file_exists($path)) {
            return [false, 'File not found: '.$path, $this->elapsedMs($start), ExitCode::ConnectionError];
        }

        if (! is_readable($path)) {
            return [false, 'File not readable: '.$path, $this->elapsedMs($start), ExitCode::ConnectionError];
        }

        if (! is_writable($path)) {
            return [false, 'File not writable: '.$path, $this->elapsedMs($start), ExitCode::ConnectionError];
        }

        return [true, '', $this->elapsedMs($start), ExitCode::Success];
    }

    /**
     * @return array{bool, string, int, ExitCode}
     */
    private function testNetwork(ConnectionData $connection, int $start, DatabaseConnectionService $connector): array
    {
        try {
            $password = $connector->resolvePassword($connection);
        } catch (RuntimeException) {
            return [false, 'Could not decrypt password — check APP_KEY.', $this->elapsedMs($start), ExitCode::ConfigError];
        }

        $dynamicName = 'clonio_test_'.uniqid();

        config(['database.connections.'.$dynamicName => $connector->buildConfig($connection, $password)]);

        try {
            DB::connection($dynamicName)->getPdo();
        } catch (Throwable $throwable) {
            DB::purge($dynamicName);

            return [false, $throwable->getMessage(), $this->elapsedMs($start), ExitCode::ConnectionError];
        }

        DB::purge($dynamicName);

        return [true, '', $this->elapsedMs($start), ExitCode::Success];
    }

    private function elapsedMs(int $startNs): int
    {
        return (int) round((hrtime(true) - $startNs) / 1_000_000);
    }
}
