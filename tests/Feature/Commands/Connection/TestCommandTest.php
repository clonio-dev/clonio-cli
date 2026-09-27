<?php

declare(strict_types=1);

use App\Data\ConnectionData;
use App\Data\SslConfig;
use App\Enums\DatabaseConnectionType;
use App\Enums\ExitCode;
use App\Enums\SslMode;
use App\Services\Config\ConfigService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

function makeMysqlConnection(string $name = 'staging', string $password = 'secret'): ConnectionData
{
    return new ConnectionData(
        name: $name,
        type: DatabaseConnectionType::Mysql,
        host: 'localhost',
        port: 3306,
        database: 'mydb',
        schema: null,
        username: 'root',
        password: $password,
        isProduction: false,
    );
}

function makePgsqlConnection(string $name = 'prod', ?SslConfig $ssl = null): ConnectionData
{
    return new ConnectionData(
        name: $name,
        type: DatabaseConnectionType::PostgreSQL,
        host: 'db',
        port: 5432,
        database: 'app',
        schema: 'public',
        username: 'root',
        password: 'secret',
        isProduction: false,
        ssl: $ssl,
    );
}

function makeSqliteConnection(string $name = 'local', string $path = ''): ConnectionData
{
    return new ConnectionData(
        name: $name,
        type: DatabaseConnectionType::Sqlite,
        host: null,
        port: null,
        database: $path !== '' ? $path : __FILE__,
        schema: null,
        username: null,
        password: '',
        isProduction: false,
    );
}

function makeDumpConnection(string $name = 'staging-dump', string $password = ''): ConnectionData
{
    return new ConnectionData(
        name: $name,
        type: DatabaseConnectionType::Dump,
        host: null,
        port: null,
        database: null,
        schema: null,
        username: null,
        password: $password,
        isProduction: false,
        dialect: DatabaseConnectionType::PostgreSQL,
    );
}

it('tests a dump connection without a PDO ping and reports the dialect', function (): void {
    $config = Mockery::mock(ConfigService::class);
    $config->shouldReceive('getConnection')->with('staging-dump')->andReturn(makeDumpConnection('staging-dump', 'secret'));
    $this->app->instance(ConfigService::class, $config);

    $this->artisan('connection:test', ['name' => 'staging-dump'])
        ->expectsOutputToContain('Dump connection "staging-dump" — dialect: pgsql')
        ->assertExitCode(ExitCode::Success->value);
});

it('reports AES-256 encryption for a password-protected dump connection', function (): void {
    $config = Mockery::mock(ConfigService::class);
    $config->shouldReceive('getConnection')->with('staging-dump')->andReturn(makeDumpConnection('staging-dump', 'secret'));
    $this->app->instance(ConfigService::class, $config);

    $this->artisan('connection:test', ['name' => 'staging-dump'])
        ->expectsOutputToContain('encryption: AES-256')
        ->assertExitCode(ExitCode::Success->value);
});

it('reports no encryption for a passwordless dump connection', function (): void {
    $config = Mockery::mock(ConfigService::class);
    $config->shouldReceive('getConnection')->with('plain-dump')->andReturn(makeDumpConnection('plain-dump'));
    $this->app->instance(ConfigService::class, $config);

    $this->artisan('connection:test', ['name' => 'plain-dump'])
        ->expectsOutputToContain('encryption: none')
        ->assertExitCode(ExitCode::Success->value);
});

it('tests a specific SQLite connection successfully', function (): void {
    // __FILE__ is a real, readable, writable file — no network needed
    $connection = makeSqliteConnection('local', __FILE__);

    $config = Mockery::mock(ConfigService::class);
    $config->shouldReceive('getConnection')->with('local')->andReturn($connection);

    $this->app->instance(ConfigService::class, $config);

    $this->artisan('connection:test', ['name' => 'local'])
        ->expectsOutputToContain('local: OK')
        ->assertExitCode(ExitCode::Success->value);
});

it('fails with exit code 3 when DB connection throws an exception', function (): void {
    $connection = makeMysqlConnection('staging');

    $config = Mockery::mock(ConfigService::class);
    $config->shouldReceive('getConnection')->with('staging')->andReturn($connection);

    $this->app->instance(ConfigService::class, $config);

    DB::shouldReceive('connection')->andReturnSelf();
    DB::shouldReceive('getPdo')->andThrow(new Exception('Connection refused'));
    DB::shouldReceive('purge');

    $this->artisan('connection:test', ['name' => 'staging'])
        ->expectsOutputToContain('FAILED')
        ->assertExitCode(ExitCode::ConnectionError->value);
});

it('fails with exit code 2 when the named connection is not found', function (): void {
    $config = Mockery::mock(ConfigService::class);
    $config->shouldReceive('getConnection')->with('unknown')->andReturn(null);

    $this->app->instance(ConfigService::class, $config);

    $this->artisan('connection:test', ['name' => 'unknown'])
        ->expectsOutputToContain("No connection named 'unknown' found.")
        ->assertExitCode(ExitCode::ConfigError->value);
});

it('fails with exit code 2 when no connections exist', function (): void {
    $config = Mockery::mock(ConfigService::class);
    $config->shouldReceive('getConnections')->andReturn([]);

    $this->app->instance(ConfigService::class, $config);

    $this->artisan('connection:test')
        ->expectsOutputToContain('No connections defined.')
        ->assertExitCode(ExitCode::ConfigError->value);
});

it('tests all connections and reports a summary', function (): void {
    $sqlite = makeSqliteConnection('local', __FILE__);
    $mysql = makeMysqlConnection('staging');

    $config = Mockery::mock(ConfigService::class);
    $config->shouldReceive('getConnections')->andReturn([
        'local' => $sqlite,
        'staging' => $mysql,
    ]);

    $this->app->instance(ConfigService::class, $config);

    DB::shouldReceive('connection')->andReturnSelf();
    DB::shouldReceive('getPdo')->andReturn(new PDO('sqlite::memory:'));
    DB::shouldReceive('purge');

    $this->artisan('connection:test')
        ->expectsOutputToContain('All 2 connections OK.')
        ->assertExitCode(ExitCode::Success->value);
});

it('reports failed connections in the summary and returns exit code 3', function (): void {
    // Use two SQLite connections: one valid path, one non-existent path
    $good = makeSqliteConnection('good', __FILE__);
    $bad = makeSqliteConnection('bad', '/nonexistent/path/db.sqlite');

    $config = Mockery::mock(ConfigService::class);
    $config->shouldReceive('getConnections')->andReturn([
        'good' => $good,
        'bad' => $bad,
    ]);

    $this->app->instance(ConfigService::class, $config);

    $this->artisan('connection:test')
        ->expectsOutputToContain('1 of 2 connections failed.')
        ->assertExitCode(ExitCode::ConnectionError->value);
});

it('suppresses table output in --ci mode but still outputs errors to stderr', function (): void {
    $connection = makeMysqlConnection('staging');

    $config = Mockery::mock(ConfigService::class);
    $config->shouldReceive('getConnections')->andReturn([
        'staging' => $connection,
    ]);

    $this->app->instance(ConfigService::class, $config);

    DB::shouldReceive('connection')->andReturnSelf();
    DB::shouldReceive('getPdo')->andThrow(new Exception('Connection refused'));
    DB::shouldReceive('purge');

    $this->artisan('connection:test', ['--ci' => true])
        ->expectsOutputToContain('FAILED')
        ->assertExitCode(ExitCode::ConnectionError->value);
});

it('fails when a SQLite file exists but is not readable', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'clonio_unreadable_');
    expect($path)->not->toBeFalse();
    chmod($path, 0o000);

    $connection = makeSqliteConnection('local', $path);

    $config = Mockery::mock(ConfigService::class);
    $config->shouldReceive('getConnection')->with('local')->andReturn($connection);
    $this->app->instance(ConfigService::class, $config);

    try {
        $this->artisan('connection:test', ['name' => 'local'])
            ->expectsOutputToContain('File not readable')
            ->assertExitCode(ExitCode::ConnectionError->value);
    } finally {
        chmod($path, 0o644);
        @unlink($path);
    }
})->skip(fn (): bool => posix_getuid() === 0, 'root bypasses file permission checks');

it('fails when a SQLite file is readable but not writable', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'clonio_readonly_');
    expect($path)->not->toBeFalse();
    chmod($path, 0o444);

    $connection = makeSqliteConnection('local', $path);

    $config = Mockery::mock(ConfigService::class);
    $config->shouldReceive('getConnection')->with('local')->andReturn($connection);
    $this->app->instance(ConfigService::class, $config);

    try {
        $this->artisan('connection:test', ['name' => 'local'])
            ->expectsOutputToContain('File not writable')
            ->assertExitCode(ExitCode::ConnectionError->value);
    } finally {
        chmod($path, 0o644);
        @unlink($path);
    }
})->skip(fn (): bool => posix_getuid() === 0, 'root bypasses file permission checks');

it('fails a dump connection when the working directory is not writable', function (): void {
    $dir = sys_get_temp_dir().'/clonio_ro_dir_'.uniqid();
    mkdir($dir, 0o555);
    $originalCwd = getcwd();
    chdir($dir);

    $config = Mockery::mock(ConfigService::class);
    $config->shouldReceive('getConnection')->with('staging-dump')->andReturn(makeDumpConnection('staging-dump'));
    $this->app->instance(ConfigService::class, $config);

    try {
        $this->artisan('connection:test', ['name' => 'staging-dump'])
            ->expectsOutputToContain('Working directory not writable')
            ->assertExitCode(ExitCode::IoError->value);
    } finally {
        if ($originalCwd !== false) {
            chdir($originalCwd);
        }

        chmod($dir, 0o755);
        rmdir($dir);
    }
})->skip(fn (): bool => posix_getuid() === 0, 'root bypasses directory permission checks');

it('fails with exit code 2 when APP_KEY is missing and password is encrypted', function (): void {
    // Use a raw 'encrypted:' prefix with garbage — decryption will fail since no APP_KEY
    $connection = makeMysqlConnection('staging', 'encrypted:notvalidciphertext');

    $config = Mockery::mock(ConfigService::class);
    $config->shouldReceive('getConnection')->with('staging')->andReturn($connection);

    $this->app->instance(ConfigService::class, $config);

    $this->artisan('connection:test', ['name' => 'staging'])
        ->expectsOutputToContain('FAILED')
        ->assertExitCode(ExitCode::ConfigError->value);
});

it('shows the transport mode on success', function (): void {
    $connection = new ConnectionData(
        name: 'staging', type: DatabaseConnectionType::Mysql, host: 'localhost', port: 3306, database: 'mydb',
        schema: null, username: 'root', password: 'secret', isProduction: false, ssl: new SslConfig(SslMode::Require),
    );
    $config = Mockery::mock(ConfigService::class);
    $config->shouldReceive('getConnection')->with('staging')->andReturn($connection);
    $this->app->instance(ConfigService::class, $config);

    DB::shouldReceive('connection')->andReturnSelf();
    DB::shouldReceive('getPdo')->andReturn(Mockery::mock(PDO::class));
    DB::shouldReceive('purge');

    $this->artisan('connection:test', ['name' => 'staging'])
        ->expectsOutputToContain('tls: require')
        ->assertExitCode(ExitCode::Success->value);
});

it('prints the negotiated cipher with -v', function (): void {
    $config = Mockery::mock(ConfigService::class);
    $config->shouldReceive('getConnection')->with('staging')->andReturn(makeMysqlConnection('staging'));
    $this->app->instance(ConfigService::class, $config);

    DB::shouldReceive('connection')->andReturnSelf();
    DB::shouldReceive('getPdo')->andReturn(Mockery::mock(PDO::class));
    DB::shouldReceive('selectOne')->andReturn((object) ['Variable_name' => 'Ssl_cipher', 'Value' => 'TLS_AES_256_GCM_SHA384']);
    DB::shouldReceive('purge');

    $this->artisan('connection:test', ['name' => 'staging', '-v' => true])
        ->expectsOutputToContain('TLS cipher: TLS_AES_256_GCM_SHA384')
        ->assertExitCode(ExitCode::Success->value);
});

it('shows the TLS hint when the server requires secure transport', function (): void {
    $config = Mockery::mock(ConfigService::class);
    $config->shouldReceive('getConnection')->with('staging')->andReturn(makeMysqlConnection('staging'));
    $this->app->instance(ConfigService::class, $config);

    DB::shouldReceive('connection')->andReturnSelf();
    DB::shouldReceive('getPdo')->andThrow(new PDOException('SQLSTATE[HY000] [3159] Connections using insecure transport are prohibited while --require_secure_transport=ON.'));
    DB::shouldReceive('purge');

    $this->artisan('connection:test', ['name' => 'staging'])
        ->expectsOutputToContain('The server requires TLS.')
        ->assertExitCode(ExitCode::ConnectionError->value);
});

it('fails with exit 3 before connecting when a certificate file is missing', function (): void {
    Storage::fake('local');
    $connection = new ConnectionData(
        name: 'staging', type: DatabaseConnectionType::Mysql, host: 'localhost', port: 3306, database: 'mydb',
        schema: null, username: 'root', password: 'secret', isProduction: false,
        ssl: new SslConfig(SslMode::Verify, 'certs/ca.pem'),
    );
    $config = Mockery::mock(ConfigService::class);
    $config->shouldReceive('getConnection')->with('staging')->andReturn($connection);
    $this->app->instance(ConfigService::class, $config);

    DB::shouldReceive('getPdo')->never();

    $this->artisan('connection:test', ['name' => 'staging'])
        ->expectsOutputToContain('Certificate file not found:')
        ->assertExitCode(ExitCode::ConnectionError->value);
});

it('shows a TLS column with the configured mode for each connection in the overview table', function (): void {
    $mysqlWithSsl = new ConnectionData(
        name: 'staging', type: DatabaseConnectionType::Mysql, host: 'localhost', port: 3306, database: 'mydb',
        schema: null, username: 'root', password: 'secret', isProduction: false, ssl: new SslConfig(SslMode::Require),
    );
    $pgsql = makePgsqlConnection('prod');
    $sqlite = makeSqliteConnection('local', __FILE__);

    $config = Mockery::mock(ConfigService::class);
    $config->shouldReceive('getConnections')->andReturn([
        'staging' => $mysqlWithSsl,
        'prod' => $pgsql,
        'local' => $sqlite,
    ]);
    $this->app->instance(ConfigService::class, $config);

    DB::shouldReceive('connection')->andReturnSelf();
    DB::shouldReceive('getPdo')->andReturn(Mockery::mock(PDO::class));
    DB::shouldReceive('purge');

    $this->artisan('connection:test')
        ->expectsOutputToContain('TLS')
        ->expectsOutputToContain('require')
        ->expectsOutputToContain('default')
        ->expectsOutputToContain('—')
        ->doesntExpectOutputToContain('Cipher')
        ->assertExitCode(ExitCode::Success->value);
});

it('shows a Cipher column with the negotiated cipher under -v, and does not query it otherwise', function (): void {
    $mysql = makeMysqlConnection('staging');
    $sqlite = makeSqliteConnection('local', __FILE__);

    $config = Mockery::mock(ConfigService::class);
    $config->shouldReceive('getConnections')->andReturn([
        'staging' => $mysql,
        'local' => $sqlite,
    ]);
    $this->app->instance(ConfigService::class, $config);

    DB::shouldReceive('connection')->andReturnSelf();
    DB::shouldReceive('getPdo')->andReturn(Mockery::mock(PDO::class));
    DB::shouldReceive('selectOne')->once()->andReturn((object) ['Variable_name' => 'Ssl_cipher', 'Value' => 'TLS_AES_256_GCM_SHA384']);
    DB::shouldReceive('purge');

    $this->artisan('connection:test', ['-v' => true])
        ->expectsOutputToContain('Cipher')
        ->expectsOutputToContain('TLS_AES_256_GCM_SHA384')
        ->assertExitCode(ExitCode::Success->value);
});

it('does not query the negotiated cipher for the overview table without -v', function (): void {
    $mysql = makeMysqlConnection('staging');

    $config = Mockery::mock(ConfigService::class);
    $config->shouldReceive('getConnections')->andReturn([
        'staging' => $mysql,
    ]);
    $this->app->instance(ConfigService::class, $config);

    DB::shouldReceive('connection')->andReturnSelf();
    DB::shouldReceive('getPdo')->andReturn(Mockery::mock(PDO::class));
    DB::shouldReceive('selectOne')->never();
    DB::shouldReceive('purge');

    $this->artisan('connection:test')
        ->doesntExpectOutputToContain('Cipher')
        ->assertExitCode(ExitCode::Success->value);
});

it('does not query the negotiated cipher for the overview table in --ci -v mode', function (): void {
    $mysql = makeMysqlConnection('staging');

    $config = Mockery::mock(ConfigService::class);
    $config->shouldReceive('getConnections')->andReturn([
        'staging' => $mysql,
    ]);
    $this->app->instance(ConfigService::class, $config);

    DB::shouldReceive('connection')->andReturnSelf();
    DB::shouldReceive('getPdo')->andReturn(Mockery::mock(PDO::class));
    DB::shouldReceive('selectOne')->never();
    DB::shouldReceive('purge');

    $this->artisan('connection:test', ['--ci' => true, '-v' => true])
        ->assertExitCode(ExitCode::Success->value);
});
