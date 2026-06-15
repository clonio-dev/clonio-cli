<?php

declare(strict_types=1);

use App\Data\ConnectionData;
use App\Enums\DatabaseConnectionType;
use App\Enums\ExitCode;
use App\Services\Config\ConfigService;
use Illuminate\Support\Facades\DB;

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
