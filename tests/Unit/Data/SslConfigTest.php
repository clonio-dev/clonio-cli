<?php

declare(strict_types=1);

use App\Data\SslConfig;
use App\Enums\DatabaseConnectionType;
use App\Enums\SslMode;

it('returns null when no ssl block is given', function (): void {
    expect(SslConfig::fromArray(null))->toBeNull();
});

it('parses mode and paths', function (): void {
    $ssl = SslConfig::fromArray(['mode' => 'verify', 'ca' => 'certs/ca.pem', 'cert' => 'c.pem', 'key' => 'k.pem']);

    expect($ssl)->toBeInstanceOf(SslConfig::class)
        ->and($ssl?->mode)->toBe(SslMode::Verify)
        ->and($ssl?->ca)->toBe('certs/ca.pem')
        ->and($ssl?->cert)->toBe('c.pem')
        ->and($ssl?->key)->toBe('k.pem');
});

it('treats empty path strings as absent', function (): void {
    $ssl = SslConfig::fromArray(['mode' => 'require', 'ca' => '']);

    expect($ssl?->ca)->toBeNull();
});

it('rejects an unknown mode and lists the valid ones', function (): void {
    SslConfig::fromArray(['mode' => 'prefer']);
})->throws(InvalidArgumentException::class, 'Invalid ssl mode "prefer". Valid modes: require, verify, disable.');

it('rejects a missing mode', function (): void {
    SslConfig::fromArray(['ca' => 'certs/ca.pem']);
})->throws(InvalidArgumentException::class, 'Invalid ssl mode');

it('rejects a plain string instead of an object (web-app style "ssl": "require")', function (): void {
    SslConfig::fromArray('require');
})->throws(InvalidArgumentException::class, 'ssl must be an object with a "mode" key.');

it('serialises only the fields that are set', function (): void {
    expect((new SslConfig(SslMode::Require))->toArray())->toBe(['mode' => 'require'])
        ->and((new SslConfig(SslMode::Verify, 'ca.pem'))->toArray())->toBe(['mode' => 'verify', 'ca' => 'ca.pem']);
});

it('has no violations for valid configurations', function (DatabaseConnectionType $type, SslConfig $ssl): void {
    expect($ssl->violations($type))->toBe([]);
})->with([
    'mysql require' => [DatabaseConnectionType::Mysql, new SslConfig(SslMode::Require)],
    'mysql require mtls' => [DatabaseConnectionType::Mysql, new SslConfig(SslMode::Require, null, 'c.pem', 'k.pem')],
    'mariadb verify with ca' => [DatabaseConnectionType::MariaDB, new SslConfig(SslMode::Verify, 'ca.pem')],
    'pgsql verify without ca' => [DatabaseConnectionType::PostgreSQL, new SslConfig(SslMode::Verify)],
    'sqlsrv verify' => [DatabaseConnectionType::SqlServer, new SslConfig(SslMode::Verify)],
    'pgsql disable' => [DatabaseConnectionType::PostgreSQL, new SslConfig(SslMode::Disable)],
]);

it('reports each rule violation', function (DatabaseConnectionType $type, SslConfig $ssl, string $error): void {
    expect($ssl->violations($type))->toContain($error);
})->with([
    'sqlite' => [DatabaseConnectionType::Sqlite, new SslConfig(SslMode::Require), 'ssl is only supported for network connections'],
    'dump' => [DatabaseConnectionType::Dump, new SslConfig(SslMode::Require), 'ssl is only supported for network connections'],
    'files with disable' => [DatabaseConnectionType::Mysql, new SslConfig(SslMode::Disable, null, 'c.pem', 'k.pem'), 'certificate files require mode require or verify'],
    'ca with require' => [DatabaseConnectionType::PostgreSQL, new SslConfig(SslMode::Require, 'ca.pem'), 'a CA certificate is only used with mode verify'],
    'files on sqlsrv' => [DatabaseConnectionType::SqlServer, new SslConfig(SslMode::Verify, 'ca.pem'), 'sqlsrv uses the system trust store; certificate paths are not supported'],
    'mysql verify without ca' => [DatabaseConnectionType::Mysql, new SslConfig(SslMode::Verify), 'mode verify requires a CA certificate for MySQL/MariaDB'],
    'cert without key' => [DatabaseConnectionType::PostgreSQL, new SslConfig(SslMode::Require, null, 'c.pem'), 'cert and key must be set together'],
    'key without cert' => [DatabaseConnectionType::PostgreSQL, new SslConfig(SslMode::Require, null, null, 'k.pem'), 'cert and key must be set together'],
]);

it('labels modes for prompts', function (): void {
    expect(SslMode::Require->label())->toBe('Require (encrypted, not verified)')
        ->and(SslMode::Verify->label())->toBe('Verify (encrypted + certificate check)')
        ->and(SslMode::Disable->label())->toBe('Disable (plaintext)')
        ->and(SslMode::values())->toBe(['require', 'verify', 'disable']);
});
