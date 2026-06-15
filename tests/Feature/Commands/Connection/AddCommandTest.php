<?php

declare(strict_types=1);

use App\Services\Config\ConfigService;
use Illuminate\Support\Facades\Crypt;

beforeEach(function (): void {
    config(['app.key' => 'base64:ROzyPViGEkER6n3g0OHblde5CygEIcuDlAFbca99xvM=']);
});

function fakeConfig(bool $hasConnection = false): ConfigService
{
    $mock = Mockery::mock(ConfigService::class);
    $mock->shouldReceive('hasConnection')->andReturn($hasConnection);
    if (! $hasConnection) {
        $mock->shouldReceive('setConnection')->once();
    }

    return $mock;
}

function fakeConfigReadOnly(): ConfigService
{
    $mock = Mockery::mock(ConfigService::class);
    $mock->shouldReceive('hasConnection')->andReturn(false);
    $mock->shouldNotReceive('setConnection');

    return $mock;
}

it('successfully adds a MySQL connection with all options provided via flags', function (): void {
    $this->app->instance(ConfigService::class, fakeConfig());

    $this->artisan('connection:add', [
        'name' => 'my_db',
        '--type' => 'mysql',
        '--host' => 'localhost',
        '--port' => '3306',
        '--database' => 'mydb',
        '--username' => 'root',
        '--password' => 'secret',
    ])
        ->expectsConfirmation('Is this a production connection?', 'no')
        ->expectsConfirmation('Save this connection?', 'yes')
        ->assertExitCode(0);
});

it('successfully adds a SQLite connection', function (): void {
    $this->app->instance(ConfigService::class, fakeConfig());

    $this->artisan('connection:add', [
        'name' => 'local_sqlite',
        '--type' => 'sqlite',
        '--database' => '/tmp/test.db',
    ])
        ->expectsConfirmation('Is this a production connection?', 'no')
        ->expectsConfirmation('Save this connection?', 'yes')
        ->assertExitCode(0);
});

it('fails when the connection name already exists', function (): void {
    $this->app->instance(ConfigService::class, fakeConfig(hasConnection: true));

    $this->artisan('connection:add', [
        'name' => 'existing_conn',
        '--type' => 'mysql',
        '--host' => 'localhost',
        '--port' => '3306',
        '--database' => 'mydb',
        '--username' => 'root',
        '--password' => 'secret',
    ])
        ->expectsOutputToContain("A connection named 'existing_conn' already exists.")
        ->assertExitCode(4);
});

it('fails with an invalid name format', function (): void {
    $this->app->instance(ConfigService::class, fakeConfigReadOnly());

    $this->artisan('connection:add', [
        'name' => 'Invalid Name',
        '--type' => 'mysql',
        '--host' => 'localhost',
        '--port' => '3306',
        '--database' => 'mydb',
        '--username' => 'root',
        '--password' => 'secret',
    ])
        ->expectsOutputToContain('Invalid connection name.')
        ->assertExitCode(4);
});

it('fails with an invalid port number', function (): void {
    $this->app->instance(ConfigService::class, fakeConfigReadOnly());

    $this->artisan('connection:add', [
        'name' => 'my_db',
        '--type' => 'mysql',
        '--host' => 'localhost',
        '--database' => 'mydb',
        '--username' => 'root',
        '--password' => 'secret',
    ])
        ->expectsQuestion('Port', '99999')
        ->expectsOutputToContain('Invalid port.')
        ->assertExitCode(4);
});

it('adds a dump connection with dialect and ZIP password via flags', function (): void {
    $this->app->instance(ConfigService::class, fakeConfig());

    $this->artisan('connection:add', [
        'name' => 'staging-dump',
        '--type' => 'dump',
        '--dialect' => 'pgsql',
        '--password' => 'zippw',
    ])
        ->expectsConfirmation('Is this a production connection?', 'no')
        ->expectsConfirmation('Save this connection?', 'yes')
        ->assertExitCode(0);
});

it('adds an unencrypted dump connection when the ZIP password is left blank', function (): void {
    $this->app->instance(ConfigService::class, fakeConfig());

    $this->artisan('connection:add', [
        'name' => 'plain-dump',
        '--type' => 'dump',
        '--dialect' => 'sqlite',
    ])
        ->expectsQuestion('ZIP archive password (leave blank for no encryption)', '')
        ->expectsConfirmation('Is this a production connection?', 'no')
        ->expectsConfirmation('Save this connection?', 'yes')
        ->assertExitCode(0);
});

it('fails with an invalid dump dialect', function (): void {
    $this->app->instance(ConfigService::class, fakeConfigReadOnly());

    $this->artisan('connection:add', [
        'name' => 'bad-dump',
        '--type' => 'dump',
        '--dialect' => 'oracle',
    ])
        ->expectsOutputToContain('Unknown dialect')
        ->assertExitCode(4);
});

it('cancels when the user declines the save confirmation', function (): void {
    $mock = Mockery::mock(ConfigService::class);
    $mock->shouldReceive('hasConnection')->andReturn(false);
    $mock->shouldNotReceive('setConnection');
    $this->app->instance(ConfigService::class, $mock);

    $this->artisan('connection:add', [
        'name' => 'my_db',
        '--type' => 'mysql',
        '--host' => 'localhost',
        '--port' => '3306',
        '--database' => 'mydb',
        '--username' => 'root',
        '--password' => 'secret',
    ])
        ->expectsConfirmation('Is this a production connection?', 'no')
        ->expectsConfirmation('Save this connection?', 'no')
        ->expectsOutputToContain('Cancelled.')
        ->assertExitCode(0);
});

it('prompts interactively for every MySQL field when no flags are given', function (): void {
    $this->app->instance(ConfigService::class, fakeConfig());

    $this->artisan('connection:add')
        ->expectsQuestion('Connection name', 'my_db')
        ->expectsQuestion('Database driver', 'mysql')
        ->expectsQuestion('Host', 'localhost')
        ->expectsQuestion('Port', '3306')
        ->expectsQuestion('Database name', 'mydb')
        ->expectsQuestion('Username', 'root')
        ->expectsQuestion('Password', 'secret')
        ->expectsConfirmation('Is this a production connection?', 'no')
        ->expectsConfirmation('Save this connection?', 'yes')
        ->assertExitCode(0);
});

it('fails with an unknown driver type', function (): void {
    $this->app->instance(ConfigService::class, fakeConfigReadOnly());

    $this->artisan('connection:add', [
        'name' => 'my_db',
        '--type' => 'oracle',
    ])
        ->expectsOutputToContain('Unknown driver type')
        ->assertExitCode(4);
});

it('prompts interactively for the SQLite database file path', function (): void {
    $this->app->instance(ConfigService::class, fakeConfig());

    $this->artisan('connection:add', [
        'name' => 'local_sqlite',
        '--type' => 'sqlite',
    ])
        ->expectsQuestion('Database file path', '/tmp/test.db')
        ->expectsConfirmation('Is this a production connection?', 'no')
        ->expectsConfirmation('Save this connection?', 'yes')
        ->assertExitCode(0);
});

it('prompts interactively for the dump dialect when no flag is given', function (): void {
    $this->app->instance(ConfigService::class, fakeConfig());

    $this->artisan('connection:add', [
        'name' => 'staging-dump',
        '--type' => 'dump',
    ])
        ->expectsQuestion('Target SQL dialect', 'mysql')
        ->expectsQuestion('ZIP archive password (leave blank for no encryption)', '')
        ->expectsConfirmation('Is this a production connection?', 'no')
        ->expectsConfirmation('Save this connection?', 'yes')
        ->assertExitCode(0);
});

it('prompts for the schema on a PostgreSQL connection', function (): void {
    $this->app->instance(ConfigService::class, fakeConfig());

    $this->artisan('connection:add', [
        'name' => 'pg_db',
        '--type' => 'pgsql',
        '--host' => 'localhost',
        '--port' => '5432',
        '--database' => 'mydb',
        '--username' => 'postgres',
        '--password' => 'secret',
    ])
        ->expectsQuestion('Schema', 'public')
        ->expectsConfirmation('Is this a production connection?', 'no')
        ->expectsConfirmation('Save this connection?', 'yes')
        ->expectsOutputToContain('public')
        ->assertExitCode(0);
});

it('prompts for trust server certificate on a SQL Server connection', function (): void {
    $this->app->instance(ConfigService::class, fakeConfig());

    $this->artisan('connection:add', [
        'name' => 'mssql_db',
        '--type' => 'sqlsrv',
        '--host' => 'localhost',
        '--port' => '1433',
        '--database' => 'mydb',
        '--username' => 'sa',
        '--password' => 'secret',
    ])
        ->expectsConfirmation('Trust server certificate? (required for self-signed certs)', 'yes')
        ->expectsConfirmation('Is this a production connection?', 'no')
        ->expectsConfirmation('Save this connection?', 'yes')
        ->expectsOutputToContain('Trust certificate')
        ->assertExitCode(0);
});

it('shows a production warning when the connection is marked production', function (): void {
    $this->app->instance(ConfigService::class, fakeConfig());

    $this->artisan('connection:add', [
        'name' => 'prod_db',
        '--type' => 'mysql',
        '--host' => 'localhost',
        '--port' => '3306',
        '--database' => 'mydb',
        '--username' => 'root',
        '--password' => 'secret',
        '--production' => true,
    ])
        ->expectsOutputToContain('This connection is marked as production.')
        ->expectsConfirmation('Save this connection?', 'yes')
        ->assertExitCode(0);
});

it('returns a config error when password encryption fails', function (): void {
    $this->app->instance(ConfigService::class, fakeConfigReadOnly());

    Crypt::shouldReceive('encryptString')->andThrow(new RuntimeException('no key'));

    $this->artisan('connection:add', [
        'name' => 'my_db',
        '--type' => 'mysql',
        '--host' => 'localhost',
        '--port' => '3306',
        '--database' => 'mydb',
        '--username' => 'root',
        '--password' => 'secret',
    ])
        ->expectsOutputToContain('Failed to encrypt password.')
        ->assertExitCode(2);
});

it('returns a config error when dump ZIP password encryption fails', function (): void {
    $this->app->instance(ConfigService::class, fakeConfigReadOnly());

    Crypt::shouldReceive('encryptString')->andThrow(new RuntimeException('no key'));

    $this->artisan('connection:add', [
        'name' => 'dump_db',
        '--type' => 'dump',
        '--dialect' => 'mysql',
        '--password' => 'zippw',
    ])
        ->expectsOutputToContain('Failed to encrypt password.')
        ->assertExitCode(2);
});

it('returns an IO error when persisting the connection throws', function (): void {
    $mock = Mockery::mock(ConfigService::class);
    $mock->shouldReceive('hasConnection')->andReturn(false);
    $mock->shouldReceive('setConnection')->once()->andThrow(new RuntimeException('disk full'));
    $this->app->instance(ConfigService::class, $mock);

    $this->artisan('connection:add', [
        'name' => 'my_db',
        '--type' => 'mysql',
        '--host' => 'localhost',
        '--port' => '3306',
        '--database' => 'mydb',
        '--username' => 'root',
        '--password' => 'secret',
    ])
        ->expectsConfirmation('Is this a production connection?', 'no')
        ->expectsConfirmation('Save this connection?', 'yes')
        ->expectsOutputToContain('Failed to save connection: disk full')
        ->assertExitCode(5);
});
