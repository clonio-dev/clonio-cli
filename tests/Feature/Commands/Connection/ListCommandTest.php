<?php

declare(strict_types=1);

use App\Data\ConnectionData;
use App\Data\SslConfig;
use App\Enums\DatabaseConnectionType;
use App\Enums\SslMode;
use App\Services\Config\ConfigService;

it('shows message when no connections are configured', function (): void {
    $config = Mockery::mock(ConfigService::class);
    $config->shouldReceive('getConnections')->andReturn([]);
    $this->app->instance(ConfigService::class, $config);

    $this->artisan('connection:list')
        ->expectsOutputToContain('No connections configured')
        ->assertExitCode(0);
});

it('lists a mysql connection', function (): void {
    $connection = new ConnectionData(
        name: 'prod-db',
        type: DatabaseConnectionType::Mysql,
        host: 'db.example.com',
        port: 3306,
        database: 'myapp',
        schema: null,
        username: 'root',
        password: 'encrypted:abc',
        isProduction: true,
    );

    $config = Mockery::mock(ConfigService::class);
    $config->shouldReceive('getConnections')->andReturn(['prod-db' => $connection]);
    $this->app->instance(ConfigService::class, $config);

    $this->artisan('connection:list')
        ->expectsOutputToContain('prod-db')
        ->assertExitCode(0);
});

it('lists a sqlite connection', function (): void {
    $connection = new ConnectionData(
        name: 'local-sqlite',
        type: DatabaseConnectionType::Sqlite,
        host: null,
        port: null,
        database: '/tmp/test.sqlite',
        schema: null,
        username: null,
        password: '',
        isProduction: false,
    );

    $config = Mockery::mock(ConfigService::class);
    $config->shouldReceive('getConnections')->andReturn(['local-sqlite' => $connection]);
    $this->app->instance(ConfigService::class, $config);

    $this->artisan('connection:list')
        ->expectsOutputToContain('local-sqlite')
        ->assertExitCode(0);
});

it('shows the transport mode per connection', function (): void {
    $verify = new ConnectionData(
        name: 'prod', type: DatabaseConnectionType::Mysql, host: 'db', port: 3306, database: 'app',
        schema: null, username: 'u', password: 'encrypted:x', isProduction: true,
        ssl: new SslConfig(SslMode::Verify, 'certs/ca.pem'),
    );
    $legacy = new ConnectionData(
        name: 'old', type: DatabaseConnectionType::PostgreSQL, host: 'db', port: 5432, database: 'app',
        schema: 'public', username: 'u', password: 'encrypted:x', isProduction: false,
    );
    $sqlite = new ConnectionData(
        name: 'local', type: DatabaseConnectionType::Sqlite, host: null, port: null, database: 'a.db',
        schema: null, username: null, password: '', isProduction: false,
    );

    $config = Mockery::mock(ConfigService::class);
    $config->shouldReceive('getConnections')->andReturn(['prod' => $verify, 'old' => $legacy, 'local' => $sqlite]);
    $this->app->instance(ConfigService::class, $config);

    $this->artisan('connection:list')
        ->expectsTable(['Name', 'Driver', 'Host', 'Database', 'TLS', 'Production'], [
            ['prod', 'mysql', 'db:3306', 'app', 'verify', 'Yes'],
            ['old', 'pgsql', 'db:5432', 'app', 'default', 'No'],
            ['local', 'sqlite', '—', 'a.db', '—', 'No'],
        ])
        ->assertExitCode(0);
});
