<?php

declare(strict_types=1);

namespace App\Services\Database;

use App\Data\ConnectionData;
use App\Enums\DatabaseConnectionType;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

class DatabaseConnectionService
{
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
            'host' => $connection->host,
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

            if ($connection->trustServerCertificate) {
                $config['trust_server_certificate'] = true;
            }
        }

        if ($connection->schema !== null) {
            $config['search_path'] = $connection->schema;
        }

        return $config;
    }

    /**
     * Opens a live database connection and returns its dynamic name.
     *
     * The caller must call DB::purge($name) when done to release the connection.
     *
     * @throws RuntimeException if password decryption fails
     * @throws Throwable if the database connection cannot be established
     */
    public function open(ConnectionData $connection): string
    {
        $password = $this->resolvePassword($connection);
        $name = 'clonio_'.uniqid();

        config(['database.connections.'.$name => $this->buildConfig($connection, $password)]);

        DB::connection($name)->getPdo();

        return $name;
    }
}
