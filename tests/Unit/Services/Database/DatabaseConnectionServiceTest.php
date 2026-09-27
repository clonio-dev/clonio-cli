<?php

declare(strict_types=1);

use App\Data\ConnectionData;
use App\Data\SslConfig;
use App\Enums\DatabaseConnectionType;
use App\Enums\SslMode;
use App\Services\Database\DatabaseConnectionService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Pdo\Mysql;

beforeEach(function (): void {
    config(['app.key' => 'base64:ROzyPViGEkER6n3g0OHblde5CygEIcuDlAFbca99xvM=']);
});

function makeConnection(
    DatabaseConnectionType $type,
    string $password = 'secret',
    ?string $schema = null,
    ?string $host = null,
    ?SslConfig $ssl = null,
    bool $trustServerCertificate = false,
): ConnectionData {
    return new ConnectionData(
        name: 'test',
        type: $type,
        host: $type === DatabaseConnectionType::Sqlite ? null : ($host ?? '127.0.0.1'),
        port: $type->defaultPort(),
        database: $type === DatabaseConnectionType::Sqlite ? '/tmp/test.db' : 'mydb',
        schema: $schema,
        username: $type === DatabaseConnectionType::Sqlite ? null : 'root',
        password: $password,
        isProduction: false,
        trustServerCertificate: $trustServerCertificate,
        ssl: $ssl,
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
        ->and($config['driver'])->toBe('sqlsrv')
        ->and($config)->not->toHaveKey('trust_server_certificate');
});

it('sets trust_server_certificate for SQL Server when enabled', function (): void {
    $service = new DatabaseConnectionService;
    $connection = new ConnectionData(
        name: 'test',
        type: DatabaseConnectionType::SqlServer,
        host: '127.0.0.1',
        port: 1433,
        database: 'mydb',
        schema: null,
        username: 'sa',
        password: 'secret',
        isProduction: false,
        trustServerCertificate: true,
    );

    $config = $service->buildConfig($connection, 'secret');

    expect($config['trust_server_certificate'])->toBeTrue();
});

it('throws when building config for a dump connection', function (): void {
    $service = new DatabaseConnectionService;
    $connection = new ConnectionData(
        name: 'd',
        type: DatabaseConnectionType::Dump,
        host: null,
        port: null,
        database: null,
        schema: null,
        username: null,
        password: '',
        isProduction: false,
        dialect: DatabaseConnectionType::Mysql,
    );

    expect(fn () => $service->buildConfig($connection, ''))
        ->toThrow(RuntimeException::class, 'virtual output targets');
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

// ── host rewrite when running inside docker ──────────────────────────────────

it('rewrites 127.0.0.1 to host.docker.internal when running in docker', function (): void {
    $service = new DatabaseConnectionService(inDocker: true);
    $config = $service->buildConfig(
        makeConnection(DatabaseConnectionType::Mysql, host: '127.0.0.1'),
        'secret'
    );

    expect($config['host'])->toBe('host.docker.internal');
});

it('rewrites localhost to host.docker.internal when running in docker', function (): void {
    $service = new DatabaseConnectionService(inDocker: true);
    $config = $service->buildConfig(
        makeConnection(DatabaseConnectionType::Mysql, host: 'localhost'),
        'secret'
    );

    expect($config['host'])->toBe('host.docker.internal');
});

it('rewrites ::1 to host.docker.internal when running in docker', function (): void {
    $service = new DatabaseConnectionService(inDocker: true);
    $config = $service->buildConfig(
        makeConnection(DatabaseConnectionType::PostgreSQL, host: '::1'),
        'secret'
    );

    expect($config['host'])->toBe('host.docker.internal');
});

it('leaves non-loopback hosts untouched when running in docker', function (): void {
    $service = new DatabaseConnectionService(inDocker: true);
    $config = $service->buildConfig(
        makeConnection(DatabaseConnectionType::Mysql, host: '192.168.1.50'),
        'secret'
    );

    expect($config['host'])->toBe('192.168.1.50');
});

it('does not rewrite hosts when not running in docker', function (): void {
    $service = new DatabaseConnectionService(inDocker: false);
    $config = $service->buildConfig(
        makeConnection(DatabaseConnectionType::Mysql, host: '127.0.0.1'),
        'secret'
    );

    expect($config['host'])->toBe('127.0.0.1');
});

// ── TLS mapping ──────────────────────────────────────────────────────────────

it('adds no TLS keys when ssl is unset', function (DatabaseConnectionType $type): void {
    $config = (new DatabaseConnectionService(inDocker: false))->buildConfig(makeConnection($type), 'secret');

    expect($config)->not->toHaveKeys(['options', 'sslmode', 'sslrootcert', 'encrypt', 'trust_server_certificate']);
})->with([DatabaseConnectionType::Mysql, DatabaseConnectionType::MariaDB, DatabaseConnectionType::PostgreSQL, DatabaseConnectionType::SqlServer]);

it('maps mysql require to an empty CA with verification off', function (): void {
    $config = (new DatabaseConnectionService(inDocker: false))
        ->buildConfig(makeConnection(DatabaseConnectionType::Mysql, ssl: new SslConfig(SslMode::Require)), 'secret');

    expect($config['options'])->toBe([Mysql::ATTR_SSL_CA => '', Mysql::ATTR_SSL_VERIFY_SERVER_CERT => false]);
});

it('maps mariadb verify to the resolved CA with verification on', function (): void {
    Storage::fake('local');
    Storage::disk('local')->put('certs/ca.pem', 'x');

    $config = (new DatabaseConnectionService(inDocker: false))
        ->buildConfig(makeConnection(DatabaseConnectionType::MariaDB, ssl: new SslConfig(SslMode::Verify, 'certs/ca.pem')), 'secret');

    expect($config['options'])->toBe([
        Mysql::ATTR_SSL_CA => Storage::disk('local')->path('certs/ca.pem'),
        Mysql::ATTR_SSL_VERIFY_SERVER_CERT => true,
    ]);
});

it('adds client certificate options for mysql mutual TLS', function (): void {
    Storage::fake('local');
    Storage::disk('local')->put('c.pem', 'x');
    Storage::disk('local')->put('k.pem', 'x');

    $config = (new DatabaseConnectionService(inDocker: false))
        ->buildConfig(makeConnection(DatabaseConnectionType::Mysql, ssl: new SslConfig(SslMode::Require, null, 'c.pem', 'k.pem')), 'secret');

    expect($config['options'])->toMatchArray([
        Mysql::ATTR_SSL_CERT => Storage::disk('local')->path('c.pem'),
        Mysql::ATTR_SSL_KEY => Storage::disk('local')->path('k.pem'),
    ]);
});

it('passes no options for mysql disable', function (): void {
    $config = (new DatabaseConnectionService(inDocker: false))
        ->buildConfig(makeConnection(DatabaseConnectionType::Mysql, ssl: new SslConfig(SslMode::Disable)), 'secret');

    expect($config)->not->toHaveKey('options');
});

it('maps pgsql modes to sslmode', function (SslMode $mode, string $sslmode): void {
    $config = (new DatabaseConnectionService(inDocker: false))
        ->buildConfig(makeConnection(DatabaseConnectionType::PostgreSQL, ssl: new SslConfig($mode)), 'secret');

    expect($config['sslmode'])->toBe($sslmode);
})->with([
    [SslMode::Disable, 'disable'],
    [SslMode::Require, 'require'],
    [SslMode::Verify, 'verify-full'],
]);

it('uses the resolved CA as a quoted sslrootcert for pgsql verify', function (): void {
    Storage::fake('local');
    Storage::disk('local')->put('certs/ca.pem', 'x');

    $config = (new DatabaseConnectionService(inDocker: false))
        ->buildConfig(makeConnection(DatabaseConnectionType::PostgreSQL, ssl: new SslConfig(SslMode::Verify, 'certs/ca.pem')), 'secret');

    $path = Storage::disk('local')->path('certs/ca.pem');
    expect($config['sslrootcert'])->toBe("'".$path."'");
});

it('falls back to the system trust store for pgsql verify without a CA', function (): void {
    $config = (new DatabaseConnectionService(inDocker: false))
        ->buildConfig(makeConnection(DatabaseConnectionType::PostgreSQL, ssl: new SslConfig(SslMode::Verify)), 'secret');

    // "system" is not a path, so it is never quoted (PRD-connection-tls I2).
    expect($config['sslrootcert'])->toBe('system');
});

it('never sets sslrootcert for pgsql require', function (): void {
    $config = (new DatabaseConnectionService(inDocker: false))
        ->buildConfig(makeConnection(DatabaseConnectionType::PostgreSQL, ssl: new SslConfig(SslMode::Require)), 'secret');

    expect($config)->not->toHaveKey('sslrootcert');
});

it('sets quoted sslcert and sslkey for pgsql mutual TLS', function (): void {
    Storage::fake('local');
    Storage::disk('local')->put('certs/ca.pem', 'x');
    Storage::disk('local')->put('certs/client.pem', 'x');
    Storage::disk('local')->put('certs/client.key', 'x');

    $config = (new DatabaseConnectionService(inDocker: false))->buildConfig(
        makeConnection(DatabaseConnectionType::PostgreSQL, ssl: new SslConfig(SslMode::Verify, 'certs/ca.pem', 'certs/client.pem', 'certs/client.key')),
        'secret'
    );

    expect($config['sslcert'])->toBe("'".Storage::disk('local')->path('certs/client.pem')."'")
        ->and($config['sslkey'])->toBe("'".Storage::disk('local')->path('certs/client.key')."'");
});

it('escapes a pgsql certificate path containing a space', function (): void {
    Storage::fake('local');
    Storage::disk('local')->put('certs/my ca.pem', 'x');

    $config = (new DatabaseConnectionService(inDocker: false))
        ->buildConfig(makeConnection(DatabaseConnectionType::PostgreSQL, ssl: new SslConfig(SslMode::Verify, 'certs/my ca.pem')), 'secret');

    $path = Storage::disk('local')->path('certs/my ca.pem');
    expect($config['sslrootcert'])->toBe("'".$path."'")
        ->and($config['sslrootcert'])->toContain(' ');
});

it('escapes a pgsql certificate path containing a single quote', function (): void {
    Storage::fake('local');
    Storage::disk('local')->put("certs/o'brien-ca.pem", 'x');

    $config = (new DatabaseConnectionService(inDocker: false))
        ->buildConfig(makeConnection(DatabaseConnectionType::PostgreSQL, ssl: new SslConfig(SslMode::Verify, "certs/o'brien-ca.pem")), 'secret');

    $path = Storage::disk('local')->path("certs/o'brien-ca.pem");
    expect($config['sslrootcert'])->toBe("'".str_replace("'", "\\'", $path)."'");
});

it('maps sqlsrv modes to encrypt and trust_server_certificate strings', function (SslMode $mode, array $expected): void {
    $config = (new DatabaseConnectionService(inDocker: false))
        ->buildConfig(makeConnection(DatabaseConnectionType::SqlServer, ssl: new SslConfig($mode)), 'secret');

    expect($config)->toMatchArray($expected);
})->with([
    'disable' => [SslMode::Disable, ['encrypt' => 'no']],
    'require' => [SslMode::Require, ['encrypt' => 'yes', 'trust_server_certificate' => 'yes']],
    'verify' => [SslMode::Verify, ['encrypt' => 'yes', 'trust_server_certificate' => 'no']],
]);

it('keeps the legacy sqlsrv trust flag when ssl is unset', function (): void {
    $config = (new DatabaseConnectionService(inDocker: false))
        ->buildConfig(makeConnection(DatabaseConnectionType::SqlServer, trustServerCertificate: true), 'secret');

    expect($config['trust_server_certificate'])->toBeTrue()
        ->and($config)->not->toHaveKey('encrypt');
});

it('lets ssl win over the legacy sqlsrv trust flag', function (): void {
    $config = (new DatabaseConnectionService(inDocker: false))
        ->buildConfig(makeConnection(DatabaseConnectionType::SqlServer, ssl: new SslConfig(SslMode::Verify), trustServerCertificate: true), 'secret');

    expect($config['trust_server_certificate'])->toBe('no');
});

it('fails before connecting when a certificate file is missing, naming the resolved path', function (): void {
    Storage::fake('local');
    $service = new DatabaseConnectionService(inDocker: false);
    $connection = makeConnection(DatabaseConnectionType::Mysql, ssl: new SslConfig(SslMode::Verify, 'certs/ca.pem'));

    expect(fn (): array => $service->buildConfig($connection, 'secret'))
        ->toThrow(RuntimeException::class, 'Certificate file not found: '.Storage::disk('local')->path('certs/ca.pem'));
});

it('rejects an ssl block that breaks the rules at connect time', function (): void {
    $service = new DatabaseConnectionService(inDocker: false);
    $connection = makeConnection(DatabaseConnectionType::Mysql, ssl: new SslConfig(SslMode::Verify));

    expect(fn (): array => $service->buildConfig($connection, 'secret'))
        ->toThrow(RuntimeException::class, 'Invalid ssl configuration for connection "test": mode verify requires a CA certificate for MySQL/MariaDB');
});

it('exposes the host actually dialled', function (): void {
    $connection = makeConnection(DatabaseConnectionType::Mysql, host: '127.0.0.1');

    expect((new DatabaseConnectionService(inDocker: true))->resolvedHost($connection))->toBe('host.docker.internal')
        ->and((new DatabaseConnectionService(inDocker: false))->resolvedHost($connection))->toBe('127.0.0.1');
});

// ── open() hints ─────────────────────────────────────────────────────────────

it('appends a hint and keeps the driver error when open() fails on a known TLS error', function (): void {
    $original = new PDOException('SQLSTATE[HY000] [3159] Connections using insecure transport are prohibited while --require_secure_transport=ON.');
    DB::shouldReceive('connection')->andReturnSelf();
    DB::shouldReceive('getPdo')->andThrow($original);
    DB::shouldReceive('purge')->once();

    try {
        (new DatabaseConnectionService(inDocker: false))->open(makeConnection(DatabaseConnectionType::Mysql));
        $this->fail('Expected exception');
    } catch (RuntimeException $runtimeException) {
        expect($runtimeException->getMessage())->toContain('[3159]')
            ->and($runtimeException->getMessage())->toContain('The server requires TLS.')
            ->and($runtimeException->getPrevious())->toBe($original);
    }
});

it('names the docker-rewritten host in the verify hint', function (): void {
    Storage::fake('local');
    Storage::disk('local')->put('ca.pem', 'x');
    DB::shouldReceive('connection')->andReturnSelf();
    DB::shouldReceive('getPdo')->andThrow(new PDOException('SQLSTATE[HY000] [2002] Cannot connect to MySQL using SSL'));
    DB::shouldReceive('purge');

    $connection = makeConnection(DatabaseConnectionType::Mysql, host: '127.0.0.1', ssl: new SslConfig(SslMode::Verify, 'ca.pem'));

    expect(fn (): string => (new DatabaseConnectionService(inDocker: true))->open($connection))
        ->toThrow(RuntimeException::class, 'the host name "host.docker.internal" is not in the certificate');
});

it('rethrows unrelated connection errors unchanged', function (): void {
    $original = new PDOException('SQLSTATE[HY000] [2002] Connection refused');
    DB::shouldReceive('connection')->andReturnSelf();
    DB::shouldReceive('getPdo')->andThrow($original);
    DB::shouldReceive('purge');

    expect(fn (): string => (new DatabaseConnectionService(inDocker: false))->open(makeConnection(DatabaseConnectionType::Mysql)))
        ->toThrow(PDOException::class, 'Connection refused');
});

// ── negotiatedCipher ─────────────────────────────────────────────────────────

it('reads the mysql session cipher', function (): void {
    DB::shouldReceive('connection')->with('c1')->andReturnSelf();
    DB::shouldReceive('selectOne')->andReturn((object) ['Variable_name' => 'Ssl_cipher', 'Value' => 'TLS_AES_256_GCM_SHA384']);

    expect((new DatabaseConnectionService(inDocker: false))->negotiatedCipher('c1', DatabaseConnectionType::Mysql))
        ->toBe('TLS_AES_256_GCM_SHA384');
});

it('reads the postgres cipher from pg_stat_ssl', function (): void {
    DB::shouldReceive('connection')->with('c1')->andReturnSelf();
    DB::shouldReceive('selectOne')->andReturn((object) ['cipher' => 'TLS_AES_128_GCM_SHA256']);

    expect((new DatabaseConnectionService(inDocker: false))->negotiatedCipher('c1', DatabaseConnectionType::PostgreSQL))
        ->toBe('TLS_AES_128_GCM_SHA256');
});

it('returns null when the connection is not encrypted or the query fails', function (): void {
    DB::shouldReceive('connection')->andReturnSelf();
    DB::shouldReceive('selectOne')->andReturn((object) ['Variable_name' => 'Ssl_cipher', 'Value' => ''], null);

    $service = new DatabaseConnectionService(inDocker: false);

    expect($service->negotiatedCipher('c1', DatabaseConnectionType::Mysql))->toBeNull()
        ->and($service->negotiatedCipher('c1', DatabaseConnectionType::PostgreSQL))->toBeNull()
        ->and($service->negotiatedCipher('c1', DatabaseConnectionType::SqlServer))->toBeNull();
});

it('reports SQL Server encryption without a cipher name, since SQL Server never exposes one', function (): void {
    DB::shouldReceive('connection')->with('c1')->andReturnSelf();
    DB::shouldReceive('selectOne')
        ->with('SELECT encrypt_option FROM sys.dm_exec_connections WHERE session_id = @@SPID')
        ->andReturn((object) ['encrypt_option' => 'TRUE']);

    expect((new DatabaseConnectionService(inDocker: false))->negotiatedCipher('c1', DatabaseConnectionType::SqlServer))
        ->toBe('encrypted (cipher not reported by SQL Server)');
});

it('returns null when SQL Server reports encrypt_option FALSE', function (): void {
    DB::shouldReceive('connection')->with('c1')->andReturnSelf();
    DB::shouldReceive('selectOne')
        ->with('SELECT encrypt_option FROM sys.dm_exec_connections WHERE session_id = @@SPID')
        ->andReturn((object) ['encrypt_option' => 'FALSE']);

    expect((new DatabaseConnectionService(inDocker: false))->negotiatedCipher('c1', DatabaseConnectionType::SqlServer))
        ->toBeNull();
});

it('returns null when the SQL Server encrypt_option query throws', function (): void {
    DB::shouldReceive('connection')->with('c1')->andReturnSelf();
    DB::shouldReceive('selectOne')->andThrow(new RuntimeException('connection lost'));

    expect((new DatabaseConnectionService(inDocker: false))->negotiatedCipher('c1', DatabaseConnectionType::SqlServer))
        ->toBeNull();
});
