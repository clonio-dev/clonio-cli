<?php

declare(strict_types=1);

use App\Services\Config\ConfigService;

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
