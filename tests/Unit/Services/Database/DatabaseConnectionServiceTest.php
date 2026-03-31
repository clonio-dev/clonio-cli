<?php

declare(strict_types=1);

use App\Data\ConnectionData;
use App\Enums\DatabaseConnectionType;
use App\Services\Database\DatabaseConnectionService;

beforeEach(function (): void {
    config(['app.key' => 'base64:ROzyPViGEkER6n3g0OHblde5CygEIcuDlAFbca99xvM=']);
});

function makeConnection(
    DatabaseConnectionType $type,
    string $password = 'secret',
    ?string $schema = null,
): ConnectionData {
    return new ConnectionData(
        name: 'test',
        type: $type,
        host: $type === DatabaseConnectionType::Sqlite ? null : '127.0.0.1',
        port: $type->defaultPort(),
        database: $type === DatabaseConnectionType::Sqlite ? '/tmp/test.db' : 'mydb',
        schema: $schema,
        username: $type === DatabaseConnectionType::Sqlite ? null : 'root',
        password: $password,
        isProduction: false,
    );
}

// ── resolvePassword ──────────────────────────────────────────────────────────

it('returns a plain-text password unchanged', function (): void {
    $service = new DatabaseConnectionService;
    $connection = makeConnection(DatabaseConnectionType::Mysql, 'plain-secret');

    expect($service->resolvePassword($connection))->toBe('plain-secret');
});

it('decrypts an encrypted: password', function (): void {
    $encrypted = 'encrypted:'.encrypt('my-secret', false);
    $connection = makeConnection(DatabaseConnectionType::Mysql, $encrypted);

    $service = new DatabaseConnectionService;
    expect($service->resolvePassword($connection))->toBe('my-secret');
});

it('throws RuntimeException when decryption fails', function (): void {
    $connection = makeConnection(DatabaseConnectionType::Mysql, 'encrypted:notvalidciphertext');

    $service = new DatabaseConnectionService;
    expect(fn () => $service->resolvePassword($connection))
        ->toThrow(RuntimeException::class, 'Could not decrypt password');
});

// ── buildConfig ──────────────────────────────────────────────────────────────

it('sets utf8mb4 charset and collation for MySQL', function (): void {
    $service = new DatabaseConnectionService;
    $config = $service->buildConfig(makeConnection(DatabaseConnectionType::Mysql), 'secret');

    expect($config['charset'])->toBe('utf8mb4')
        ->and($config['collation'])->toBe('utf8mb4_unicode_ci')
        ->and($config['driver'])->toBe('mysql');
});

it('sets utf8mb4 charset and collation for MariaDB', function (): void {
    $service = new DatabaseConnectionService;
    $config = $service->buildConfig(makeConnection(DatabaseConnectionType::MariaDB), 'secret');

    expect($config['charset'])->toBe('utf8mb4')
        ->and($config['collation'])->toBe('utf8mb4_unicode_ci')
        ->and($config['driver'])->toBe('mariadb');
});

it('sets UTF8 charset without collation for PostgreSQL', function (): void {
    $service = new DatabaseConnectionService;
    $config = $service->buildConfig(makeConnection(DatabaseConnectionType::PostgreSQL), 'secret');

    expect($config['charset'])->toBe('UTF8')
        ->and($config)->not->toHaveKey('collation')
        ->and($config['driver'])->toBe('pgsql');
});

it('sets utf8 charset for SQL Server', function (): void {
    $service = new DatabaseConnectionService;
    $config = $service->buildConfig(makeConnection(DatabaseConnectionType::SqlServer), 'secret');

    expect($config['charset'])->toBe('utf8')
        ->and($config['driver'])->toBe('sqlsrv');
});

it('sets no charset or collation for SQLite', function (): void {
    $service = new DatabaseConnectionService;
    $config = $service->buildConfig(makeConnection(DatabaseConnectionType::Sqlite), '');

    expect($config)->not->toHaveKey('charset')
        ->and($config)->not->toHaveKey('collation')
        ->and($config['driver'])->toBe('sqlite');
});

it('includes search_path when schema is set', function (): void {
    $service = new DatabaseConnectionService;
    $connection = makeConnection(DatabaseConnectionType::PostgreSQL, 'secret', 'public');
    $config = $service->buildConfig($connection, 'secret');

    expect($config['search_path'])->toBe('public');
});

it('omits search_path when schema is null', function (): void {
    $service = new DatabaseConnectionService;
    $config = $service->buildConfig(makeConnection(DatabaseConnectionType::PostgreSQL), 'secret');

    expect($config)->not->toHaveKey('search_path');
});
