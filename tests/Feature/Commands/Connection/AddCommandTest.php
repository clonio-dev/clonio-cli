<?php

declare(strict_types=1);

use App\Data\ConnectionData;
use App\Enums\SslMode;
use App\Services\Config\ConfigService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Console\Tester\CommandTester;

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

/** Config mock that asserts the saved connection matches $check. */
function fakeConfigExpecting(Closure $check): ConfigService
{
    $mock = Mockery::mock(ConfigService::class);
    $mock->shouldReceive('hasConnection')->andReturn(false);
    $mock->shouldReceive('setConnection')->once()->withArgs(
        static fn (string $name, ConnectionData $data): bool => (bool) $check($data)
    );

    return $mock;
}

/** @return array<string, string> */
function mysqlFlags(): array
{
    return [
        'name' => 'my_db', '--type' => 'mysql', '--host' => 'db.example.com', '--port' => '3306',
        '--database' => 'mydb', '--username' => 'root', '--password' => 'secret',
    ];
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
        ->expectsQuestion('Transport security', 'Require (encrypted, not verified)')
        ->expectsConfirmation('Use a client certificate (mutual TLS)?', 'no')
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
        ->expectsQuestion('Transport security', 'Require (encrypted, not verified)')
        ->expectsConfirmation('Use a client certificate (mutual TLS)?', 'no')
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
        ->expectsQuestion('Transport security', 'Require (encrypted, not verified)')
        ->expectsConfirmation('Use a client certificate (mutual TLS)?', 'no')
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
        ->expectsQuestion('Transport security', 'Require (encrypted, not verified)')
        ->expectsConfirmation('Use a client certificate (mutual TLS)?', 'no')
        ->expectsConfirmation('Is this a production connection?', 'no')
        ->expectsConfirmation('Save this connection?', 'yes')
        ->expectsOutputToContain('public')
        ->assertExitCode(0);
});

it('prompts for transport security on a SQL Server connection without asking for files', function (): void {
    $this->app->instance(ConfigService::class, fakeConfigExpecting(
        static fn (ConnectionData $d): bool => $d->ssl?->mode === SslMode::Verify
    ));

    $this->artisan('connection:add', [
        'name' => 'mssql_db',
        '--type' => 'sqlsrv',
        '--host' => 'localhost',
        '--port' => '1433',
        '--database' => 'mydb',
        '--username' => 'sa',
        '--password' => 'secret',
    ])
        ->expectsQuestion('Transport security', 'Verify (encrypted + certificate check)')
        ->expectsConfirmation('Is this a production connection?', 'no')
        ->expectsConfirmation('Save this connection?', 'yes')
        ->expectsOutputToContain('Transport security')
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
        ->expectsQuestion('Transport security', 'Require (encrypted, not verified)')
        ->expectsConfirmation('Use a client certificate (mutual TLS)?', 'no')
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
        ->expectsQuestion('Transport security', 'Require (encrypted, not verified)')
        ->expectsConfirmation('Use a client certificate (mutual TLS)?', 'no')
        ->expectsConfirmation('Is this a production connection?', 'no')
        ->expectsConfirmation('Save this connection?', 'yes')
        ->expectsOutputToContain('Failed to save connection: disk full')
        ->assertExitCode(5);
});

it('defaults new network connections to require without interaction', function (): void {
    $this->app->instance(ConfigService::class, fakeConfigExpecting(
        static fn (ConnectionData $d): bool => $d->ssl?->mode === SslMode::Require && ! $d->ssl->hasCertificateFiles()
    ));

    $this->artisan('connection:add', [...mysqlFlags(), '--no-interaction' => true])
        ->assertExitCode(0);
});

it('defaults sqlsrv connections to verify without interaction', function (): void {
    $this->app->instance(ConfigService::class, fakeConfigExpecting(
        static fn (ConnectionData $d): bool => $d->ssl?->mode === SslMode::Verify && ! $d->ssl->hasCertificateFiles()
    ));

    $this->artisan('connection:add', [
        'name' => 'mssql', '--type' => 'sqlsrv', '--host' => 'h', '--port' => '1433', '--database' => 'd',
        '--username' => 'sa', '--password' => 'p', '--no-interaction' => true,
    ])->assertExitCode(0);
});

it('offers the transport modes in order', function (): void {
    $this->app->instance(ConfigService::class, fakeConfigExpecting(
        static fn (ConnectionData $d): bool => $d->ssl?->mode === SslMode::Disable
    ));

    $this->artisan('connection:add', mysqlFlags())
        ->expectsChoice('Transport security', 'Disable (plaintext)', [
            'Require (encrypted, not verified)',
            'Verify (encrypted + certificate check)',
            'Disable (plaintext)',
            'Driver default',
        ])
        ->expectsConfirmation('Is this a production connection?', 'no')
        ->expectsConfirmation('Save this connection?', 'yes')
        ->assertExitCode(0);
});

it('stores no ssl block when Driver default is chosen', function (): void {
    $this->app->instance(ConfigService::class, fakeConfigExpecting(
        static fn (ConnectionData $d): bool => $d->ssl === null
    ));

    $this->artisan('connection:add', mysqlFlags())
        ->expectsQuestion('Transport security', 'Driver default')
        ->expectsConfirmation('Is this a production connection?', 'no')
        ->expectsConfirmation('Save this connection?', 'yes')
        ->assertExitCode(0);
});

it('creates a verify connection fully non-interactively', function (): void {
    Storage::fake('local');
    Storage::disk('local')->put('certs/ca.pem', 'x');

    $this->app->instance(ConfigService::class, fakeConfigExpecting(
        static fn (ConnectionData $d): bool => $d->ssl?->mode === SslMode::Verify && $d->ssl->ca === 'certs/ca.pem'
    ));

    $this->artisan('connection:add', [...mysqlFlags(), '--ssl-mode' => 'verify', '--ssl-ca' => 'certs/ca.pem', '--no-interaction' => true])
        ->assertExitCode(0);
});

it('asks for the CA when verify is chosen interactively', function (): void {
    Storage::fake('local');
    Storage::disk('local')->put('certs/ca.pem', 'x');

    $this->app->instance(ConfigService::class, fakeConfigExpecting(
        static fn (ConnectionData $d): bool => $d->ssl?->ca === 'certs/ca.pem'
    ));

    $this->artisan('connection:add', mysqlFlags())
        ->expectsQuestion('Transport security', 'Verify (encrypted + certificate check)')
        ->expectsQuestion('CA certificate path (leave empty for none)', 'certs/ca.pem')
        ->expectsConfirmation('Use a client certificate (mutual TLS)?', 'no')
        ->expectsConfirmation('Is this a production connection?', 'no')
        ->expectsOutputToContain('CA certificate')
        ->expectsConfirmation('Save this connection?', 'yes')
        ->assertExitCode(0);
});

it('asks for client certificate and key when mutual TLS is confirmed', function (): void {
    Storage::fake('local');
    Storage::disk('local')->put('c.pem', 'x');
    Storage::disk('local')->put('k.pem', 'x');
    chmod(Storage::disk('local')->path('k.pem'), 0600);

    $this->app->instance(ConfigService::class, fakeConfigExpecting(
        static fn (ConnectionData $d): bool => $d->ssl?->cert === 'c.pem' && $d->ssl->key === 'k.pem'
    ));

    $this->artisan('connection:add', mysqlFlags())
        ->expectsQuestion('Transport security', 'Require (encrypted, not verified)')
        ->expectsConfirmation('Use a client certificate (mutual TLS)?', 'yes')
        ->expectsQuestion('Client certificate path (leave empty for none)', 'c.pem')
        ->expectsQuestion('Client key path (leave empty for none)', 'k.pem')
        ->expectsConfirmation('Is this a production connection?', 'no')
        ->expectsConfirmation('Save this connection?', 'yes')
        ->assertExitCode(0);
});

it('still asks for the CA when only a client certificate and key are given via flags', function (): void {
    Storage::fake('local');
    Storage::disk('local')->put('certs/ca.pem', 'x');
    Storage::disk('local')->put('c.pem', 'x');
    Storage::disk('local')->put('k.pem', 'x');
    chmod(Storage::disk('local')->path('k.pem'), 0600);

    $this->app->instance(ConfigService::class, fakeConfigExpecting(
        static fn (ConnectionData $d): bool => $d->ssl?->ca === 'certs/ca.pem' && $d->ssl->cert === 'c.pem' && $d->ssl->key === 'k.pem'
    ));

    $this->artisan('connection:add', [...mysqlFlags(), '--ssl-cert' => 'c.pem', '--ssl-key' => 'k.pem'])
        ->expectsQuestion('Transport security', 'Verify (encrypted + certificate check)')
        ->expectsQuestion('CA certificate path (leave empty for none)', 'certs/ca.pem')
        ->expectsConfirmation('Is this a production connection?', 'no')
        ->expectsConfirmation('Save this connection?', 'yes')
        ->assertExitCode(0);
});

it('hints at --ssl-mode=verify when --ssl-ca is given without --ssl-mode', function (): void {
    $this->app->instance(ConfigService::class, fakeConfigReadOnly());

    $this->artisan('connection:add', [...mysqlFlags(), '--ssl-ca' => 'ca.pem', '--no-interaction' => true])
        ->expectsOutputToContain('a CA certificate is only used with mode verify (pass --ssl-mode=verify)')
        ->assertExitCode(4);
});

it('warns when the client key is readable by others', function (): void {
    Storage::fake('local');
    Storage::disk('local')->put('c.pem', 'x');
    Storage::disk('local')->put('k.pem', 'x');
    chmod(Storage::disk('local')->path('k.pem'), 0644);

    $this->app->instance(ConfigService::class, fakeConfig());

    $this->artisan('connection:add', [...mysqlFlags(), '--ssl-mode' => 'require', '--ssl-cert' => 'c.pem', '--ssl-key' => 'k.pem', '--no-interaction' => true])
        ->expectsOutputToContain('chmod 600')
        ->assertExitCode(0);
})->skipOnWindows();

it('rejects an unknown ssl mode', function (): void {
    $this->app->instance(ConfigService::class, fakeConfigReadOnly());

    $this->artisan('connection:add', [...mysqlFlags(), '--ssl-mode' => 'prefer'])
        ->expectsOutputToContain("Unknown ssl mode: 'prefer'. Valid modes: require, verify, disable.")
        ->assertExitCode(4);
});

it('rejects ssl options on sqlite', function (): void {
    $this->app->instance(ConfigService::class, fakeConfigReadOnly());

    $this->artisan('connection:add', ['name' => 'local', '--type' => 'sqlite', '--database' => '/tmp/a.db', '--ssl-mode' => 'require'])
        ->expectsOutputToContain('ssl is only supported for network connections')
        ->assertExitCode(4);
});

it('rejects certificate paths on sqlsrv', function (): void {
    $this->app->instance(ConfigService::class, fakeConfigReadOnly());

    $this->artisan('connection:add', [
        'name' => 'mssql', '--type' => 'sqlsrv', '--host' => 'h', '--port' => '1433', '--database' => 'd',
        '--username' => 'sa', '--password' => 'p', '--ssl-mode' => 'verify', '--ssl-ca' => 'ca.pem',
    ])
        ->expectsOutputToContain('sqlsrv uses the system trust store; certificate paths are not supported')
        ->assertExitCode(4);
});

it('rejects verify without a CA on mysql', function (): void {
    $this->app->instance(ConfigService::class, fakeConfigReadOnly());

    $this->artisan('connection:add', [...mysqlFlags(), '--ssl-mode' => 'verify', '--no-interaction' => true])
        ->expectsOutputToContain('mode verify requires a CA certificate for MySQL/MariaDB')
        ->assertExitCode(4);
});

it('rejects a CA file that does not exist, showing the resolved path', function (): void {
    Storage::fake('local');
    $this->app->instance(ConfigService::class, fakeConfigReadOnly());

    $this->artisan('connection:add', [...mysqlFlags(), '--ssl-mode' => 'verify', '--ssl-ca' => 'certs/missing.pem'])
        ->expectsOutputToContain(Storage::disk('local')->path('certs/missing.pem'))
        ->assertExitCode(4);
});

it('treats --trust-server-certificate as a deprecated alias for require on sqlsrv', function (): void {
    $this->app->instance(ConfigService::class, fakeConfigExpecting(
        static fn (ConnectionData $d): bool => $d->ssl?->mode === SslMode::Require && $d->trustServerCertificate === false
    ));

    $this->artisan('connection:add', [
        'name' => 'mssql', '--type' => 'sqlsrv', '--host' => 'h', '--port' => '1433', '--database' => 'd',
        '--username' => 'sa', '--password' => 'p', '--trust-server-certificate' => true, '--no-interaction' => true,
    ])->assertExitCode(0);
});

/** Runs connection:add through a real (stream-backed) question helper so prompt defaults apply. */
function g4_runAdd(array $args, array $inputs): CommandTester
{
    $tester = new CommandTester(Artisan::all()['connection:add']);
    $tester->setInputs($inputs);
    $tester->execute($args, ['interactive' => true]);

    return $tester;
}

function g4_decrypted(string $stored): string
{
    expect($stored)->toStartWith('encrypted:');

    return Crypt::decryptString(substr($stored, strlen('encrypted:')));
}

it('re-prompts for every option passed as an empty string', function (): void {
    $this->app->instance(ConfigService::class, fakeConfigExpecting(
        static fn (ConnectionData $d): bool => $d->name === 'my_db'
            && $d->type->value === 'mysql'
            && $d->host === 'db.example.com'
            && $d->port === 3307
            && $d->database === 'shop'
            && $d->username === 'admin'
            && g4_decrypted($d->password) === 'pw'
            && $d->ssl?->mode === SslMode::Require
            && ! $d->ssl->hasCertificateFiles()
    ));

    $this->artisan('connection:add', [
        'name' => '', '--type' => '', '--host' => '', '--port' => '', '--database' => '',
        '--username' => '', '--password' => '', '--ssl-mode' => '', '--ssl-ca' => '', '--ssl-cert' => '', '--ssl-key' => '',
    ])
        ->expectsQuestion('Connection name', 'my_db')
        ->expectsQuestion('Database driver', 'mysql')
        ->expectsQuestion('Host', 'db.example.com')
        ->expectsQuestion('Port', '3307')
        ->expectsQuestion('Database name', 'shop')
        ->expectsQuestion('Username', 'admin')
        ->expectsQuestion('Password', 'pw')
        ->expectsQuestion('Transport security', 'Require (encrypted, not verified)')
        ->expectsConfirmation('Use a client certificate (mutual TLS)?', 'no')
        ->expectsConfirmation('Is this a production connection?', 'no')
        ->expectsConfirmation('Save this connection?', 'yes')
        ->assertExitCode(0);
});

it('falls back to defaults when prompts return no answer and shows the PostgreSQL summary', function (): void {
    $this->app->instance(ConfigService::class, fakeConfigExpecting(
        static fn (ConnectionData $d): bool => $d->host === 'localhost'
            && $d->port === 5432
            && $d->database === ''
            && $d->schema === 'public'
            && $d->username === ''
            && g4_decrypted($d->password) === ''
            && $d->ssl === null
    ));

    $this->artisan('connection:add', ['name' => 'pg_db', '--type' => 'pgsql'])
        ->expectsQuestion('Host', null)
        ->expectsQuestion('Port', null)
        ->expectsQuestion('Database name', null)
        ->expectsQuestion('Schema', null)
        ->expectsQuestion('Username', null)
        ->expectsQuestion('Password', null)
        ->expectsQuestion('Transport security', 'Driver default')
        ->expectsConfirmation('Is this a production connection?', 'no')
        ->expectsTable(['Field', 'Value'], [
            ['Name', 'pg_db'],
            ['Driver', 'pgsql'],
            ['Host', 'localhost'],
            ['Port', '5432'],
            ['Database', ''],
            ['Schema', 'public'],
            ['Username', ''],
            ['Password', '••••••••'],
            ['Transport security', 'Driver default'],
            ['Production', 'No'],
        ])
        ->expectsConfirmation('Save this connection?', 'yes')
        ->expectsOutput("Connection 'pg_db' added successfully.")
        ->assertExitCode(0);
});

it('uses the schema given via flag and re-prompts for an empty one', function (string $flag, ?string $answer, string $expected): void {
    $this->app->instance(ConfigService::class, fakeConfigExpecting(
        static fn (ConnectionData $d): bool => $d->schema === $expected
    ));

    $pending = $this->artisan('connection:add', [
        'name' => 'pg_db', '--type' => 'pgsql', '--host' => 'h', '--port' => '5432', '--database' => 'd',
        '--username' => 'u', '--password' => 'p', '--schema' => $flag, '--ssl-mode' => 'require',
    ]);

    if ($answer !== null) {
        $pending->expectsQuestion('Schema', $answer);
    }

    $pending
        ->expectsConfirmation('Is this a production connection?', 'no')
        ->expectsConfirmation('Save this connection?', 'yes')
        ->assertExitCode(0);
})->with([
    'flag given' => ['custom', null, 'custom'],
    'empty flag' => ['', 'sales', 'sales'],
]);

it('reports the empty driver type when the driver prompt returns nothing', function (): void {
    $this->app->instance(ConfigService::class, fakeConfigReadOnly());

    $this->artisan('connection:add', ['name' => 'my_db'])
        ->expectsQuestion('Database driver', null)
        ->expectsOutput("Unknown driver type: ''. Valid types: mysql, mariadb, pgsql, sqlsrv, sqlite, dump.")
        ->assertExitCode(4);
});

it('lists the valid driver types for an unknown type', function (): void {
    $this->app->instance(ConfigService::class, fakeConfigReadOnly());

    $this->artisan('connection:add', ['name' => 'my_db', '--type' => 'oracle'])
        ->expectsOutput("Unknown driver type: 'oracle'. Valid types: mysql, mariadb, pgsql, sqlsrv, sqlite, dump.")
        ->assertExitCode(4);
});

it('reports the empty dialect when the dialect prompt returns nothing', function (): void {
    $this->app->instance(ConfigService::class, fakeConfigReadOnly());

    $this->artisan('connection:add', ['name' => 'd', '--type' => 'dump'])
        ->expectsQuestion('Target SQL dialect', null)
        ->expectsOutput("Unknown dialect: ''. Valid dialects: mysql, mariadb, pgsql, sqlsrv, sqlite.")
        ->assertExitCode(4);
});

it('lists the valid dialects for an unknown dialect', function (): void {
    $this->app->instance(ConfigService::class, fakeConfigReadOnly());

    $this->artisan('connection:add', ['name' => 'd', '--type' => 'dump', '--dialect' => 'oracle'])
        ->expectsOutput("Unknown dialect: 'oracle'. Valid dialects: mysql, mariadb, pgsql, sqlsrv, sqlite.")
        ->assertExitCode(4);
});

it('re-prompts for an empty dump dialect and ZIP password and encrypts the archive password', function (): void {
    $this->app->instance(ConfigService::class, fakeConfigExpecting(
        static fn (ConnectionData $d): bool => $d->dialect?->value === 'pgsql'
            && $d->database === null
            && g4_decrypted($d->password) === 'zip'
    ));

    $this->artisan('connection:add', ['name' => 'dump1', '--type' => 'dump', '--dialect' => '', '--password' => ''])
        ->expectsQuestion('Target SQL dialect', 'pgsql')
        ->expectsQuestion('ZIP archive password (leave blank for no encryption)', 'zip')
        ->expectsConfirmation('Is this a production connection?', 'no')
        ->expectsTable(['Field', 'Value'], [
            ['Name', 'dump1'],
            ['Driver', 'dump'],
            ['Dialect', 'pgsql'],
            ['Encryption', 'AES-256'],
            ['Production', 'No'],
        ])
        ->expectsConfirmation('Save this connection?', 'yes')
        ->assertExitCode(0);
});

it('stores no password for a dump whose ZIP password is left blank', function (?string $answer): void {
    $this->app->instance(ConfigService::class, fakeConfigExpecting(
        static fn (ConnectionData $d): bool => $d->password === ''
    ));

    $this->artisan('connection:add', ['name' => 'dump1', '--type' => 'dump', '--dialect' => 'mysql'])
        ->expectsQuestion('ZIP archive password (leave blank for no encryption)', $answer)
        ->expectsConfirmation('Is this a production connection?', 'no')
        ->expectsTable(['Field', 'Value'], [
            ['Name', 'dump1'],
            ['Driver', 'dump'],
            ['Dialect', 'mysql'],
            ['Encryption', 'None'],
            ['Production', 'No'],
        ])
        ->expectsConfirmation('Save this connection?', 'yes')
        ->assertExitCode(0);
})->with([
    'empty answer' => [''],
    'no answer' => [null],
]);

it('stores an empty password for a SQLite connection and shows its summary', function (): void {
    $this->app->instance(ConfigService::class, fakeConfigExpecting(
        static fn (ConnectionData $d): bool => $d->password === '' && $d->database === '/tmp/test.db'
            && $d->host === null && $d->port === null && $d->username === null && $d->ssl === null
    ));

    $this->artisan('connection:add', ['name' => 'local_sqlite', '--type' => 'sqlite', '--database' => '/tmp/test.db'])
        ->expectsConfirmation('Is this a production connection?', 'no')
        ->expectsTable(['Field', 'Value'], [
            ['Name', 'local_sqlite'],
            ['Driver', 'sqlite'],
            ['Database', '/tmp/test.db'],
            ['Production', 'No'],
        ])
        ->expectsConfirmation('Save this connection?', 'yes')
        ->assertExitCode(0);
});

it('validates the port range boundaries', function (string $port, bool $valid): void {
    $this->app->instance(ConfigService::class, $valid
        ? fakeConfigExpecting(static fn (ConnectionData $d): bool => $d->port === (int) $port)
        : fakeConfigReadOnly());

    $pending = $this->artisan('connection:add', [...mysqlFlags(), '--port' => $port, '--no-interaction' => true]);

    if ($valid) {
        $pending->assertExitCode(0);
    } else {
        $pending->expectsOutput('Invalid port. Must be a number between 1 and 65535.')->assertExitCode(4);
    }
})->with([
    'zero' => ['0', false],
    'lowest' => ['1', true],
    'highest' => ['65535', true],
    'too high' => ['65536', false],
    'not a number' => ['abc', false],
]);

it('shows every field of a production verify connection with mutual TLS in the summary', function (): void {
    Storage::fake('local');
    Storage::disk('local')->put('certs/ca.pem', 'x');
    Storage::disk('local')->put('c.pem', 'x');
    Storage::disk('local')->put('k.pem', 'x');
    chmod(Storage::disk('local')->path('k.pem'), 0600);

    $this->app->instance(ConfigService::class, fakeConfigExpecting(
        static fn (ConnectionData $d): bool => $d->isProduction
            && $d->ssl?->mode === SslMode::Verify
            && $d->ssl->ca === 'certs/ca.pem' && $d->ssl->cert === 'c.pem' && $d->ssl->key === 'k.pem'
    ));

    $this->artisan('connection:add', [
        ...mysqlFlags(), '--production' => true, '--ssl-mode' => 'verify',
        '--ssl-ca' => 'certs/ca.pem', '--ssl-cert' => 'c.pem', '--ssl-key' => 'k.pem', '--no-interaction' => true,
    ])
        ->expectsOutput('This connection is marked as production. Destructive operations will require confirmation.')
        ->expectsTable(['Field', 'Value'], [
            ['Name', 'my_db'],
            ['Driver', 'mysql'],
            ['Host', 'db.example.com'],
            ['Port', '3306'],
            ['Database', 'mydb'],
            ['Username', 'root'],
            ['Password', '••••••••'],
            ['Transport security', 'Verify (encrypted + certificate check)'],
            ['CA certificate', 'certs/ca.pem'],
            ['Client certificate', 'c.pem'],
            ['Client key', 'k.pem'],
            ['Production', 'Yes'],
        ])
        ->expectsOutput("Connection 'my_db' added successfully.")
        ->assertExitCode(0);
});

it('keeps an explicit ssl mode on sqlsrv even with --trust-server-certificate', function (): void {
    $this->app->instance(ConfigService::class, fakeConfigExpecting(
        static fn (ConnectionData $d): bool => $d->ssl?->mode === SslMode::Disable
    ));

    $this->artisan('connection:add', [
        'name' => 'mssql', '--type' => 'sqlsrv', '--host' => 'h', '--port' => '1433', '--database' => 'd',
        '--username' => 'sa', '--password' => 'p', '--ssl-mode' => 'disable', '--trust-server-certificate' => true,
        '--no-interaction' => true,
    ])->assertExitCode(0);
});

it('rejects a lone ssl certificate option on sqlite', function (string $option): void {
    $this->app->instance(ConfigService::class, fakeConfigReadOnly());

    $this->artisan('connection:add', ['name' => 'local', '--type' => 'sqlite', '--database' => '/tmp/a.db', $option => 'x.pem'])
        ->expectsOutput('ssl is only supported for network connections')
        ->assertExitCode(4);
})->with(['--ssl-ca', '--ssl-cert', '--ssl-key']);

it('applies the prompt defaults when every question is answered with Enter', function (): void {
    $this->app->instance(ConfigService::class, fakeConfigExpecting(
        static fn (ConnectionData $d): bool => $d->ssl?->mode === SslMode::Require
            && ! $d->ssl->hasCertificateFiles()
            && $d->isProduction === false
    ));

    // Transport security, client certificate, production, save.
    $tester = g4_runAdd(mysqlFlags(), ['', '', '', '']);

    expect($tester->getStatusCode())->toBe(0)
        ->and($tester->getDisplay())->toContain("Connection 'my_db' added successfully.");
});

it('does not ask for client certificate paths when the mutual TLS prompt is answered with Enter', function (): void {
    $this->app->instance(ConfigService::class, fakeConfigExpecting(
        static fn (ConnectionData $d): bool => $d->ssl?->mode === SslMode::Require
            && ! $d->ssl->hasCertificateFiles()
            && $d->isProduction === true
    ));

    // Transport security, client certificate (Enter = no), production (yes), save.
    $tester = g4_runAdd(mysqlFlags(), ['', '', 'yes', '']);

    expect($tester->getStatusCode())->toBe(0)
        ->and($tester->getDisplay())->not->toContain('Client certificate path');
});
