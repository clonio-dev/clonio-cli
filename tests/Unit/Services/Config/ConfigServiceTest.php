<?php

declare(strict_types=1);

use App\Data\ConnectionData;
use App\Enums\DatabaseConnectionType;
use App\Services\Config\ConfigService;

function makeTempDir(): string
{
    $dir = sys_get_temp_dir().'/clonio_test_'.uniqid();
    mkdir($dir, 0700, true);

    return $dir;
}

function removeTempDir(string $dir): void
{
    $file = $dir.'/clonio.json';
    if (file_exists($file)) {
        unlink($file);
    }
    rmdir($dir);
}

it('returns empty connections when clonio.json does not exist', function (): void {
    $dir = makeTempDir();
    $config = new ConfigService($dir);

    expect($config->exists())->toBeFalse()
        ->and($config->getConnections())->toBeEmpty();

    removeTempDir($dir);
});

it('saves and loads a connection', function (): void {
    $dir = makeTempDir();
    $config = new ConfigService($dir);

    $connection = new ConnectionData(
        name: 'staging',
        type: DatabaseConnectionType::Mysql,
        host: 'localhost',
        port: 3306,
        database: 'mydb',
        schema: null,
        username: 'root',
        password: 'encrypted:abc',
        isProduction: false,
    );

    $config->setConnection('staging', $connection);

    expect($config->exists())->toBeTrue();

    $loaded = $config->getConnection('staging');
    expect($loaded)->not->toBeNull()
        ->and($loaded->name)->toBe('staging')
        ->and($loaded->type)->toBe(DatabaseConnectionType::Mysql)
        ->and($loaded->host)->toBe('localhost')
        ->and($loaded->port)->toBe(3306)
        ->and($loaded->database)->toBe('mydb')
        ->and($loaded->username)->toBe('root')
        ->and($loaded->password)->toBe('encrypted:abc')
        ->and($loaded->isProduction)->toBeFalse();

    removeTempDir($dir);
});

it('returns null for a missing connection name', function (): void {
    $dir = makeTempDir();
    $config = new ConfigService($dir);

    expect($config->getConnection('nonexistent'))->toBeNull()
        ->and($config->hasConnection('nonexistent'))->toBeFalse();

    removeTempDir($dir);
});

it('deletes a connection', function (): void {
    $dir = makeTempDir();
    $config = new ConfigService($dir);

    $connection = new ConnectionData(
        name: 'staging',
        type: DatabaseConnectionType::Mysql,
        host: 'localhost',
        port: 3306,
        database: 'mydb',
        schema: null,
        username: 'root',
        password: 'encrypted:abc',
        isProduction: false,
    );

    $config->setConnection('staging', $connection);
    expect($config->hasConnection('staging'))->toBeTrue();

    $config->deleteConnection('staging');
    expect($config->hasConnection('staging'))->toBeFalse();

    removeTempDir($dir);
});

it('renames a connection', function (): void {
    $dir = makeTempDir();
    $config = new ConfigService($dir);

    $connection = new ConnectionData(
        name: 'staging',
        type: DatabaseConnectionType::Mysql,
        host: 'localhost',
        port: 3306,
        database: 'mydb',
        schema: null,
        username: 'root',
        password: 'encrypted:abc',
        isProduction: false,
    );

    $config->setConnection('staging', $connection);

    $renamed = new ConnectionData(
        name: 'dev',
        type: $connection->type,
        host: $connection->host,
        port: $connection->port,
        database: $connection->database,
        schema: $connection->schema,
        username: $connection->username,
        password: $connection->password,
        isProduction: $connection->isProduction,
    );
    $config->renameConnection('staging', 'dev', $renamed);

    expect($config->hasConnection('staging'))->toBeFalse()
        ->and($config->hasConnection('dev'))->toBeTrue()
        ->and($config->getConnection('dev')->name)->toBe('dev');

    removeTempDir($dir);
});

it('returns all connections', function (): void {
    $dir = makeTempDir();
    $config = new ConfigService($dir);

    foreach (['alpha', 'beta', 'gamma'] as $name) {
        $config->setConnection($name, new ConnectionData(
            name: $name,
            type: DatabaseConnectionType::Sqlite,
            host: null,
            port: null,
            database: '/tmp/test.db',
            schema: null,
            username: null,
            password: '',
            isProduction: false,
        ));
    }

    $connections = $config->getConnections();
    expect($connections)->toHaveCount(3)
        ->and(array_keys($connections))->toBe(['alpha', 'beta', 'gamma']);

    removeTempDir($dir);
});

it('throws on invalid JSON', function (): void {
    $dir = makeTempDir();
    file_put_contents($dir.'/clonio.json', 'not-valid-json');

    $config = new ConfigService($dir);

    expect(fn () => $config->load())->toThrow(RuntimeException::class, 'Invalid JSON');

    removeTempDir($dir);
});

it('sets file permissions to 0600 on save', function (): void {
    $dir = makeTempDir();
    $config = new ConfigService($dir);

    $config->setConnection('test', new ConnectionData(
        name: 'test',
        type: DatabaseConnectionType::Sqlite,
        host: null,
        port: null,
        database: '/tmp/test.db',
        schema: null,
        username: null,
        password: '',
        isProduction: false,
    ));

    $perms = fileperms($dir.'/clonio.json') & 0777;
    expect($perms)->toBe(0600);

    removeTempDir($dir);
});
