<?php

declare(strict_types=1);

namespace App\Commands\Connection;

use App\Data\ConnectionData;
use App\Enums\DatabaseConnectionType;
use App\Enums\ExitCode;
use App\Services\Config\ConfigService;
use Illuminate\Support\Facades\Crypt;
use LaravelZero\Framework\Commands\Command;
use RuntimeException;
use Throwable;

class AddCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'connection:add
        {name? : The connection name (lowercase alphanumeric, dashes, underscores)}
        {--type= : Database driver type (mysql, mariadb, pgsql, sqlsrv, sqlite)}
        {--host= : Database host}
        {--port= : Database port}
        {--database= : Database name or file path}
        {--schema= : Database schema (PostgreSQL only)}
        {--username= : Database username}
        {--password= : Database password}
        {--production : Mark this as a production connection}
        {--trust-server-certificate : Trust the server certificate (SQL Server with self-signed certs)}';

    /**
     * @var string
     */
    protected $description = 'Add a new database connection to clonio.json';

    public function handle(ConfigService $config): int
    {
        // --- Step 1: Name ---
        $nameArg = $this->argument('name');
        $name = is_string($nameArg) && $nameArg !== '' ? $nameArg : null;

        if ($name === null) {
            $asked = $this->ask('Connection name');
            $name = is_string($asked) ? $asked : '';
        }

        if (! preg_match('/^[a-z0-9_-]+$/', $name)) {
            $this->error('Invalid connection name. Use only lowercase letters, numbers, dashes, and underscores.');

            return ExitCode::ValidationError->value;
        }

        if ($config->hasConnection($name)) {
            $this->error(sprintf("A connection named '%s' already exists.", $name));

            return ExitCode::ValidationError->value;
        }

        // --- Step 2: Driver type ---
        $typeOption = $this->option('type');
        $typeValue = is_string($typeOption) && $typeOption !== '' ? $typeOption : null;

        if ($typeValue === null) {
            $choice = $this->choice('Database driver', DatabaseConnectionType::values());
            $typeValue = is_string($choice) ? $choice : '';
        }

        $type = DatabaseConnectionType::tryFrom($typeValue);

        if ($type === null) {
            $this->error(sprintf("Unknown driver type: '%s'. Valid types: ", $typeValue).implode(', ', DatabaseConnectionType::values()).'.');

            return ExitCode::ValidationError->value;
        }

        // --- Step 3: Host (skip for SQLite) ---
        $host = null;

        if ($type->requiresNetworkConfig()) {
            $hostOption = $this->option('host');
            $host = is_string($hostOption) && $hostOption !== '' ? $hostOption : null;

            if ($host === null) {
                $asked = $this->ask('Host', 'localhost');
                $host = is_string($asked) ? $asked : 'localhost';
            }
        }

        // --- Step 4: Port (skip for SQLite) ---
        $port = null;

        if ($type->requiresNetworkConfig()) {
            $portOption = $this->option('port');
            $portValue = is_string($portOption) && $portOption !== '' ? $portOption : null;

            if ($portValue === null) {
                $defaultPort = (string) ($type->defaultPort() ?? '');
                $asked = $this->ask('Port', $defaultPort);
                $portValue = is_string($asked) ? $asked : $defaultPort;
            }

            $portInt = filter_var($portValue, FILTER_VALIDATE_INT);

            if ($portInt === false || $portInt < 1 || $portInt > 65535) {
                $this->error('Invalid port. Must be a number between 1 and 65535.');

                return ExitCode::ValidationError->value;
            }

            $port = $portInt;
        }

        // --- Step 5: Database name / file path ---
        $databaseOption = $this->option('database');
        $database = is_string($databaseOption) && $databaseOption !== '' ? $databaseOption : null;

        if ($database === null) {
            $label = $type === DatabaseConnectionType::Sqlite ? 'Database file path' : 'Database name';
            $asked = $this->ask($label);
            $database = is_string($asked) ? $asked : '';
        }

        // --- Step 6: Schema (PostgreSQL only) ---
        $schema = null;

        if ($type->requiresSchema()) {
            $schemaOption = $this->option('schema');
            $schema = is_string($schemaOption) && $schemaOption !== '' ? $schemaOption : null;

            if ($schema === null) {
                $asked = $this->ask('Schema', 'public');
                $schema = is_string($asked) ? $asked : 'public';
            }
        }

        // --- Step 7: Username (skip for SQLite) ---
        $username = null;

        if ($type->requiresNetworkConfig()) {
            $usernameOption = $this->option('username');
            $username = is_string($usernameOption) && $usernameOption !== '' ? $usernameOption : null;

            if ($username === null) {
                $asked = $this->ask('Username');
                $username = is_string($asked) ? $asked : '';
            }
        }

        // --- Step 8: Password (skip for SQLite) ---
        $encryptedPassword = '';

        if ($type->requiresNetworkConfig()) {
            $passwordOption = $this->option('password');
            $rawPassword = is_string($passwordOption) && $passwordOption !== '' ? $passwordOption : null;

            if ($rawPassword === null) {
                $asked = $this->secret('Password');
                $rawPassword = is_string($asked) ? $asked : '';
            }

            try {
                $encryptedPassword = 'encrypted:'.Crypt::encryptString($rawPassword);
            } catch (Throwable) {
                $this->error('Failed to encrypt password. Ensure APP_KEY is set in your environment.');

                return ExitCode::ConfigError->value;
            }
        }

        // --- Step 9: Trust server certificate (SQL Server only) ---
        $trustServerCertificate = false;

        if ($type === DatabaseConnectionType::SqlServer) {
            $trustServerCertificate = (bool) $this->option('trust-server-certificate');

            if (! $trustServerCertificate) {
                $trustServerCertificate = $this->confirm('Trust server certificate? (required for self-signed certs)', false);
            }
        }

        // --- Step 10: Production flag ---
        $isProduction = (bool) $this->option('production');

        if (! $isProduction) {
            $isProduction = $this->confirm('Is this a production connection?', false);
        }

        // --- Production warning ---
        if ($isProduction) {
            $this->warn('This connection is marked as production. Destructive operations will require confirmation.');
        }

        // --- Summary table ---
        $summaryRows = [
            ['Name', $name],
            ['Driver', $type->value],
        ];

        if ($host !== null) {
            $summaryRows[] = ['Host', $host];
        }

        if ($port !== null) {
            $summaryRows[] = ['Port', (string) $port];
        }

        $summaryRows[] = ['Database', $database];

        if ($schema !== null) {
            $summaryRows[] = ['Schema', $schema];
        }

        if ($username !== null) {
            $summaryRows[] = ['Username', $username];
        }

        if ($type->requiresNetworkConfig()) {
            $summaryRows[] = ['Password', '••••••••'];
        }

        if ($type === DatabaseConnectionType::SqlServer) {
            $summaryRows[] = ['Trust certificate', $trustServerCertificate ? 'Yes' : 'No'];
        }

        $summaryRows[] = ['Production', $isProduction ? 'Yes' : 'No'];

        $this->table(['Field', 'Value'], $summaryRows);

        // --- Confirm save ---
        if (! $this->confirm('Save this connection?', true)) {
            $this->line('Cancelled.');

            return ExitCode::Success->value;
        }

        // --- Persist ---
        $connection = new ConnectionData(
            name: $name,
            type: $type,
            host: $host,
            port: $port,
            database: $database,
            schema: $schema,
            username: $username,
            password: $encryptedPassword,
            isProduction: $isProduction,
            trustServerCertificate: $trustServerCertificate,
        );

        try {
            $config->setConnection($name, $connection);
        } catch (RuntimeException $runtimeException) {
            $this->error('Failed to save connection: '.$runtimeException->getMessage());

            return ExitCode::IoError->value;
        }

        $this->info(sprintf("Connection '%s' added successfully.", $name));

        return ExitCode::Success->value;
    }
}
