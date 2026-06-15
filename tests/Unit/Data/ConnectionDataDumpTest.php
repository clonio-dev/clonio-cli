<?php

declare(strict_types=1);

use App\Data\ConnectionData;
use App\Enums\DatabaseConnectionType;

it('round-trips a dump connection with dialect and password through to/from array', function (): void {
    $data = [
        'type' => 'dump',
        'dialect' => 'pgsql',
        'password' => 'encrypted:abc',
    ];

    $connection = ConnectionData::fromArray('staging-dump', $data);

    expect($connection->type)->toBe(DatabaseConnectionType::Dump)
        ->and($connection->dialect)->toBe(DatabaseConnectionType::PostgreSQL)
        ->and($connection->host)->toBeNull()
        ->and($connection->database)->toBeNull();

    $array = $connection->toArray();

    expect($array)->toMatchArray(['type' => 'dump', 'dialect' => 'pgsql', 'password' => 'encrypted:abc'])
        ->and($array)->not->toHaveKey('host')
        ->and($array)->not->toHaveKey('database');
});

it('omits the dialect key for non-dump connections', function (): void {
    $connection = new ConnectionData(
        name: 'db', type: DatabaseConnectionType::Mysql, host: 'localhost', port: 3306,
        database: 'app', schema: null, username: 'root', password: '', isProduction: false,
    );

    expect($connection->toArray())->not->toHaveKey('dialect');
});

it('ignores an invalid dialect string', function (): void {
    $connection = ConnectionData::fromArray('d', ['type' => 'dump', 'dialect' => 'nonsense']);

    expect($connection->dialect)->toBeNull();
});
