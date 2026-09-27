<?php

declare(strict_types=1);

namespace App\Services\Database;

use App\Data\ConnectionData;
use App\Data\SslConfig;
use App\Enums\DatabaseConnectionType;
use App\Enums\SslMode;
use App\Services\Database\Tls\CertificateFiles;
use App\Services\Database\Tls\ConnectionErrorHint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Pdo\Mysql;
use RuntimeException;
use Throwable;

class DatabaseConnectionService
{
    /** Hosts that mean "this machine" — rewritten to host.docker.internal inside a container. */
    private const array LOOPBACK_HOSTS = ['127.0.0.1', 'localhost', '::1'];

    private const string DOCKER_HOST_GATEWAY = 'host.docker.internal';

    private readonly bool $inDocker;

    private readonly CertificateFiles $certificates;

    public function __construct(?bool $inDocker = null, ?CertificateFiles $certificates = null)
    {
        // /.dockerenv is created by the Docker engine inside every container and
        // is the most portable signal across Linux, macOS, and Windows daemons.
        $this->inDocker = $inDocker ?? is_file('/.dockerenv');
        $this->certificates = $certificates ?? new CertificateFiles;
    }

    /**
     * Returns the plain-text password, decrypting the 'encrypted:' prefix if present.
     *
     * @throws RuntimeException if decryption fails (e.g. missing or wrong APP_KEY)
     */
    public function resolvePassword(ConnectionData $connection): string
    {
        $password = $connection->password;

        if (! str_starts_with($password, 'encrypted:')) {
            return $password;
        }

        try {
            return Crypt::decryptString(substr($password, 10));
        } catch (Throwable) {
            throw new RuntimeException('Could not decrypt password — check APP_KEY.');
        }
    }

    /**
     * Builds the Laravel DB config array for the given connection with a plain-text password.
     *
     * @return array<string, mixed>
     */
    public function buildConfig(ConnectionData $connection, string $password): array
    {
        throw_if($connection->type === DatabaseConnectionType::Dump, RuntimeException::class, 'Dump connections are virtual output targets and have no live database to open.');

        if ($connection->type === DatabaseConnectionType::Sqlite) {
            return [
                'driver' => 'sqlite',
                'database' => $connection->database,
                'prefix' => '',
            ];
        }

        /** @var array<string, mixed> $config */
        $config = [
            'driver' => $connection->type->value,
            'host' => $this->resolveHost($connection->host),
            'port' => $connection->port,
            'database' => $connection->database,
            'username' => $connection->username,
            'password' => $password,
            'prefix' => '',
        ];

        if ($connection->type === DatabaseConnectionType::Mysql || $connection->type === DatabaseConnectionType::MariaDB) {
            $config['charset'] = 'utf8mb4';
            $config['collation'] = 'utf8mb4_unicode_ci';
        } elseif ($connection->type === DatabaseConnectionType::PostgreSQL) {
            $config['charset'] = 'UTF8';
        } elseif ($connection->type === DatabaseConnectionType::SqlServer) {
            $config['charset'] = 'utf8';
        }

        if ($connection->schema !== null) {
            $config['search_path'] = $connection->schema;
        }

        return $this->applyTls($config, $connection);
    }

    /** The host PDO will actually dial (after the Docker loopback rewrite). */
    public function resolvedHost(ConnectionData $connection): ?string
    {
        return $this->resolveHost($connection->host);
    }

    /**
     * Rewrite loopback hostnames to host.docker.internal when running inside a
     * container — inside the container, 127.0.0.1 is the container itself and
     * cannot reach a database running on the host. Non-loopback hostnames are
     * left untouched so user-specified addresses still win.
     */
    private function resolveHost(?string $host): ?string
    {
        if (! $this->inDocker || $host === null) {
            return $host;
        }

        return in_array(strtolower($host), self::LOOPBACK_HOSTS, true)
            ? self::DOCKER_HOST_GATEWAY
            : $host;
    }

    /**
     * Opens a live database connection and returns its dynamic name.
     *
     * The caller must call DB::purge($name) when done to release the connection.
     * Known TLS failures are rethrown as RuntimeException with an actionable hint
     * appended; the driver exception is kept as previous.
     *
     * @throws RuntimeException if password decryption fails, the ssl config is invalid, or a TLS hint applies
     * @throws Throwable if the database connection cannot be established
     */
    public function open(ConnectionData $connection): string
    {
        $password = $this->resolvePassword($connection);
        $name = 'clonio_'.uniqid();

        config(['database.connections.'.$name => $this->buildConfig($connection, $password)]);

        try {
            DB::connection($name)->getPdo();
        } catch (Throwable $throwable) {
            DB::purge($name);

            $hint = ConnectionErrorHint::for($throwable, $connection, $this->resolvedHost($connection));

            throw_if($hint === null, $throwable);

            throw new RuntimeException($throwable->getMessage().PHP_EOL.$hint, 0, $throwable);
        }

        return $name;
    }

    /**
     * The negotiated TLS cipher of an open connection, or null (unencrypted, unsupported driver, query failed).
     *
     * SQL Server never exposes the cipher over T-SQL, only whether the session is encrypted
     * (sys.dm_exec_connections.encrypt_option), so it is handled separately (see
     * sqlsrvEncryptionLabel()) and returns a stable label instead of a cipher name.
     */
    public function negotiatedCipher(string $connectionName, DatabaseConnectionType $type): ?string
    {
        if ($type === DatabaseConnectionType::SqlServer) {
            return $this->sqlsrvEncryptionLabel($connectionName);
        }

        /** @var array{string, string}|null $query */
        $query = match ($type) {
            DatabaseConnectionType::Mysql, DatabaseConnectionType::MariaDB => ["SHOW SESSION STATUS LIKE 'Ssl_cipher'", 'Value'],
            DatabaseConnectionType::PostgreSQL => ['SELECT cipher FROM pg_stat_ssl WHERE pid = pg_backend_pid()', 'cipher'],
            default => null,
        };

        if ($query === null) {
            return null;
        }

        [$sql, $column] = $query;

        try {
            $row = DB::connection($connectionName)->selectOne($sql);
        } catch (Throwable) {
            return null;
        }

        $value = is_object($row) ? (get_object_vars($row)[$column] ?? null) : null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * Whether the open SQL Server session is encrypted, as a stable label — Microsoft does not
     * surface the negotiated cipher via T-SQL, only encrypt_option ('TRUE'/'FALSE') on
     * sys.dm_exec_connections. Returns null when unencrypted, so the "TLS cipher:" line is
     * omitted exactly as it is for a plaintext MySQL/PostgreSQL connection.
     */
    private function sqlsrvEncryptionLabel(string $connectionName): ?string
    {
        try {
            $row = DB::connection($connectionName)->selectOne(
                'SELECT encrypt_option FROM sys.dm_exec_connections WHERE session_id = @@SPID'
            );
        } catch (Throwable) {
            return null;
        }

        $value = is_object($row) ? (get_object_vars($row)['encrypt_option'] ?? null) : null;

        return is_string($value) && strcasecmp($value, 'TRUE') === 0
            ? 'encrypted (cipher not reported by SQL Server)'
            : null;
    }

    /**
     * Translates the connection's ssl block into driver config (PRD-connection-tls §5).
     *
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     *
     * @throws RuntimeException when the ssl block breaks a rule or a certificate file is missing
     */
    private function applyTls(array $config, ConnectionData $connection): array
    {
        $ssl = $connection->ssl;

        if (! $ssl instanceof SslConfig) {
            // Legacy flag, only honoured while no ssl block exists.
            if ($connection->type === DatabaseConnectionType::SqlServer && $connection->trustServerCertificate) {
                $config['trust_server_certificate'] = true;
            }

            return $config;
        }

        $violations = $ssl->violations($connection->type);

        if ($violations !== []) {
            throw new RuntimeException(sprintf('Invalid ssl configuration for connection "%s": %s', $connection->name, implode('; ', $violations)));
        }

        // mysqlnd reports a missing file only as the generic "[2002] Cannot connect to MySQL using SSL".
        $unreadable = $this->certificates->unreadable($ssl);

        if ($unreadable !== []) {
            throw new RuntimeException('Certificate file not found: '.$unreadable[0]);
        }

        return match ($connection->type) {
            DatabaseConnectionType::Mysql, DatabaseConnectionType::MariaDB => $this->applyMysqlTls($config, $ssl),
            DatabaseConnectionType::PostgreSQL => $this->applyPgsqlTls($config, $ssl),
            DatabaseConnectionType::SqlServer => $this->applySqlsrvTls($config, $ssl),
            default => $config,
        };
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    private function applyMysqlTls(array $config, SslConfig $ssl): array
    {
        if ($ssl->mode === SslMode::Disable) {
            return $config;
        }

        // mysqlnd only switches TLS on once an SSL option is present. An empty CA does that
        // without restricting ciphers. VERIFY_SERVER_CERT must always be explicit: mysqlnd
        // verifies CA and hostname by default as soon as TLS is on. Verified against
        // MySQL 8.4 and MariaDB 11 (PRD-connection-tls §5.1).
        $options = [
            Mysql::ATTR_SSL_CA => $ssl->mode === SslMode::Verify && $ssl->ca !== null ? $this->certificates->resolve($ssl->ca) : '',
            Mysql::ATTR_SSL_VERIFY_SERVER_CERT => $ssl->mode === SslMode::Verify,
        ];

        if ($ssl->cert !== null && $ssl->key !== null) {
            $options[Mysql::ATTR_SSL_CERT] = $this->certificates->resolve($ssl->cert);
            $options[Mysql::ATTR_SSL_KEY] = $this->certificates->resolve($ssl->key);
        }

        $config['options'] = $options;

        return $config;
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    private function applyPgsqlTls(array $config, SslConfig $ssl): array
    {
        $config['sslmode'] = match ($ssl->mode) {
            SslMode::Disable => 'disable',
            SslMode::Require => 'require',
            SslMode::Verify => 'verify-full',
        };

        if ($ssl->mode === SslMode::Verify) {
            // Without a CA, "system" makes libpq (>= 16) use the OS trust store instead of ~/.postgresql/root.crt.
            $config['sslrootcert'] = $ssl->ca !== null ? $this->quoteLibpqPath($this->certificates->resolve($ssl->ca)) : 'system';
        }

        if ($ssl->mode !== SslMode::Disable && $ssl->cert !== null && $ssl->key !== null) {
            $config['sslcert'] = $this->quoteLibpqPath($this->certificates->resolve($ssl->cert));
            $config['sslkey'] = $this->quoteLibpqPath($this->certificates->resolve($ssl->key));
        }

        return $config;
    }

    /**
     * Quotes a value for libpq conninfo syntax so paths containing spaces or single quotes
     * survive Laravel's unquoted `key=value` DSN interpolation (PostgresConnector::addSslOptions).
     */
    private function quoteLibpqPath(string $path): string
    {
        return "'".str_replace(['\\', "'"], ['\\\\', "\\'"], $path)."'";
    }

    /**
     * Values are strings: SqlServerConnector interpolates them into the DSN, where false renders empty.
     *
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    private function applySqlsrvTls(array $config, SslConfig $ssl): array
    {
        if ($ssl->mode === SslMode::Disable) {
            $config['encrypt'] = 'no';

            return $config;
        }

        $config['encrypt'] = 'yes';
        $config['trust_server_certificate'] = $ssl->mode === SslMode::Require ? 'yes' : 'no';

        return $config;
    }
}
