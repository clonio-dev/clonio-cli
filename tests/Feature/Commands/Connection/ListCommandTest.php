<?php

declare(strict_types=1);

use App\Data\ConnectionData;
use App\Enums\DatabaseConnectionType;
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
