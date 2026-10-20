<?php

declare(strict_types=1);

use App\Data\ConnectionData;
use App\Data\SslConfig;
use App\Enums\DatabaseConnectionType;
use App\Enums\SslMode;
use App\Services\Config\ConfigService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

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

/**
 * Runs connection:update against a real (non-mocked) question helper fed from an in-memory
 * stdin stream, so prompt defaults are applied exactly as for a user pressing Enter.
 *
 * @param  list<string>  $lines
 * @return array{int, string}
 */
function g4b_runUpdateWithStdin(array $params, array $lines): array
{
    $stream = fopen('php://memory', 'r+');
    fwrite($stream, implode(PHP_EOL, $lines).PHP_EOL);
    rewind($stream);

    $input = new ArrayInput(['command' => 'connection:update', ...$params]);
    $input->setStream($stream);
    $input->setInteractive(true);
    $output = new BufferedOutput;

    $command = app(Kernel::class)->all()['connection:update'];
    $exitCode = $command->run($input, $output);

    return [$exitCode, $output->fetch()];
}

function g4b_mysqlConnection(?string $host = 'db.internal', ?int $port = 3307, ?string $database = 'appdb', ?string $username = 'app'): ConnectionData
{
    return new ConnectionData(
        name: 'staging',
        type: DatabaseConnectionType::Mysql,
        host: $host,
        port: $port,
        database: $database,
        schema: null,
        username: $username,
        password: 'encrypted:abc123',
        isProduction: false,
    );
}

function g4b_pgsqlConnection(string $schema = 'custom'): ConnectionData
{
    return new ConnectionData(
        name: 'pg',
        type: DatabaseConnectionType::PostgreSQL,
        host: 'pg.internal',
        port: 5433,
        database: 'pgdb',
        schema: $schema,
        username: 'pgu',
        password: 'encrypted:abc123',
        isProduction: false,
    );
}

function g4b_sqliteConnection(?string $database): ConnectionData
{
    return new ConnectionData(
        name: 'local',
        type: DatabaseConnectionType::Sqlite,
        host: null,
        port: null,
        database: $database,
        schema: null,
        username: null,
        password: '',
        isProduction: false,
    );
}

it('applies the stored values as prompt defaults when every question is answered with Enter', function (): void {
    $current = g4b_mysqlConnection();
    $this->app->instance(ConfigService::class, fakeUpdateConfig($current, static fn (ConnectionData $d): bool => $d == $current));

    [$exitCode, $output] = g4b_runUpdateWithStdin(['name' => 'staging'], array_fill(0, 12, ''));

    expect($exitCode)->toBe(0)
        ->and($output)->toContain(' Connection name [staging]:')
        ->and($output)->toContain(' Database driver [mysql]:')
        ->and($output)->toContain(' Host [db.internal]:')
        ->and($output)->toContain(' Port [3307]:')
        ->and($output)->toContain(' Database [appdb]:')
        ->and($output)->toContain(' Username [app]:')
        ->and($output)->toContain(' Password (press Enter to keep current):'.PHP_EOL)
        ->and($output)->toContain('No fields changed.')
        ->and($output)->toContain(' Save changes? (yes/no) [yes]:')
        ->and($output)->toContain("Connection 'staging' updated successfully.")
        ->and($output)->not->toContain('No changes saved.');
});

it('pre-selects the stored driver and schema when Enter is pressed for a pgsql connection', function (): void {
    $current = g4b_pgsqlConnection();
    $this->app->instance(ConfigService::class, fakeUpdateConfig($current, static fn (ConnectionData $d): bool => $d == $current));

    [$exitCode, $output] = g4b_runUpdateWithStdin(['name' => 'pg'], array_fill(0, 12, ''));

    expect($exitCode)->toBe(0)
        ->and($output)->toContain(' Database driver [pgsql]:')
        ->and($output)->toContain(' Port [5433]:')
        ->and($output)->toContain(' Schema [custom]:')
        ->and($output)->toContain('No fields changed.');
});

it('offers the new driver defaults (not the stored values) after switching to another network driver', function (): void {
    $current = g4b_mysqlConnection();
    $this->app->instance(ConfigService::class, fakeUpdateConfig($current, static fn (ConnectionData $d): bool => $d->type === DatabaseConnectionType::PostgreSQL
        && $d->host === 'localhost'
        && $d->port === 5432
        && $d->database === null
        && $d->username === null
        && $d->schema === 'public'
        && $d->password === 'encrypted:abc123'
        && $d->ssl === null));

    [$exitCode, $output] = g4b_runUpdateWithStdin(['name' => 'staging'], ['', 'pgsql', '', '', '', '', '', '', '', '', '', '']);

    expect($exitCode)->toBe(0)
        ->and($output)->toContain(' Host [localhost]:')
        ->and($output)->toContain(' Port [5432]:')
        ->and($output)->toContain(' Database:'.PHP_EOL)
        ->and($output)->toContain(' Username:'.PHP_EOL)
        ->and($output)->toContain(' Schema [public]:');
});

it('asks for a fresh file path without default after switching to sqlite', function (): void {
    $current = g4b_mysqlConnection();
    $this->app->instance(ConfigService::class, fakeUpdateConfig($current, static fn (ConnectionData $d): bool => $d->type === DatabaseConnectionType::Sqlite
        && $d->database === null
        && $d->host === null
        && $d->port === null
        && $d->username === null));

    [$exitCode, $output] = g4b_runUpdateWithStdin(['name' => 'staging'], ['', 'sqlite', '', '', '', '']);

    expect($exitCode)->toBe(0)
        ->and($output)->toContain(' Database file path:'.PHP_EOL);
});

it('keeps every stored network field unchanged under --no-interaction', function (): void {
    $current = g4b_mysqlConnection();
    $this->app->instance(ConfigService::class, fakeUpdateConfig($current, static fn (ConnectionData $d): bool => $d == $current));

    $this->artisan('connection:update', ['name' => 'staging', '--no-interaction' => true])
        ->expectsOutputToContain('No fields changed.')
        ->assertExitCode(0);
});

it('keeps null network fields null under --no-interaction', function (): void {
    $current = g4b_mysqlConnection(host: null, port: null, database: null, username: null);
    $this->app->instance(ConfigService::class, fakeUpdateConfig($current, static fn (ConnectionData $d): bool => $d == $current));

    $this->artisan('connection:update', ['name' => 'staging', '--no-interaction' => true])
        ->expectsOutputToContain('No fields changed.')
        ->assertExitCode(0);
});

it('keeps the stored schema under --no-interaction', function (): void {
    $current = g4b_pgsqlConnection();
    $this->app->instance(ConfigService::class, fakeUpdateConfig($current, static fn (ConnectionData $d): bool => $d == $current));

    $this->artisan('connection:update', ['name' => 'pg', '--no-interaction' => true])
        ->assertExitCode(0);
});

it('keeps the sqlite file path under --no-interaction', function (?string $database): void {
    $current = g4b_sqliteConnection($database);
    $this->app->instance(ConfigService::class, fakeUpdateConfig($current, static fn (ConnectionData $d): bool => $d == $current));

    $this->artisan('connection:update', ['name' => 'local', '--no-interaction' => true])
        ->expectsOutputToContain('No fields changed.')
        ->assertExitCode(0);
})->with([
    'with a path' => ['/tmp/g4b-existing.db'],
    'without a path' => [null],
]);

it('stores empty answers as null and falls back to the stored port', function (): void {
    $current = g4b_mysqlConnection();
    $this->app->instance(ConfigService::class, fakeUpdateConfig($current, static fn (ConnectionData $d): bool => $d->host === null
        && $d->port === 3307
        && $d->database === null
        && $d->username === null));

    $this->artisan('connection:update', ['name' => 'staging'])
        ->expectsQuestion('Connection name', 'staging')
        ->expectsQuestion('Database driver', 'mysql')
        ->expectsQuestion('Host', '')
        ->expectsQuestion('Port', '')
        ->expectsQuestion('Database', '')
        ->expectsQuestion('Username', '')
        ->expectsQuestion('Password (press Enter to keep current)', '')
        ->expectsQuestion('Transport security', 'Driver default')
        ->expectsConfirmation('Is this a production connection?', 'no')
        ->expectsConfirmation('Save changes?', 'yes')
        ->assertExitCode(0);
});

it('falls back to the new driver default port and stores an empty schema as null after a type change', function (): void {
    $current = g4b_mysqlConnection();
    $this->app->instance(ConfigService::class, fakeUpdateConfig($current, static fn (ConnectionData $d): bool => $d->type === DatabaseConnectionType::PostgreSQL
        && $d->host === null
        && $d->port === 5432
        && $d->database === null
        && $d->username === null
        && $d->schema === null));

    $this->artisan('connection:update', ['name' => 'staging'])
        ->expectsQuestion('Connection name', 'staging')
        ->expectsQuestion('Database driver', 'pgsql')
        ->expectsQuestion('Host', '')
        ->expectsQuestion('Port', '')
        ->expectsQuestion('Database', '')
        ->expectsQuestion('Username', '')
        ->expectsQuestion('Schema', '')
        ->expectsQuestion('Password (press Enter to keep current)', '')
        ->expectsQuestion('Transport security', 'Driver default')
        ->expectsConfirmation('Is this a production connection?', 'no')
        ->expectsConfirmation('Save changes?', 'yes')
        ->assertExitCode(0);
});

it('stores port 0 as null but keeps port 1', function (string $answer, ?int $expected): void {
    $current = g4b_mysqlConnection();
    $this->app->instance(ConfigService::class, fakeUpdateConfig($current, static fn (ConnectionData $d): bool => $d->port === $expected));

    $this->artisan('connection:update', ['name' => 'staging'])
        ->expectsQuestion('Connection name', 'staging')
        ->expectsQuestion('Database driver', 'mysql')
        ->expectsQuestion('Host', 'db.internal')
        ->expectsQuestion('Port', $answer)
        ->expectsQuestion('Database', 'appdb')
        ->expectsQuestion('Username', 'app')
        ->expectsQuestion('Password (press Enter to keep current)', '')
        ->expectsQuestion('Transport security', 'Driver default')
        ->expectsConfirmation('Is this a production connection?', 'no')
        ->expectsConfirmation('Save changes?', 'yes')
        ->assertExitCode(0);
})->with([
    'zero' => ['0', null],
    'one' => ['1', 1],
]);

it('treats an empty name argument like a missing one', function (): void {
    $current = makeUpdateConnection('staging');
    $this->app->instance(ConfigService::class, fakeUpdateConfig($current, static fn (ConnectionData $d): bool => $d == $current));

    $this->artisan('connection:update', ['name' => '', '--no-interaction' => true])
        ->assertExitCode(0);
});

it('updates the connection picked from the list', function (?string $answer, string $expected): void {
    $staging = makeUpdateConnection('staging');
    $production = makeUpdateConnection('production');

    $config = Mockery::mock(ConfigService::class);
    $config->shouldReceive('getConnections')->andReturn(['staging' => $staging, 'production' => $production]);
    $config->shouldReceive('getConnection')->once()->with($expected)->andReturn($expected === 'staging' ? $staging : $production);
    $config->shouldReceive('setConnection')->once()->with($expected, Mockery::type(ConnectionData::class));

    $this->app->instance(ConfigService::class, $config);

    $this->artisan('connection:update')
        ->expectsQuestion('Which connection do you want to update?', $answer)
        ->expectsQuestion('Connection name', $expected)
        ->expectsQuestion('Database driver', 'mysql')
        ->expectsQuestion('Host', 'localhost')
        ->expectsQuestion('Port', '3306')
        ->expectsQuestion('Database', 'mydb')
        ->expectsQuestion('Username', 'root')
        ->expectsQuestion('Password (press Enter to keep current)', '')
        ->expectsQuestion('Transport security', 'Driver default')
        ->expectsConfirmation('Is this a production connection?', 'no')
        ->expectsConfirmation('Save changes?', 'yes')
        ->expectsOutputToContain(sprintf("Connection '%s' updated successfully.", $expected))
        ->assertExitCode(0);
})->with([
    'second entry' => ['production', 'production'],
    'no answer falls back to the first entry' => [null, 'staging'],
]);

it('keeps a network connection without ssl on the driver default under --no-interaction', function (): void {
    $current = g4b_mysqlConnection();
    $this->app->instance(ConfigService::class, fakeUpdateConfig($current, static fn (ConnectionData $d): bool => $d->ssl === null));

    $this->artisan('connection:update', ['name' => 'staging', '--no-interaction' => true])
        ->assertExitCode(0);
});

it('tells the user that certificate paths were removed when switching to sqlsrv', function (): void {
    $current = makeUpdateConnection(ssl: new SslConfig(SslMode::Verify, 'certs/ca.pem'));
    $this->app->instance(ConfigService::class, fakeUpdateConfig($current, static fn (ConnectionData $d): bool => $d->ssl == new SslConfig(SslMode::Verify)));

    $this->artisan('connection:update', ['name' => 'staging'])
        ->expectsQuestion('Connection name', 'staging')
        ->expectsQuestion('Database driver', 'sqlsrv')
        ->expectsQuestion('Host', 'localhost')
        ->expectsQuestion('Port', '1433')
        ->expectsQuestion('Database', 'mydb')
        ->expectsQuestion('Username', 'root')
        ->expectsQuestion('Password (press Enter to keep current)', '')
        ->expectsQuestion('Transport security', 'Verify (encrypted + certificate check)')
        ->expectsOutputToContain('Certificate paths are not supported for sqlsrv and were removed.')
        ->expectsConfirmation('Is this a production connection?', 'no')
        ->expectsConfirmation('Save changes?', 'yes')
        ->assertExitCode(0);
});

it('does not mention removed certificate paths for sqlsrv without certificate files', function (): void {
    $current = new ConnectionData(
        name: 'mssql', type: DatabaseConnectionType::SqlServer, host: 'localhost', port: 1433, database: 'mydb',
        schema: null, username: 'sa', password: 'encrypted:abc123', isProduction: false,
        ssl: new SslConfig(SslMode::Require),
    );
    $this->app->instance(ConfigService::class, fakeUpdateConfig($current, static fn (ConnectionData $d): bool => $d == $current));

    $this->artisan('connection:update', ['name' => 'mssql'])
        ->expectsQuestion('Connection name', 'mssql')
        ->expectsQuestion('Database driver', 'sqlsrv')
        ->expectsQuestion('Host', 'localhost')
        ->expectsQuestion('Port', '1433')
        ->expectsQuestion('Database', 'mydb')
        ->expectsQuestion('Username', 'sa')
        ->expectsQuestion('Password (press Enter to keep current)', '')
        ->expectsQuestion('Transport security', 'Require (encrypted, not verified)')
        ->doesntExpectOutputToContain('Certificate paths are not supported')
        ->expectsConfirmation('Is this a production connection?', 'no')
        ->expectsConfirmation('Save changes?', 'yes')
        ->assertExitCode(0);
});

it('does not mention removed certificate paths when a mysql connection keeps its CA', function (): void {
    Storage::fake('local');
    Storage::disk('local')->put('certs/ca.pem', 'x');
    $current = makeUpdateConnection(ssl: new SslConfig(SslMode::Verify, 'certs/ca.pem'));
    $this->app->instance(ConfigService::class, fakeUpdateConfig($current, static fn (ConnectionData $d): bool => $d == $current));

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
        ->doesntExpectOutputToContain('Certificate paths are not supported')
        ->expectsConfirmation('Is this a production connection?', 'no')
        ->expectsConfirmation('Save changes?', 'yes')
        ->assertExitCode(0);
});

it('shows every changed field in the diff when filling an empty sqlite connection', function (): void {
    config(['app.key' => 'base64:ROzyPViGEkER6n3g0OHblde5CygEIcuDlAFbca99xvM=']);
    Storage::fake('local');
    Storage::disk('local')->put('certs/ca.pem', 'x');
    Storage::disk('local')->put('certs/client.pem', 'x');
    Storage::disk('local')->put('certs/client.key', 'x');
    chmod(Storage::disk('local')->path('certs/client.key'), 0600);

    $current = g4b_sqliteConnection('/tmp/g4b-a.db');

    $config = Mockery::mock(ConfigService::class);
    $config->shouldReceive('getConnection')->with('local')->andReturn($current);
    $config->shouldReceive('hasConnection')->with('renamed')->andReturn(false);
    $config->shouldReceive('renameConnection')->once()->withArgs(
        static fn (string $old, string $new, ConnectionData $d): bool => $old === 'local' && $new === 'renamed'
    );
    $this->app->instance(ConfigService::class, $config);

    $this->artisan('connection:update', ['name' => 'local'])
        ->expectsQuestion('Connection name', 'renamed')
        ->expectsQuestion('Database driver', 'pgsql')
        ->expectsQuestion('Host', 'pg.local')
        ->expectsQuestion('Port', '5433')
        ->expectsQuestion('Database', 'pgdb')
        ->expectsQuestion('Username', 'pgu')
        ->expectsQuestion('Schema', 'sch')
        ->expectsQuestion('Password (press Enter to keep current)', 'secret')
        ->expectsQuestion('Transport security', 'Verify (encrypted + certificate check)')
        ->expectsQuestion('CA certificate path (leave empty for none)', 'certs/ca.pem')
        ->expectsConfirmation('Use a client certificate (mutual TLS)?', 'yes')
        ->expectsQuestion('Client certificate path (leave empty for none)', 'certs/client.pem')
        ->expectsQuestion('Client key path (leave empty for none)', 'certs/client.key')
        ->expectsConfirmation('Is this a production connection?', 'yes')
        ->expectsTable(['Field', 'Old', 'New'], [
            ['name', 'local', 'renamed'],
            ['type', 'sqlite', 'pgsql'],
            ['host', '', 'pg.local'],
            ['port', '', '5433'],
            ['database', '/tmp/g4b-a.db', 'pgdb'],
            ['schema', '', 'sch'],
            ['username', '', 'pgu'],
            ['is_production', 'false', 'true'],
            ['ssl.mode', 'default', 'verify'],
            ['ssl.ca', '', 'certs/ca.pem'],
            ['ssl.cert', '', 'certs/client.pem'],
            ['ssl.key', '', 'certs/client.key'],
        ])
        ->expectsConfirmation('Save changes?', 'yes')
        ->assertExitCode(0);
});

it('shows every cleared field in the diff when turning a full pgsql connection into sqlite', function (): void {
    $current = new ConnectionData(
        name: 'pg', type: DatabaseConnectionType::PostgreSQL, host: 'pg.local', port: 5433, database: 'pgdb',
        schema: 'sch', username: 'pgu', password: 'encrypted:abc123', isProduction: true, trustServerCertificate: true,
        ssl: new SslConfig(SslMode::Verify, 'certs/ca.pem', 'certs/client.pem', 'certs/client.key'),
    );
    $this->app->instance(ConfigService::class, fakeUpdateConfig($current, static fn (ConnectionData $d): bool => $d->type === DatabaseConnectionType::Sqlite));

    $this->artisan('connection:update', ['name' => 'pg'])
        ->expectsQuestion('Connection name', 'pg')
        ->expectsQuestion('Database driver', 'sqlite')
        ->expectsQuestion('Database file path', '/tmp/g4b-b.db')
        ->expectsQuestion('Password (press Enter to keep current)', '')
        ->expectsConfirmation('Is this a production connection?', 'no')
        ->expectsTable(['Field', 'Old', 'New'], [
            ['type', 'pgsql', 'sqlite'],
            ['host', 'pg.local', ''],
            ['port', '5433', ''],
            ['database', 'pgdb', '/tmp/g4b-b.db'],
            ['schema', 'sch', ''],
            ['username', 'pgu', ''],
            ['is_production', 'true', 'false'],
            ['trust_server_certificate', 'true', 'false'],
            ['ssl.mode', 'verify', 'default'],
            ['ssl.ca', 'certs/ca.pem', ''],
            ['ssl.cert', 'certs/client.pem', ''],
            ['ssl.key', 'certs/client.key', ''],
        ])
        ->expectsConfirmation('Save changes?', 'yes')
        ->assertExitCode(0);
});

it('reports that nothing was saved when the save confirmation is declined', function (): void {
    $current = makeUpdateConnection('staging');
    $config = Mockery::mock(ConfigService::class);
    $config->shouldReceive('getConnection')->with('staging')->andReturn($current);
    $config->shouldNotReceive('setConnection');
    $this->app->instance(ConfigService::class, $config);

    [$exitCode, $output] = g4b_runUpdateWithStdin(['name' => 'staging'], [...array_fill(0, 9, ''), 'no']);

    expect($exitCode)->toBe(0)
        ->and($output)->toContain('No changes saved.')
        ->and($output)->not->toContain('updated successfully');
});

it('warns when the client key of the updated connection is readable by other users', function (): void {
    Storage::fake('local');
    Storage::disk('local')->put('certs/ca.pem', 'x');
    Storage::disk('local')->put('certs/client.pem', 'x');
    Storage::disk('local')->put('certs/client.key', 'x');
    chmod(Storage::disk('local')->path('certs/client.key'), 0644);
    $current = makeUpdateConnection(ssl: new SslConfig(SslMode::Verify, 'certs/ca.pem', 'certs/client.pem', 'certs/client.key'));
    $this->app->instance(ConfigService::class, fakeUpdateConfig($current, static fn (ConnectionData $d): bool => $d == $current));

    $this->artisan('connection:update', ['name' => 'staging', '--no-interaction' => true])
        ->expectsOutputToContain('Client key certs/client.key is readable by other users. Run: chmod 600 ')
        ->assertExitCode(0);
});

it('lists a changed password in the change summary without printing it', function (): void {
    $current = makeUpdateConnection();

    $this->app->instance(ConfigService::class, fakeUpdateConfig(
        $current,
        static fn (ConnectionData $d): bool => $d->password !== $current->password
    ));

    $this->artisan('connection:update', ['name' => 'staging'])
        ->expectsQuestion('Connection name', 'staging')
        ->expectsQuestion('Database driver', 'mysql')
        ->expectsQuestion('Host', 'localhost')
        ->expectsQuestion('Port', '3306')
        ->expectsQuestion('Database', 'mydb')
        ->expectsQuestion('Username', 'root')
        ->expectsQuestion('Password (press Enter to keep current)', 'newsecret')
        ->expectsQuestion('Transport security', 'Driver default')
        ->expectsConfirmation('Is this a production connection?', 'no')
        ->expectsTable(['Field', 'Old', 'New'], [['password', '••••••••', '(changed)']])
        ->doesntExpectOutputToContain('newsecret')
        ->expectsConfirmation('Save changes?', 'yes')
        ->assertExitCode(0);
});

it('reports no changes when only the unchanged password is kept', function (): void {
    $current = makeUpdateConnection();

    $this->app->instance(ConfigService::class, fakeUpdateConfig(
        $current,
        static fn (ConnectionData $d): bool => $d->password === $current->password
    ));

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
        ->expectsOutputToContain('No fields changed.')
        ->expectsConfirmation('Save changes?', 'yes')
        ->assertExitCode(0);
});
