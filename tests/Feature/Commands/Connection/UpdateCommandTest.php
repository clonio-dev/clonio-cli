<?php

declare(strict_types=1);

use App\Data\ConnectionData;
use App\Data\SslConfig;
use App\Enums\DatabaseConnectionType;
use App\Enums\SslMode;
use App\Services\Config\ConfigService;
use Illuminate\Support\Facades\Storage;

function makeUpdateConnection(string $name = 'staging', ?SslConfig $ssl = null): ConnectionData
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
        ssl: $ssl,
    );
}

/** Config mock for "update staging" that asserts the saved connection. */
function fakeUpdateConfig(ConnectionData $current, Closure $check): ConfigService
{
    $config = Mockery::mock(ConfigService::class);
    $config->shouldReceive('getConnections')->andReturn([$current->name => $current]);
    $config->shouldReceive('getConnection')->with($current->name)->andReturn($current);
    $config->shouldReceive('hasConnection')->andReturn(true);
    $config->shouldReceive('setConnection')->once()->withArgs(
        static fn (string $name, ConnectionData $data): bool => (bool) $check($data)
    );

    return $config;
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
        ->expectsQuestion('Transport security', 'Driver default')
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
        ->expectsQuestion('Transport security', 'Driver default')
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
        ->expectsQuestion('Transport security', 'Driver default')
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
        ->expectsQuestion('Transport security', 'Driver default')
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
        ->expectsQuestion('Transport security', 'Driver default')
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
        ->expectsQuestion('Transport security', 'Driver default')
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
        ->expectsQuestion('Transport security', 'Driver default')
        ->expectsConfirmation('Is this a production connection?', 'no')
        ->expectsConfirmation('Save changes?', 'yes')
        ->assertExitCode(0);
});

it('fails with config error when multiple connections exist, no name given, and non-interactive', function (): void {
    $staging = makeUpdateConnection('staging');
    $production = makeUpdateConnection('production');

    $config = Mockery::mock(ConfigService::class);
    $config->shouldReceive('getConnections')->andReturn([
        'staging' => $staging,
        'production' => $production,
    ]);
    $config->shouldNotReceive('setConnection');
    $config->shouldNotReceive('renameConnection');

    $this->app->instance(ConfigService::class, $config);

    $this->artisan('connection:update', ['--no-interaction' => true])
        ->expectsOutputToContain('Multiple connections found; pass the connection name: clonio connection:update <name>.')
        ->assertExitCode(2);
});

it('auto-selects the connection when only one exists and non-interactive', function (): void {
    $connection = makeUpdateConnection('staging');

    $config = Mockery::mock(ConfigService::class);
    $config->shouldReceive('getConnections')->andReturn(['staging' => $connection]);
    $config->shouldReceive('getConnection')->with('staging')->andReturn($connection);
    $config->shouldReceive('hasConnection')->with('staging')->andReturn(true);
    $config->shouldReceive('setConnection')->once();

    $this->app->instance(ConfigService::class, $config);

    $this->artisan('connection:update', ['--no-interaction' => true])
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
        ->expectsQuestion('Transport security', 'Driver default')
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

it('migrates the legacy SQL Server trust flag to ssl require', function (): void {
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
        trustServerCertificate: true,
    );

    $this->app->instance(ConfigService::class, fakeUpdateConfig($mssql, static fn (ConnectionData $d): bool => $d->ssl?->mode === SslMode::Require && $d->trustServerCertificate === false));

    $this->artisan('connection:update', ['name' => 'mssql'])
        ->expectsQuestion('Connection name', 'mssql')
        ->expectsQuestion('Database driver', 'sqlsrv')
        ->expectsQuestion('Host', 'localhost')
        ->expectsQuestion('Port', '1433')
        ->expectsQuestion('Database', 'mydb')
        ->expectsQuestion('Username', 'sa')
        ->expectsQuestion('Password (press Enter to keep current)', '')
        ->expectsChoice('Transport security', 'Require (encrypted, not verified)', [
            'Require (encrypted, not verified)',
            'Verify (encrypted + certificate check)',
            'Disable (plaintext)',
            'Driver default',
        ])
        ->expectsConfirmation('Is this a production connection?', 'no')
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
        ->expectsQuestion('Transport security', 'Driver default')
        ->expectsConfirmation('Is this a production connection?', 'no')
        ->expectsOutputToContain("A connection named 'production' already exists.")
        ->assertExitCode(4);
});

it('keeps a stored CA on Enter', function (): void {
    Storage::fake('local');
    Storage::disk('local')->put('certs/ca.pem', 'x');
    $current = makeUpdateConnection(ssl: new SslConfig(SslMode::Verify, 'certs/ca.pem'));

    $this->app->instance(ConfigService::class, fakeUpdateConfig($current, static fn (ConnectionData $d): bool => $d->ssl?->ca === 'certs/ca.pem'));

    $this->artisan('connection:update', ['name' => 'staging'])
        ->expectsQuestion('Connection name', 'staging')
        ->expectsQuestion('Database driver', 'mysql')
        ->expectsQuestion('Host', 'localhost')
        ->expectsQuestion('Port', '3306')
        ->expectsQuestion('Database', 'mydb')
        ->expectsQuestion('Username', 'root')
        ->expectsQuestion('Password (press Enter to keep current)', '')
        ->expectsQuestion('Transport security', 'Verify (encrypted + certificate check)')
        ->expectsQuestion('CA certificate path [certs/ca.pem] (Enter = keep, "none" = remove)', '')
        ->expectsConfirmation('Use a client certificate (mutual TLS)?', 'no')
        ->expectsConfirmation('Is this a production connection?', 'no')
        ->expectsConfirmation('Save changes?', 'yes')
        ->assertExitCode(0);
});

it('updates the password of a connection whose cert file is missing, without checking it (M4)', function (): void {
    Storage::fake('local');
    // Deliberately not put on disk: the ssl block is unchanged, so its files must not be checked.
    $current = makeUpdateConnection(ssl: new SslConfig(SslMode::Verify, 'certs/ca.pem'));

    $this->app->instance(ConfigService::class, fakeUpdateConfig(
        $current,
        static fn (ConnectionData $d): bool => $d->password !== $current->password && $d->ssl?->ca === 'certs/ca.pem'
    ));

    $this->artisan('connection:update', ['name' => 'staging'])
        ->expectsQuestion('Connection name', 'staging')
        ->expectsQuestion('Database driver', 'mysql')
        ->expectsQuestion('Host', 'localhost')
        ->expectsQuestion('Port', '3306')
        ->expectsQuestion('Database', 'mydb')
        ->expectsQuestion('Username', 'root')
        ->expectsQuestion('Password (press Enter to keep current)', 'newsecret')
        ->expectsQuestion('Transport security', 'Verify (encrypted + certificate check)')
        ->expectsQuestion('CA certificate path [certs/ca.pem] (Enter = keep, "none" = remove)', '')
        ->expectsConfirmation('Use a client certificate (mutual TLS)?', 'no')
        ->expectsConfirmation('Is this a production connection?', 'no')
        ->expectsConfirmation('Save changes?', 'yes')
        ->assertExitCode(0);
});

it('keeps a stored CA and verify mode under --no-interaction, asking nothing', function (): void {
    Storage::fake('local');
    Storage::disk('local')->put('certs/ca.pem', 'x');
    $current = makeUpdateConnection(ssl: new SslConfig(SslMode::Verify, 'certs/ca.pem'));

    $this->app->instance(ConfigService::class, fakeUpdateConfig(
        $current,
        static fn (ConnectionData $d): bool => $d->ssl instanceof SslConfig
            && $d->ssl->mode === SslMode::Verify
            && $d->ssl->ca === 'certs/ca.pem'
    ));

    $this->artisan('connection:update', ['name' => 'staging', '--no-interaction' => true])
        ->doesntExpectOutputToContain('CA certificate path')
        ->assertExitCode(0);
});

it('preserves a non-null dialect across an update', function (): void {
    $current = new ConnectionData(
        name: 'dump-target',
        type: DatabaseConnectionType::Dump,
        host: null,
        port: null,
        database: '/tmp/out.sql',
        schema: null,
        username: null,
        password: '',
        isProduction: false,
        dialect: DatabaseConnectionType::Mysql,
    );

    $config = Mockery::mock(ConfigService::class);
    $config->shouldReceive('getConnections')->andReturn(['dump-target' => $current]);
    $config->shouldReceive('getConnection')->with('dump-target')->andReturn($current);
    $config->shouldReceive('hasConnection')->with('dump-target')->andReturn(true);
    $config->shouldReceive('setConnection')
        ->once()
        ->withArgs(function (string $name, ConnectionData $data): bool {
            return $data->dialect === DatabaseConnectionType::Mysql;
        });

    $this->app->instance(ConfigService::class, $config);

    $this->artisan('connection:update', ['name' => 'dump-target'])
        ->expectsQuestion('Connection name', 'dump-target')
        ->expectsQuestion('Database driver', 'dump')
        ->expectsQuestion('Database file path', '/tmp/out.sql')
        ->expectsQuestion('Password (press Enter to keep current)', '')
        ->expectsConfirmation('Is this a production connection?', 'no')
        ->expectsConfirmation('Save changes?', 'yes')
        ->assertExitCode(0);
});

it('removes a stored CA on "none" and shows it in the diff (pgsql verify without CA is valid)', function (): void {
    Storage::fake('local');
    Storage::disk('local')->put('certs/ca.pem', 'x');
    $current = new ConnectionData(
        name: 'pg', type: DatabaseConnectionType::PostgreSQL, host: 'localhost', port: 5432, database: 'mydb',
        schema: 'public', username: 'u', password: 'encrypted:abc', isProduction: false,
        ssl: new SslConfig(SslMode::Verify, 'certs/ca.pem'),
    );

    $this->app->instance(ConfigService::class, fakeUpdateConfig($current, static fn (ConnectionData $d): bool => $d->ssl?->mode === SslMode::Verify && $d->ssl->ca === null));

    $this->artisan('connection:update', ['name' => 'pg'])
        ->expectsQuestion('Connection name', 'pg')
        ->expectsQuestion('Database driver', 'pgsql')
        ->expectsQuestion('Host', 'localhost')
        ->expectsQuestion('Port', '5432')
        ->expectsQuestion('Database', 'mydb')
        ->expectsQuestion('Username', 'u')
        ->expectsQuestion('Schema', 'public')
        ->expectsQuestion('Password (press Enter to keep current)', '')
        ->expectsQuestion('Transport security', 'Verify (encrypted + certificate check)')
        ->expectsQuestion('CA certificate path [certs/ca.pem] (Enter = keep, "none" = remove)', 'none')
        ->expectsConfirmation('Use a client certificate (mutual TLS)?', 'no')
        ->expectsConfirmation('Is this a production connection?', 'no')
        ->expectsTable(['Field', 'Old', 'New'], [['ssl.ca', 'certs/ca.pem', '']])
        ->expectsConfirmation('Save changes?', 'yes')
        ->assertExitCode(0);
});

it('drops the CA when switching from verify to require', function (): void {
    Storage::fake('local');
    Storage::disk('local')->put('certs/ca.pem', 'x');
    $current = makeUpdateConnection(ssl: new SslConfig(SslMode::Verify, 'certs/ca.pem'));

    $this->app->instance(ConfigService::class, fakeUpdateConfig($current, static fn (ConnectionData $d): bool => $d->ssl == new SslConfig(SslMode::Require)));

    $this->artisan('connection:update', ['name' => 'staging'])
        ->expectsQuestion('Connection name', 'staging')
        ->expectsQuestion('Database driver', 'mysql')
        ->expectsQuestion('Host', 'localhost')
        ->expectsQuestion('Port', '3306')
        ->expectsQuestion('Database', 'mydb')
        ->expectsQuestion('Username', 'root')
        ->expectsQuestion('Password (press Enter to keep current)', '')
        ->expectsQuestion('Transport security', 'Require (encrypted, not verified)')
        ->expectsConfirmation('Use a client certificate (mutual TLS)?', 'no')
        ->expectsConfirmation('Is this a production connection?', 'no')
        ->expectsConfirmation('Save changes?', 'yes')
        ->assertExitCode(0);
});

it('rejects an update that leaves mysql verify without a CA', function (): void {
    $current = makeUpdateConnection();
    $config = Mockery::mock(ConfigService::class);
    $config->shouldReceive('getConnection')->with('staging')->andReturn($current);
    $config->shouldReceive('hasConnection')->andReturn(true);
    $config->shouldNotReceive('setConnection');
    $this->app->instance(ConfigService::class, $config);

    $this->artisan('connection:update', ['name' => 'staging'])
        ->expectsQuestion('Connection name', 'staging')
        ->expectsQuestion('Database driver', 'mysql')
        ->expectsQuestion('Host', 'localhost')
        ->expectsQuestion('Port', '3306')
        ->expectsQuestion('Database', 'mydb')
        ->expectsQuestion('Username', 'root')
        ->expectsQuestion('Password (press Enter to keep current)', '')
        ->expectsQuestion('Transport security', 'Verify (encrypted + certificate check)')
        ->expectsQuestion('CA certificate path (leave empty for none)', '')
        ->expectsConfirmation('Use a client certificate (mutual TLS)?', 'no')
        ->expectsConfirmation('Is this a production connection?', 'no')
        ->expectsOutputToContain('mode verify requires a CA certificate for MySQL/MariaDB')
        ->assertExitCode(4);
});

it('removes ssl when the type changes to sqlite', function (): void {
    $current = makeUpdateConnection(ssl: new SslConfig(SslMode::Require));

    $this->app->instance(ConfigService::class, fakeUpdateConfig($current, static fn (ConnectionData $d): bool => $d->ssl === null));

    $this->artisan('connection:update', ['name' => 'staging'])
        ->expectsQuestion('Connection name', 'staging')
        ->expectsQuestion('Database driver', 'sqlite')
        ->expectsQuestion('Database file path', '/tmp/a.db')
        ->expectsQuestion('Password (press Enter to keep current)', '')
        ->expectsConfirmation('Is this a production connection?', 'no')
        ->expectsConfirmation('Save changes?', 'yes')
        ->assertExitCode(0);
});
