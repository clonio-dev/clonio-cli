<?php

declare(strict_types=1);

use App\Data\ConnectionData;
use App\Data\SslConfig;
use App\Enums\DatabaseConnectionType;
use App\Enums\SslMode;
use App\Services\Database\Tls\ConnectionErrorHint;

function hintConnection(DatabaseConnectionType $type, ?SslMode $mode): ConnectionData
{
    return new ConnectionData(
        name: 'prod',
        type: $type,
        host: 'db.example.com',
        port: $type->defaultPort(),
        database: 'app',
        schema: null,
        username: 'u',
        password: '',
        isProduction: false,
        ssl: $mode instanceof SslMode ? new SslConfig($mode, $mode === SslMode::Verify ? 'ca.pem' : null) : null,
    );
}

it('tells the user to enable TLS when mysql requires secure transport', function (): void {
    $e = new PDOException('SQLSTATE[HY000] [3159] Connections using insecure transport are prohibited while --require_secure_transport=ON.');

    expect(ConnectionErrorHint::for($e, hintConnection(DatabaseConnectionType::Mysql, null), 'db.example.com'))
        ->toBe('The server requires TLS. Run "clonio connection:update prod" and set transport security to "require" or "verify".');
});

it('tells the user to enable TLS when pg_hba rejects unencrypted connections', function (): void {
    $e = new PDOException('SQLSTATE[08006] [7] FATAL:  no pg_hba.conf entry for host "10.0.0.5", user "u", database "app", no encryption');

    expect(ConnectionErrorHint::for($e, hintConnection(DatabaseConnectionType::PostgreSQL, SslMode::Disable), 'db.example.com'))
        ->toContain('The server requires TLS.');
});

it('names CA and dialled host when mysql verify fails', function (): void {
    $e = new PDOException('SQLSTATE[HY000] [2002] Cannot connect to MySQL using SSL');

    expect(ConnectionErrorHint::for($e, hintConnection(DatabaseConnectionType::MariaDB, SslMode::Verify), 'host.docker.internal'))
        ->toBe('TLS handshake failed. Likely causes: the CA file did not sign the server certificate, or the host name "host.docker.internal" is not in the certificate. Use mode "require" to skip verification.');
});

it('suggests disable when mysql require handshake fails', function (): void {
    $e = new PDOException('SQLSTATE[HY000] [2002] Cannot connect to MySQL using SSL');

    expect(ConnectionErrorHint::for($e, hintConnection(DatabaseConnectionType::Mysql, SslMode::Require), 'db.example.com'))
        ->toBe('TLS handshake failed. The server may not support TLS — use mode "disable" if the connection is on a trusted network.');
});

it('suggests disable when postgres has no TLS (stock docker image + default require)', function (): void {
    $e = new PDOException('SQLSTATE[08006] [7] server does not support SSL, but SSL was required');

    expect(ConnectionErrorHint::for($e, hintConnection(DatabaseConnectionType::PostgreSQL, SslMode::Require), 'localhost'))
        ->toContain('use mode "disable"');
});

it('gives the verification hint for postgres certificate errors', function (string $message): void {
    $e = new PDOException($message);

    expect(ConnectionErrorHint::for($e, hintConnection(DatabaseConnectionType::PostgreSQL, SslMode::Verify), 'db.example.com'))
        ->toContain('TLS handshake failed. Likely causes');
})->with([
    'SQLSTATE[08006] [7] SSL error: certificate verify failed',
    'SQLSTATE[08006] [7] server certificate for "x" does not match host name "db.example.com"',
    'SQLSTATE[08006] [7] root certificate file "system" does not exist',
]);

it('recognises an explicit pg_hba hostnossl reject line', function (): void {
    $e = new PDOException('SQLSTATE[08006] [7] FATAL:  pg_hba.conf rejects connection for host "172.17.0.1", user "postgres", database "clonio_test", no encryption');

    expect(ConnectionErrorHint::for($e, hintConnection(DatabaseConnectionType::PostgreSQL, SslMode::Disable), 'db'))
        ->toStartWith('The server requires TLS.');
});

it('suggests disable when mysqlnd reports [2006] while TLS was requested (server without TLS)', function (SslMode $mode): void {
    $e = new PDOException('SQLSTATE[HY000] [2006] MySQL server has gone away');

    expect(ConnectionErrorHint::for($e, hintConnection(DatabaseConnectionType::Mysql, $mode), 'db'))
        ->toStartWith('TLS handshake failed. The server may not support TLS');
})->with([SslMode::Require, SslMode::Verify]);

it('does not treat [2006] as a TLS problem when TLS was not requested', function (?SslMode $mode): void {
    $e = new PDOException('SQLSTATE[HY000] [2006] MySQL server has gone away');

    expect(ConnectionErrorHint::for($e, hintConnection(DatabaseConnectionType::Mysql, $mode), 'db'))->toBeNull();
})->with([null, SslMode::Disable]);

it('gives the verification hint for SQL Server ODBC certificate errors', function (): void {
    $e = new PDOException('SQLSTATE[08001]: [Microsoft][ODBC Driver 18 for SQL Server]SSL Provider: [error:0A000086:SSL routines::certificate verify failed:self-signed certificate]');

    expect(ConnectionErrorHint::for($e, hintConnection(DatabaseConnectionType::SqlServer, null), '127.0.0.1'))
        ->toStartWith('TLS handshake failed. Likely causes');
});

it('returns null for unrelated errors', function (): void {
    $e = new PDOException('SQLSTATE[HY000] [2002] Connection refused');

    expect(ConnectionErrorHint::for($e, hintConnection(DatabaseConnectionType::Mysql, SslMode::Require), 'db'))->toBeNull();
});
