<?php

declare(strict_types=1);

namespace App\Commands\Connection;

use App\Commands\Connection\Concerns\PromptsForSsl;
use App\Data\ConnectionData;
use App\Data\SslConfig;
use App\Enums\DatabaseConnectionType;
use App\Enums\ExitCode;
use App\Enums\SslMode;
use App\Services\Config\ConfigService;
use Illuminate\Support\Facades\Crypt;
use LaravelZero\Framework\Commands\Command;
use RuntimeException;
use Throwable;

class AddCommand extends Command
{
    use PromptsForSsl;

    /**
     * @var string
     */
    protected $signature = 'connection:add
        {name? : The connection name (lowercase alphanumeric, dashes, underscores)}
        {--type= : Database driver type (mysql, mariadb, pgsql, sqlsrv, sqlite, dump)}
        {--dialect= : Target SQL dialect for dump connections (mysql, mariadb, pgsql, sqlsrv, sqlite)}
        {--host= : Database host}
        {--port= : Database port}
        {--database= : Database name or file path}
        {--schema= : Database schema (PostgreSQL only)}
        {--username= : Database username}
        {--password= : Database password}
        {--production : Mark this as a production connection}
        {--ssl-mode= : Transport security — disable|require|verify (network drivers only, default: require)}
        {--ssl-ca= : Path to CA certificate (PEM), used with --ssl-mode=verify}
        {--ssl-cert= : Path to client certificate (PEM, mutual TLS)}
        {--ssl-key= : Path to client private key (PEM, mutual TLS)}
        {--trust-server-certificate : Deprecated — alias for --ssl-mode=require (SQL Server)}';

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

        // --- Step 2b: Dialect (dump connections only) ---
        $dialect = null;

        if ($type === DatabaseConnectionType::Dump) {
            $dialectOption = $this->option('dialect');
            $dialectValue = is_string($dialectOption) && $dialectOption !== '' ? $dialectOption : null;

            if ($dialectValue === null) {
                $choice = $this->choice('Target SQL dialect', DatabaseConnectionType::dialectValues());
                $dialectValue = is_string($choice) ? $choice : '';
            }

            $dialect = DatabaseConnectionType::tryFrom($dialectValue);

            if ($dialect === null || ! $dialect->isDialect()) {
                $this->error(sprintf("Unknown dialect: '%s'. Valid dialects: ", $dialectValue).implode(', ', DatabaseConnectionType::dialectValues()).'.');

                return ExitCode::ValidationError->value;
            }
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

        // --- Step 5: Database name / file path (skip for dump — virtual output target) ---
        $database = null;

        if ($type !== DatabaseConnectionType::Dump) {
            $databaseOption = $this->option('database');
            $database = is_string($databaseOption) && $databaseOption !== '' ? $databaseOption : null;

            if ($database === null) {
                $label = $type === DatabaseConnectionType::Sqlite ? 'Database file path' : 'Database name';
                $asked = $this->ask($label);
                $database = is_string($asked) ? $asked : '';
            }
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

        // --- Step 8b: ZIP password (dump only, optional) ---
        if ($type === DatabaseConnectionType::Dump) {
            $passwordOption = $this->option('password');
            $rawPassword = is_string($passwordOption) && $passwordOption !== '' ? $passwordOption : null;

            if ($rawPassword === null && $this->input->isInteractive()) {
                $asked = $this->secret('ZIP archive password (leave blank for no encryption)');
                $rawPassword = is_string($asked) && $asked !== '' ? $asked : null;
            }

            if ($rawPassword !== null) {
                try {
                    $encryptedPassword = 'encrypted:'.Crypt::encryptString($rawPassword);
                } catch (Throwable) {
                    $this->error('Failed to encrypt password. Ensure APP_KEY is set in your environment.');

                    return ExitCode::ConfigError->value;
                }
            }
        }

        // --- Step 9: Transport security (network drivers only) ---
        $ssl = null;
        $sslModeValue = $this->stringOption('ssl-mode');
        $sslModeGivenExplicitly = $sslModeValue !== null;
        $sslCa = $this->stringOption('ssl-ca');
        $sslCert = $this->stringOption('ssl-cert');
        $sslKey = $this->stringOption('ssl-key');
        $hasSslFileOptions = $sslCa !== null || $sslCert !== null || $sslKey !== null;

        if (! $type->requiresNetworkConfig()) {
            if ($sslModeValue !== null || $hasSslFileOptions) {
                $this->error('ssl is only supported for network connections');

                return ExitCode::ValidationError->value;
            }
        } else {
            if ($sslModeValue === null && $type === DatabaseConnectionType::SqlServer && (bool) $this->option('trust-server-certificate')) {
                $sslModeValue = SslMode::Require->value;
            }

            if ($sslModeValue !== null) {
                $mode = SslMode::tryFrom($sslModeValue);

                if (! $mode instanceof SslMode) {
                    $this->error(sprintf("Unknown ssl mode: '%s'. Valid modes: %s.", $sslModeValue, implode(', ', SslMode::values())));

                    return ExitCode::ValidationError->value;
                }

                $ssl = new SslConfig($mode, $sslCa, $sslCert, $sslKey);
            } else {
                // sqlsrv verifies against the system trust store by default (ODBC Driver 18);
                // require would weaken that, so it keeps its own default (PRD-connection-tls §6.1).
                $defaultMode = $type === DatabaseConnectionType::SqlServer ? SslMode::Verify : SslMode::Require;
                $mode = $this->askSslMode($defaultMode);

                if ($mode instanceof SslMode) {
                    $ssl = $this->askSslFilesForAdd($type, $mode, $sslCa, $sslCert, $sslKey);
                } elseif ($hasSslFileOptions) {
                    $this->error('certificate files require mode require or verify');

                    return ExitCode::ValidationError->value;
                }
            }

            $sslErrors = $this->sslErrors($type, $ssl, $sslModeGivenExplicitly);

            if ($sslErrors !== []) {
                foreach ($sslErrors as $sslError) {
                    $this->error($sslError);
                }

                return ExitCode::ValidationError->value;
            }

            $this->warnIfKeyExposed($ssl);
        }

        // --- Step 10: Production flag ---
        $isProduction = (bool) $this->option('production');

        if (! $isProduction && $this->input->isInteractive()) {
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

        if ($dialect instanceof DatabaseConnectionType) {
            $summaryRows[] = ['Dialect', $dialect->value];
        }

        if ($database !== null) {
            $summaryRows[] = ['Database', $database];
        }

        if ($schema !== null) {
            $summaryRows[] = ['Schema', $schema];
        }

        if ($username !== null) {
            $summaryRows[] = ['Username', $username];
        }

        if ($type->requiresNetworkConfig()) {
            $summaryRows[] = ['Password', '••••••••'];
        }

        if ($type->requiresNetworkConfig()) {
            $summaryRows[] = ['Transport security', $ssl instanceof SslConfig ? $ssl->mode->label() : 'Driver default'];

            if ($ssl instanceof SslConfig && $ssl->ca !== null) {
                $summaryRows[] = ['CA certificate', $ssl->ca];
            }

            if ($ssl instanceof SslConfig && $ssl->cert !== null) {
                $summaryRows[] = ['Client certificate', $ssl->cert];
            }

            if ($ssl instanceof SslConfig && $ssl->key !== null) {
                $summaryRows[] = ['Client key', $ssl->key];
            }
        }

        if ($type === DatabaseConnectionType::Dump) {
            $summaryRows[] = ['Encryption', $encryptedPassword !== '' ? 'AES-256' : 'None'];
        }

        $summaryRows[] = ['Production', $isProduction ? 'Yes' : 'No'];

        $this->table(['Field', 'Value'], $summaryRows);

        // --- Confirm save ---
        if ($this->input->isInteractive() && ! $this->confirm('Save this connection?', true)) {
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
            dialect: $dialect,
            ssl: $ssl,
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

    private function stringOption(string $name): ?string
    {
        $value = $this->option($name);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
