<?php

declare(strict_types=1);

use App\Data\ConnectionData;
use App\Data\SslConfig;
use App\Enums\DatabaseConnectionType;
use App\Enums\SslMode;
use App\Services\Config\ConfigService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Console\Tester\CommandTester;

beforeEach(function (): void {
    config(['app.key' => 'base64:ROzyPViGEkER6n3g0OHblde5CygEIcuDlAFbca99xvM=']);
});

/** @return array<string, string> */
function g4_sslMysqlFlags(): array
{
    return [
        'name' => 'my_db', '--type' => 'mysql', '--host' => 'db.example.com', '--port' => '3306',
        '--database' => 'mydb', '--username' => 'root', '--password' => 'secret',
    ];
}

function g4_sslAddConfig(?Closure $check = null): ConfigService
{
    $mock = Mockery::mock(ConfigService::class);
    $mock->shouldReceive('hasConnection')->andReturn(false);

    if ($check instanceof Closure) {
        $mock->shouldReceive('setConnection')->once()->withArgs(
            static fn (string $name, ConnectionData $data): bool => (bool) $check($data)
        );
    } else {
        $mock->shouldNotReceive('setConnection');
    }

    return $mock;
}

function g4_sslUpdateConfig(ConnectionData $current, Closure $check): ConfigService
{
    $mock = Mockery::mock(ConfigService::class);
    $mock->shouldReceive('getConnections')->andReturn([$current->name => $current]);
    $mock->shouldReceive('getConnection')->with($current->name)->andReturn($current);
    $mock->shouldReceive('hasConnection')->andReturn(true);
    $mock->shouldReceive('setConnection')->once()->withArgs(
        static fn (string $name, ConnectionData $data): bool => (bool) $check($data)
    );

    return $mock;
}

function g4_sslMysqlConnection(?SslConfig $ssl): ConnectionData
{
    return new ConnectionData(
        name: 'staging',
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

it('pre-selects Driver default when the current connection has no ssl block', function (): void {
    $this->app->instance(ConfigService::class, g4_sslUpdateConfig(
        g4_sslMysqlConnection(null),
        static fn (ConnectionData $d): bool => $d->ssl === null && $d->host === 'localhost'
    ));

    $tester = new CommandTester(Artisan::all()['connection:update']);
    // name, driver, host, port, database, username, password, transport security, production, save
    $tester->setInputs(array_fill(0, 10, ''));
    $tester->execute(['name' => 'staging'], ['interactive' => true]);

    expect($tester->getStatusCode())->toBe(0)
        ->and($tester->getDisplay())->toContain("Connection 'staging' updated successfully.");
});

it('keeps the current client certificate and key without interaction', function (): void {
    $current = g4_sslMysqlConnection(new SslConfig(SslMode::Require, null, 'c.pem', 'k.pem'));

    $this->app->instance(ConfigService::class, g4_sslUpdateConfig(
        $current,
        static fn (ConnectionData $d): bool => $d->ssl?->mode === SslMode::Require
            && $d->ssl->ca === null && $d->ssl->cert === 'c.pem' && $d->ssl->key === 'k.pem'
    ));

    $this->artisan('connection:update', ['name' => 'staging', '--no-interaction' => true])
        ->assertExitCode(0);
});

it('only asks for the missing key when a client certificate is given via flag', function (): void {
    Storage::fake('local');
    Storage::disk('local')->put('c.pem', 'x');
    Storage::disk('local')->put('k.pem', 'x');
    chmod(Storage::disk('local')->path('k.pem'), 0600);

    $this->app->instance(ConfigService::class, g4_sslAddConfig(
        static fn (ConnectionData $d): bool => $d->ssl?->cert === 'c.pem' && $d->ssl->key === 'k.pem'
    ));

    $this->artisan('connection:add', [...g4_sslMysqlFlags(), '--ssl-cert' => 'c.pem'])
        ->expectsQuestion('Transport security', 'Require (encrypted, not verified)')
        ->expectsQuestion('Client key path (leave empty for none)', 'k.pem')
        ->expectsConfirmation('Is this a production connection?', 'no')
        ->expectsConfirmation('Save this connection?', 'yes')
        ->assertExitCode(0);
});

it('treats a blank or "none" certificate answer as no file', function (string $answer): void {
    Storage::fake('local');
    $this->app->instance(ConfigService::class, g4_sslAddConfig());

    $this->artisan('connection:add', g4_sslMysqlFlags())
        ->expectsQuestion('Transport security', 'Verify (encrypted + certificate check)')
        ->expectsQuestion('CA certificate path (leave empty for none)', $answer)
        ->expectsConfirmation('Use a client certificate (mutual TLS)?', 'no')
        ->expectsOutput('mode verify requires a CA certificate for MySQL/MariaDB')
        ->doesntExpectOutputToContain('Certificate file not found')
        ->assertExitCode(4);
})->with([
    'whitespace' => ['   '],
    'upper-case none with padding' => [' NONE '],
]);

it('trims the entered certificate path', function (): void {
    Storage::fake('local');
    Storage::disk('local')->put('certs/ca.pem', 'x');

    $this->app->instance(ConfigService::class, g4_sslAddConfig(
        static fn (ConnectionData $d): bool => $d->ssl?->ca === 'certs/ca.pem'
    ));

    $this->artisan('connection:add', g4_sslMysqlFlags())
        ->expectsQuestion('Transport security', 'Verify (encrypted + certificate check)')
        ->expectsQuestion('CA certificate path (leave empty for none)', '  certs/ca.pem  ')
        ->expectsConfirmation('Use a client certificate (mutual TLS)?', 'no')
        ->expectsConfirmation('Is this a production connection?', 'no')
        ->expectsConfirmation('Save this connection?', 'yes')
        ->assertExitCode(0);
});

it('reports each unreadable certificate file with its resolved path', function (): void {
    Storage::fake('local');
    $this->app->instance(ConfigService::class, g4_sslAddConfig());

    $this->artisan('connection:add', [...g4_sslMysqlFlags(), '--ssl-mode' => 'verify', '--ssl-ca' => 'certs/missing.pem', '--no-interaction' => true])
        ->expectsOutput('Certificate file not found or not readable: '.Storage::disk('local')->path('certs/missing.pem'))
        ->assertExitCode(4);
});

it('skips the file check when the ssl block already has violations', function (): void {
    Storage::fake('local');
    $this->app->instance(ConfigService::class, g4_sslAddConfig());

    $this->artisan('connection:add', [...g4_sslMysqlFlags(), '--ssl-mode' => 'require', '--ssl-ca' => 'certs/missing.pem', '--no-interaction' => true])
        ->expectsOutputToContain('a CA certificate is only used with mode verify')
        ->doesntExpectOutputToContain('(pass --ssl-mode=verify)')
        ->doesntExpectOutputToContain('Certificate file not found or not readable')
        ->assertExitCode(4);
});
