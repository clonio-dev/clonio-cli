<?php

declare(strict_types=1);

use App\Data\ConnectionData;
use App\Enums\DatabaseConnectionType;
use App\Services\Config\ConfigService;

function makeDeleteConnection(string $name = 'staging', bool $isProduction = false): ConnectionData
{
    return new ConnectionData(
        name: $name,
        type: DatabaseConnectionType::Mysql,
        host: 'localhost',
        port: 3306,
        database: 'mydb',
        schema: null,
        username: 'root',
        password: 'encrypted:abc123',
        isProduction: $isProduction,
    );
}

it('deletes connection after user confirms', function (): void {
    $connection = makeDeleteConnection('staging');

    $config = Mockery::mock(ConfigService::class);
    $config->shouldReceive('getConnections')->andReturn(['staging' => $connection]);
    $config->shouldReceive('getConnection')->with('staging')->andReturn($connection);
    $config->shouldReceive('deleteConnection')->with('staging')->once();

    $this->app->instance(ConfigService::class, $config);

    $this->artisan('connection:delete', ['name' => 'staging'])
        ->expectsConfirmation('Delete this connection?', 'yes')
        ->expectsOutputToContain('Connection staging deleted.')
        ->assertExitCode(0);
});

it('cancels when user declines confirmation', function (): void {
    $connection = makeDeleteConnection('staging');

    $config = Mockery::mock(ConfigService::class);
    $config->shouldReceive('getConnections')->andReturn(['staging' => $connection]);
    $config->shouldReceive('getConnection')->with('staging')->andReturn($connection);
    $config->shouldReceive('deleteConnection')->never();

    $this->app->instance(ConfigService::class, $config);

    $this->artisan('connection:delete', ['name' => 'staging'])
        ->expectsConfirmation('Delete this connection?', 'no')
        ->expectsOutputToContain('Cancelled.')
        ->assertExitCode(0);
});

it('skips confirmation and deletes immediately with --force', function (): void {
    $connection = makeDeleteConnection('staging');

    $config = Mockery::mock(ConfigService::class);
    $config->shouldReceive('getConnections')->andReturn(['staging' => $connection]);
    $config->shouldReceive('getConnection')->with('staging')->andReturn($connection);
    $config->shouldReceive('deleteConnection')->with('staging')->once();

    $this->app->instance(ConfigService::class, $config);

    $this->artisan('connection:delete', ['name' => 'staging', '--force' => true])
        ->expectsOutputToContain('Connection staging deleted.')
        ->assertExitCode(0);
});

it('fails when connection not found', function (): void {
    $config = Mockery::mock(ConfigService::class);
    $config->shouldReceive('getConnections')->andReturn(['staging' => makeDeleteConnection('staging')]);
    $config->shouldReceive('getConnection')->with('unknown')->andReturn(null);
    $config->shouldReceive('deleteConnection')->never();

    $this->app->instance(ConfigService::class, $config);

    $this->artisan('connection:delete', ['name' => 'unknown'])
        ->expectsOutputToContain('No connection named unknown found.')
        ->assertExitCode(2);
});

it('shows production warning when connection is marked as production', function (): void {
    $connection = makeDeleteConnection('prod', isProduction: true);

    $config = Mockery::mock(ConfigService::class);
    $config->shouldReceive('getConnections')->andReturn(['prod' => $connection]);
    $config->shouldReceive('getConnection')->with('prod')->andReturn($connection);
    $config->shouldReceive('deleteConnection')->with('prod')->once();

    $this->app->instance(ConfigService::class, $config);

    $this->artisan('connection:delete', ['name' => 'prod'])
        ->expectsOutputToContain('Warning: This is a production connection!')
        ->expectsConfirmation('Delete this connection?', 'yes')
        ->expectsOutputToContain('Connection prod deleted.')
        ->assertExitCode(0);
});

it('auto-selects the only connection when no name is given', function (): void {
    $connection = makeDeleteConnection('staging');

    $config = Mockery::mock(ConfigService::class);
    $config->shouldReceive('getConnections')->andReturn(['staging' => $connection]);
    $config->shouldReceive('getConnection')->with('staging')->andReturn($connection);
    $config->shouldReceive('deleteConnection')->with('staging')->once();

    $this->app->instance(ConfigService::class, $config);

    $this->artisan('connection:delete', ['--force' => true])
        ->expectsOutputToContain('Connection staging deleted.')
        ->assertExitCode(0);
});

it('errors when no connections exist', function (): void {
    $config = Mockery::mock(ConfigService::class);
    $config->shouldReceive('getConnections')->andReturn([]);

    $this->app->instance(ConfigService::class, $config);

    $this->artisan('connection:delete')
        ->expectsOutputToContain('No connections found.')
        ->assertExitCode(2);
});
