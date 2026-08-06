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
        ->expectsQuestion('SSL Certificate Authority', null)
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
        ->expectsQuestion('SSL Certificate Authority', null)
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
        ->expectsQuestion('SSL Certificate Authority', null)
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
        ->expectsQuestion('SSL Certificate Authority', null)
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

it('renames a connection when the name changes', function (): void {
    $connection = makeUpdateConnection('staging');

    $config = Mockery::mock(ConfigService::class);
    $config->shouldReceive('getConnections')->andReturn(['staging' => $connection]);
    $config->shouldReceive('getConnection')->with('staging')->andReturn($connection);
    $config->shouldReceive('hasConnection')->with('renamed')->andReturn(false);
    $config->shouldReceive('renameConnection')
        ->once()
        ->withArgs(function (string $old, string $new, ConnectionData $data): bool {
            return $old === 'staging' && $new === 'renamed' && $data->name === 'renamed';
        });
    $config->shouldNotReceive('setConnection');

    $this->app->instance(ConfigService::class, $config);

    $this->artisan('connection:update', ['name' => 'staging'])
        ->expectsQuestion('Connection name', 'renamed')
        ->expectsQuestion('Database driver', 'mysql')
        ->expectsQuestion('Host', 'localhost')
        ->expectsQuestion('Port', '3306')
        ->expectsQuestion('Database', 'mydb')
        ->expectsQuestion('Username', 'root')
        ->expectsQuestion('Password (press Enter to keep current)', '')
        ->expectsQuestion('SSL Certificate Authority', null)
        ->expectsConfirmation('Is this a production connection?', 'no')
        ->expectsConfirmation('Save changes?', 'yes')
        ->expectsOutputToContain("Connection 'renamed' updated successfully.")
        ->assertExitCode(0);
});

it('returns an IO error when persisting the update throws', function (): void {
    $connection = makeUpdateConnection('staging');

    $config = Mockery::mock(ConfigService::class);
    $config->shouldReceive('getConnections')->andReturn(['staging' => $connection]);
    $config->shouldReceive('getConnection')->with('staging')->andReturn($connection);
    $config->shouldReceive('hasConnection')->with('staging')->andReturn(true);
    $config->shouldReceive('setConnection')->once()->andThrow(new RuntimeException('disk full'));

    $this->app->instance(ConfigService::class, $config);

    $this->artisan('connection:update', ['name' => 'staging'])
        ->expectsQuestion('Connection name', 'staging')
        ->expectsQuestion('Database driver', 'mysql')
        ->expectsQuestion('Host', 'localhost')
        ->expectsQuestion('Port', '3306')
        ->expectsQuestion('Database', 'mydb')
        ->expectsQuestion('Username', 'root')
        ->expectsQuestion('Password (press Enter to keep current)', '')
        ->expectsQuestion('SSL Certificate Authority', null)
        ->expectsConfirmation('Is this a production connection?', 'no')
        ->expectsConfirmation('Save changes?', 'yes')
        ->expectsOutputToContain('disk full')
        ->assertExitCode(5);
});

it('prompts the user to choose when multiple connections exist and no name is given', function (): void {
    $staging = makeUpdateConnection('staging');
    $production = makeUpdateConnection('production');

    $config = Mockery::mock(ConfigService::class);
    $config->shouldReceive('getConnections')->andReturn([
        'staging' => $staging,
        'production' => $production,
    ]);
    $config->shouldReceive('getConnection')->with('staging')->andReturn($staging);
    $config->shouldReceive('hasConnection')->with('staging')->andReturn(true);
    $config->shouldReceive('setConnection')->once();

    $this->app->instance(ConfigService::class, $config);

    $this->artisan('connection:update')
        ->expectsChoice('Which connection do you want to update?', 'staging', ['staging', 'production'])
        ->expectsQuestion('Connection name', 'staging')
        ->expectsQuestion('Database driver', 'mysql')
        ->expectsQuestion('Host', 'localhost')
        ->expectsQuestion('Port', '3306')
        ->expectsQuestion('Database', 'mydb')
        ->expectsQuestion('Username', 'root')
        ->expectsQuestion('Password (press Enter to keep current)', '')
        ->expectsQuestion('SSL Certificate Authority', null)
        ->expectsConfirmation('Is this a production connection?', 'no')
        ->expectsConfirmation('Save changes?', 'yes')
        ->assertExitCode(0);
});

it('uses default prompts when changing the driver type to another network database', function (): void {
    config(['app.key' => 'base64:ROzyPViGEkER6n3g0OHblde5CygEIcuDlAFbca99xvM=']);

    $connection = makeUpdateConnection('staging');

    $config = Mockery::mock(ConfigService::class);
    $config->shouldReceive('getConnections')->andReturn(['staging' => $connection]);
    $config->shouldReceive('getConnection')->with('staging')->andReturn($connection);
    $config->shouldReceive('hasConnection')->with('staging')->andReturn(true);
    $config->shouldReceive('setConnection')
        ->once()
        ->withArgs(function (string $name, ConnectionData $data): bool {
            return $data->type === DatabaseConnectionType::PostgreSQL
                && $data->schema === 'public';
        });

    $this->app->instance(ConfigService::class, $config);

    // Switch mysql -> pgsql so typeChanged is true and the schema prompt appears.
    $this->artisan('connection:update', ['name' => 'staging'])
        ->expectsQuestion('Connection name', 'staging')
        ->expectsQuestion('Database driver', 'pgsql')
        ->expectsQuestion('Host', 'pg.example.com')
        ->expectsQuestion('Port', '5432')
        ->expectsQuestion('Database', 'pgdb')
        ->expectsQuestion('Username', 'postgres')
        ->expectsQuestion('Schema', 'public')
        ->expectsQuestion('Password (press Enter to keep current)', 'newsecret')
        ->expectsConfirmation('Is this a production connection?', 'no')
        ->expectsConfirmation('Save changes?', 'yes')
        ->assertExitCode(0);
});

it('updates to a SQLite connection asking only for the file path', function (): void {
    $connection = makeUpdateConnection('staging');

    $config = Mockery::mock(ConfigService::class);
    $config->shouldReceive('getConnections')->andReturn(['staging' => $connection]);
    $config->shouldReceive('getConnection')->with('staging')->andReturn($connection);
    $config->shouldReceive('hasConnection')->with('staging')->andReturn(true);
    $config->shouldReceive('setConnection')
        ->once()
        ->withArgs(function (string $name, ConnectionData $data): bool {
            return $data->type === DatabaseConnectionType::Sqlite
                && $data->database === '/tmp/new.db'
                && $data->host === null
                && $data->username === null;
        });

    $this->app->instance(ConfigService::class, $config);

    // Switch mysql -> sqlite (typeChanged true, non-network branch)
    $this->artisan('connection:update', ['name' => 'staging'])
        ->expectsQuestion('Connection name', 'staging')
        ->expectsQuestion('Database driver', 'sqlite')
        ->expectsQuestion('Database file path', '/tmp/new.db')
        ->expectsQuestion('Password (press Enter to keep current)', '')
        ->expectsConfirmation('Is this a production connection?', 'no')
        ->expectsConfirmation('Save changes?', 'yes')
        ->assertExitCode(0);
});

it('keeps the existing SQLite file path when the type is unchanged', function (): void {
    $sqlite = new ConnectionData(
        name: 'local',
        type: DatabaseConnectionType::Sqlite,
        host: null,
        port: null,
        database: '/tmp/existing.db',
        schema: null,
        username: null,
        password: '',
        isProduction: false,
    );

    $config = Mockery::mock(ConfigService::class);
    $config->shouldReceive('getConnections')->andReturn(['local' => $sqlite]);
    $config->shouldReceive('getConnection')->with('local')->andReturn($sqlite);
    $config->shouldReceive('hasConnection')->with('local')->andReturn(true);
    $config->shouldReceive('setConnection')
        ->once()
        ->withArgs(function (string $name, ConnectionData $data): bool {
            return $data->database === '/tmp/existing.db';
        });

    $this->app->instance(ConfigService::class, $config);

    $this->artisan('connection:update', ['name' => 'local'])
        ->expectsQuestion('Connection name', 'local')
        ->expectsQuestion('Database driver', 'sqlite')
        ->expectsQuestion('Database file path', '/tmp/existing.db')
        ->expectsQuestion('Password (press Enter to keep current)', '')
        ->expectsConfirmation('Is this a production connection?', 'no')
        ->expectsConfirmation('Save changes?', 'yes')
        ->assertExitCode(0);
});

it('prompts for trust server certificate when updating a SQL Server connection', function (): void {
    $mssql = new ConnectionData(
        name: 'mssql',
        type: DatabaseConnectionType::SqlServer,
        host: 'localhost',
        port: 1433,
        database: 'mydb',
        schema: null,
        username: 'sa',
        password: 'encrypted:abc123',
        isProduction: false,
        trustServerCertificate: false,
    );

    $config = Mockery::mock(ConfigService::class);
    $config->shouldReceive('getConnections')->andReturn(['mssql' => $mssql]);
    $config->shouldReceive('getConnection')->with('mssql')->andReturn($mssql);
    $config->shouldReceive('hasConnection')->with('mssql')->andReturn(true);
    $config->shouldReceive('setConnection')
        ->once()
        ->withArgs(function (string $name, ConnectionData $data): bool {
            return $data->trustServerCertificate === true;
        });

    $this->app->instance(ConfigService::class, $config);

    $this->artisan('connection:update', ['name' => 'mssql'])
        ->expectsQuestion('Connection name', 'mssql')
        ->expectsQuestion('Database driver', 'sqlsrv')
        ->expectsQuestion('Host', 'localhost')
        ->expectsQuestion('Port', '1433')
        ->expectsQuestion('Database', 'mydb')
        ->expectsQuestion('Username', 'sa')
        ->expectsQuestion('Password (press Enter to keep current)', '')
        ->expectsConfirmation('Is this a production connection?', 'no')
        ->expectsConfirmation('Trust server certificate? (required for self-signed certs)', 'yes')
        ->expectsConfirmation('Save changes?', 'yes')
        ->assertExitCode(0);
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
        ->expectsQuestion('SSL Certificate Authority', null)
        ->expectsConfirmation('Is this a production connection?', 'no')
        ->expectsOutputToContain("A connection named 'production' already exists.")
        ->assertExitCode(4);
});
