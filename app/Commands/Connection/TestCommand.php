<?php

declare(strict_types=1);

namespace App\Commands\Connection;

use App\Commands\Connection\Concerns\DescribesTls;
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
    use DescribesTls;

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

        $verbose = $this->output->isVerbose();
        [$ok, $message, $elapsed, $exitCode, $cipher] = $this->testConnection($connection, $connector, $verbose && ! $ci);

        if ($ok) {
            if (! $ci) {
                if ($connection->type === DatabaseConnectionType::Dump) {
                    $this->line(sprintf(
                        'Dump connection "%s" — dialect: %s, target: %s, encryption: %s',
                        $name,
                        $connection->dialect instanceof DatabaseConnectionType ? $connection->dialect->value : 'unknown',
                        getcwd() ?: '.',
                        $connection->password !== '' ? 'AES-256' : 'none',
                    ));
                } elseif ($connection->type === DatabaseConnectionType::Sqlite) {
                    $this->line(sprintf('%s: OK (%dms)', $name, $elapsed));
                } else {
                    $this->line(sprintf('%s: OK (%dms, tls: %s)', $name, $elapsed, $this->tlsLabel($connection)));

                    if ($cipher !== null) {
                        $this->line('  TLS cipher: '.$cipher);
                    }
                }
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

        $verbose = $this->output->isVerbose();
        $wantCipher = $verbose && ! $ci;

        $rows = [];
        $failCount = 0;
        $worstExitCode = ExitCode::Success;

        foreach ($connections as $name => $connection) {
            [$ok, $message, $elapsed, $exitCode, $cipher] = $this->testConnection($connection, $connector, $wantCipher);

            if (! $ok) {
                $failCount++;
                $worstExitCode = $exitCode;
            }

            $row = [
                'Connection' => $name,
                'Driver' => $connection->type->label(),
                'TLS' => $this->tlsLabel($connection),
            ];

            if ($verbose) {
                $row['Cipher'] = $cipher ?? '—';
            }

            $row['Status'] = $ok ? 'OK' : 'FAILED — '.$message;
            $row['Time'] = $ok ? $elapsed.'ms' : '-';

            $rows[] = $row;
        }

        $total = count($connections);

        if (! $ci) {
            $headers = ['Connection', 'Driver', 'TLS'];

            if ($verbose) {
                $headers[] = 'Cipher';
            }

            $headers[] = 'Status';
            $headers[] = 'Time';

            $this->table($headers, $rows);
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
     * Test a single connection and return [bool $ok, string $message, int $elapsedMs, ExitCode, ?string $cipher].
     *
     * $wantCipher gates the extra cipher query on network connections — callers pass true only
     * when the negotiated cipher will actually be shown, so nothing is queried and discarded.
     *
     * @return array{bool, string, int, ExitCode, ?string}
     */
    private function testConnection(ConnectionData $connection, DatabaseConnectionService $connector, bool $wantCipher): array
    {
        $start = hrtime(true);

        if ($connection->type === DatabaseConnectionType::Dump) {
            return [...$this->testDump($start), null];
        }

        if ($connection->type === DatabaseConnectionType::Sqlite) {
            return [...$this->testSqlite($connection, $start), null];
        }

        return $this->testNetwork($connection, $start, $connector, $wantCipher);
    }

    /**
     * Dump connections have no PDO — verify the working directory is writable instead.
     *
     * @return array{bool, string, int, ExitCode}
     */
    private function testDump(int $start): array
    {
        $cwd = getcwd();

        if ($cwd === false || ! is_writable($cwd)) {
            return [false, 'Working directory not writable: '.($cwd === false ? '(unknown)' : $cwd), $this->elapsedMs($start), ExitCode::IoError];
        }

        return [true, '', $this->elapsedMs($start), ExitCode::Success];
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
     * On success, $cipher is the negotiated TLS cipher when $wantCipher is true and the driver
     * reports one, otherwise null.
     *
     * @return array{bool, string, int, ExitCode, ?string}
     */
    private function testNetwork(ConnectionData $connection, int $start, DatabaseConnectionService $connector, bool $wantCipher): array
    {
        try {
            $connector->resolvePassword($connection);
        } catch (RuntimeException) {
            return [false, 'Could not decrypt password — check APP_KEY.', $this->elapsedMs($start), ExitCode::ConfigError, null];
        }

        try {
            $dynamicName = $connector->open($connection);
        } catch (Throwable $throwable) {
            return [false, $throwable->getMessage(), $this->elapsedMs($start), ExitCode::ConnectionError, null];
        }

        $elapsed = $this->elapsedMs($start);
        $cipher = $wantCipher ? $connector->negotiatedCipher($dynamicName, $connection->type) : null;

        DB::purge($dynamicName);

        return [true, '', $elapsed, ExitCode::Success, $cipher];
    }

    private function elapsedMs(int $startNs): int
    {
        return (int) round((hrtime(true) - $startNs) / 1_000_000);
    }
}
