<?php

declare(strict_types=1);

use App\Data\ConnectionData;
use App\Enums\DatabaseConnectionType;

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
