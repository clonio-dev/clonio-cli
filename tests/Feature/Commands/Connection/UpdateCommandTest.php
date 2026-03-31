<?php

declare(strict_types=1);

use App\Data\ConnectionData;
use App\Enums\DatabaseConnectionType;
use App\Services\Config\ConfigService;

function makeUpdateConnection(string $name = 'staging'): ConnectionData
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
        isProduction: false,
    );
}

it('updates a connection when found', function (): void {
    $connection = makeUpdateConnection('staging');

    $config = Mockery::mock(ConfigService::class);
    $config->shouldReceive('getConnections')->andReturn(['staging' => $connection]);
    $config->shouldReceive('getConnection')->with('staging')->andReturn($connection);
    $config->shouldReceive('hasConnection')->with('staging')->andReturn(true);
    $config->shouldReceive('setConnection')
        ->once()
        ->withArgs(function (string $name, ConnectionData $data): bool {
            return $name === 'staging'
                && $data->host === 'db.example.com'
                && $data->port === 3306
                && $data->database === 'mydb'
                && $data->username === 'root'
                && $data->password === 'encrypted:abc123';
        });

    $this->app->instance(ConfigService::class, $config);

    $this->artisan('connection:update', ['name' => 'staging'])
        ->expectsQuestion('Connection name', 'staging')
        ->expectsQuestion('Database driver', 'mysql')
        ->expectsQuestion('Host', 'db.example.com')
        ->expectsQuestion('Port', '3306')
        ->expectsQuestion('Database', 'mydb')
        ->expectsQuestion('Username', 'root')
        ->expectsQuestion('Password (press Enter to keep current)', '')
        ->expectsConfirmation('Is this a production connection?', 'no')
        ->expectsConfirmation('Save changes?', 'yes')
        ->assertExitCode(0);
});

it('cancels update when user declines save confirmation', function (): void {
    $connection = makeUpdateConnection('staging');

    $config = Mockery::mock(ConfigService::class);
    $config->shouldReceive('getConnections')->andReturn(['staging' => $connection]);
    $config->shouldReceive('getConnection')->with('staging')->andReturn($connection);
    $config->shouldNotReceive('setConnection');
    $config->shouldNotReceive('renameConnection');

    $this->app->instance(ConfigService::class, $config);

    $this->artisan('connection:update', ['name' => 'staging'])
        ->expectsQuestion('Connection name', 'staging')
        ->expectsQuestion('Database driver', 'mysql')
        ->expectsQuestion('Host', 'localhost')
        ->expectsQuestion('Port', '3306')
        ->expectsQuestion('Database', 'mydb')
        ->expectsQuestion('Username', 'root')
        ->expectsQuestion('Password (press Enter to keep current)', '')
        ->expectsConfirmation('Is this a production connection?', 'no')
        ->expectsConfirmation('Save changes?', 'no')
        ->assertExitCode(0);
});

it('fails when connection not found', function (): void {
    $config = Mockery::mock(ConfigService::class);
    $config->shouldReceive('getConnections')->andReturn(['staging' => makeUpdateConnection('staging')]);
    $config->shouldReceive('getConnection')->with('production')->andReturn(null);

    $this->app->instance(ConfigService::class, $config);

    $this->artisan('connection:update', ['name' => 'production'])
        ->expectsOutputToContain('No connection named production found.')
        ->assertExitCode(2);
});

it('preserves existing password when empty input given', function (): void {
    $connection = makeUpdateConnection('staging');

    $config = Mockery::mock(ConfigService::class);
    $config->shouldReceive('getConnections')->andReturn(['staging' => $connection]);
    $config->shouldReceive('getConnection')->with('staging')->andReturn($connection);
    $config->shouldReceive('hasConnection')->with('staging')->andReturn(true);
    $config->shouldReceive('setConnection')
        ->once()
        ->withArgs(function (string $name, ConnectionData $data): bool {
            return $name === 'staging'
                && $data->password === 'encrypted:abc123';
        });

    $this->app->instance(ConfigService::class, $config);

    $this->artisan('connection:update', ['name' => 'staging'])
        ->expectsQuestion('Connection name', 'staging')
        ->expectsQuestion('Database driver', 'mysql')
        ->expectsQuestion('Host', 'localhost')
        ->expectsQuestion('Port', '3306')
        ->expectsQuestion('Database', 'mydb')
        ->expectsQuestion('Username', 'root')
        ->expectsQuestion('Password (press Enter to keep current)', '')
        ->expectsConfirmation('Is this a production connection?', 'no')
        ->expectsConfirmation('Save changes?', 'yes')
        ->assertExitCode(0);
});

it('auto-selects connection when only one exists', function (): void {
    $connection = makeUpdateConnection('staging');

    $config = Mockery::mock(ConfigService::class);
    $config->shouldReceive('getConnections')->andReturn(['staging' => $connection]);
    $config->shouldReceive('getConnection')->with('staging')->andReturn($connection);
    $config->shouldReceive('hasConnection')->with('staging')->andReturn(true);
    $config->shouldReceive('setConnection')->once();

    $this->app->instance(ConfigService::class, $config);

    // No name argument — should auto-select 'staging'
    $this->artisan('connection:update')
        ->expectsQuestion('Connection name', 'staging')
        ->expectsQuestion('Database driver', 'mysql')
        ->expectsQuestion('Host', 'localhost')
        ->expectsQuestion('Port', '3306')
        ->expectsQuestion('Database', 'mydb')
        ->expectsQuestion('Username', 'root')
        ->expectsQuestion('Password (press Enter to keep current)', '')
        ->expectsConfirmation('Is this a production connection?', 'no')
        ->expectsConfirmation('Save changes?', 'yes')
        ->assertExitCode(0);
});

it('fails with config error when no connections exist and no name given', function (): void {
    $config = Mockery::mock(ConfigService::class);
    $config->shouldReceive('getConnections')->andReturn([]);

    $this->app->instance(ConfigService::class, $config);

    $this->artisan('connection:update')
        ->expectsOutputToContain('No connections found in clonio.json.')
        ->assertExitCode(2);
});

it('fails with validation error when renaming to an existing connection name', function (): void {
    $connection = makeUpdateConnection('staging');

    $config = Mockery::mock(ConfigService::class);
    $config->shouldReceive('getConnections')->andReturn(['staging' => $connection]);
    $config->shouldReceive('getConnection')->with('staging')->andReturn($connection);
    $config->shouldReceive('hasConnection')->with('production')->andReturn(true);
    $config->shouldNotReceive('setConnection');
    $config->shouldNotReceive('renameConnection');

    $this->app->instance(ConfigService::class, $config);

    $this->artisan('connection:update', ['name' => 'staging'])
        ->expectsQuestion('Connection name', 'production')
        ->expectsQuestion('Database driver', 'mysql')
        ->expectsQuestion('Host', 'localhost')
        ->expectsQuestion('Port', '3306')
        ->expectsQuestion('Database', 'mydb')
        ->expectsQuestion('Username', 'root')
        ->expectsQuestion('Password (press Enter to keep current)', '')
        ->expectsConfirmation('Is this a production connection?', 'no')
        ->expectsOutputToContain("A connection named 'production' already exists.")
        ->assertExitCode(4);
});
