<?php

declare(strict_types=1);

use App\Data\ConnectionData;
use App\Data\SslConfig;
use App\Enums\DatabaseConnectionType;
use App\Enums\SslMode;

it('round-trips a MySQL connection through fromArray and toArray', function (): void {
    $data = [
        'type' => 'mysql',
        'host' => 'db.example.com',
        'port' => 3306,
        'database' => 'app_db',
        'username' => 'admin',
        'password' => 'encrypted:xyz',
        'is_production' => true,
    ];

    $connection = ConnectionData::fromArray('production', $data);

    expect($connection->name)->toBe('production')
        ->and($connection->type)->toBe(DatabaseConnectionType::Mysql)
        ->and($connection->host)->toBe('db.example.com')
        ->and($connection->port)->toBe(3306)
        ->and($connection->database)->toBe('app_db')
        ->and($connection->schema)->toBeNull()
        ->and($connection->username)->toBe('admin')
        ->and($connection->password)->toBe('encrypted:xyz')
        ->and($connection->isProduction)->toBeTrue();

    $roundTripped = $connection->toArray();
    expect($roundTripped['type'])->toBe('mysql')
        ->and($roundTripped['host'])->toBe('db.example.com')
        ->and($roundTripped['port'])->toBe(3306)
        ->and($roundTripped['database'])->toBe('app_db')
        ->and($roundTripped['username'])->toBe('admin')
        ->and($roundTripped['password'])->toBe('encrypted:xyz')
        ->and($roundTripped['is_production'])->toBeTrue()
        ->and($roundTripped)->not->toHaveKey('schema');
});

it('round-trips a PostgreSQL connection with schema', function (): void {
    $data = [
        'type' => 'pgsql',
        'host' => 'localhost',
        'port' => 5432,
        'database' => 'analytics',
        'schema' => 'reporting',
        'username' => 'pguser',
        'password' => 'encrypted:abc',
        'is_production' => false,
    ];

    $connection = ConnectionData::fromArray('analytics', $data);

    expect($connection->schema)->toBe('reporting');

    $array = $connection->toArray();
    expect($array['schema'])->toBe('reporting');
});

it('includes trust_server_certificate in toArray only when true (SQL Server)', function (): void {
    $data = [
        'type' => 'sqlsrv',
        'host' => 'sql.example.com',
        'port' => 1433,
        'database' => 'AppDb',
        'username' => 'sa',
        'password' => 'encrypted:pass',
        'is_production' => true,
        'trust_server_certificate' => true,
    ];

    $connection = ConnectionData::fromArray('mssql-prod', $data);

    expect($connection->type)->toBe(DatabaseConnectionType::SqlServer)
        ->and($connection->trustServerCertificate)->toBeTrue();

    $array = $connection->toArray();
    expect($array)->toHaveKey('trust_server_certificate')
        ->and($array['trust_server_certificate'])->toBeTrue();

    // When false, the key must be omitted entirely
    $connectionNoTrust = ConnectionData::fromArray('mssql-prod', array_merge($data, ['trust_server_certificate' => false]));
    expect($connectionNoTrust->toArray())->not->toHaveKey('trust_server_certificate');
});

it('round-trips a SQLite connection without network fields', function (): void {
    $data = [
        'type' => 'sqlite',
        'database' => '/var/db/local.sqlite',
        'password' => '',
        'is_production' => false,
    ];

    $connection = ConnectionData::fromArray('local', $data);

    expect($connection->host)->toBeNull()
        ->and($connection->port)->toBeNull()
        ->and($connection->username)->toBeNull()
        ->and($connection->schema)->toBeNull();

    $array = $connection->toArray();
    expect($array)->not->toHaveKey('host')
        ->and($array)->not->toHaveKey('port')
        ->and($array)->not->toHaveKey('username')
        ->and($array)->not->toHaveKey('schema');
});

it('round-trips an ssl block', function (): void {
    $data = [
        'type' => 'mysql', 'host' => 'db', 'port' => 3306, 'database' => 'app', 'username' => 'u',
        'password' => 'encrypted:x', 'is_production' => true,
        'ssl' => ['mode' => 'verify', 'ca' => 'certs/ca.pem'],
    ];

    $connection = ConnectionData::fromArray('prod', $data);

    expect($connection->ssl)->toEqual(new SslConfig(SslMode::Verify, 'certs/ca.pem'))
        ->and($connection->toArray()['ssl'])->toBe(['mode' => 'verify', 'ca' => 'certs/ca.pem']);
});

it('does not add an ssl key to connections that never had one', function (): void {
    $data = [
        'type' => 'mysql', 'host' => 'db', 'port' => 3306, 'database' => 'app', 'username' => 'u',
        'password' => 'encrypted:x', 'is_production' => false,
    ];

    $connection = ConnectionData::fromArray('legacy', $data);

    expect($connection->ssl)->toBeNull()
        ->and($connection->toArray())->toBe($data);
});

it('rejects ssl on a sqlite connection when loading, naming the connection', function (): void {
    ConnectionData::fromArray('local', ['type' => 'sqlite', 'database' => 'a.db', 'password' => '', 'ssl' => ['mode' => 'require']]);
})->throws(InvalidArgumentException::class, 'Connection "local": ssl is only supported for network connections.');

it('prefixes invalid ssl blocks with the connection name', function (): void {
    ConnectionData::fromArray('prod', ['type' => 'mysql', 'password' => '', 'ssl' => ['mode' => 'bogus']]);
})->throws(InvalidArgumentException::class, 'Connection "prod": Invalid ssl mode "bogus"');
