<?php

declare(strict_types=1);

use App\Data\ConnectionData;
use App\Enums\DatabaseConnectionType;
use App\Services\Config\ConfigService;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    Storage::fake('local');
});

it('returns empty connections when clonio.json does not exist', function (): void {
    $config = new ConfigService;

    expect($config->exists())->toBeFalse()
        ->and($config->getConnections())->toBeEmpty();
});

it('saves and loads a connection', function (): void {
    $config = new ConfigService;

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

    Storage::assertExists('clonio.json');

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
});

it('returns null for a missing connection name', function (): void {
    $config = new ConfigService;

    expect($config->getConnection('nonexistent'))->toBeNull()
        ->and($config->hasConnection('nonexistent'))->toBeFalse();
});

it('deletes a connection', function (): void {
    $config = new ConfigService;

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
});

it('renames a connection', function (): void {
    $config = new ConfigService;

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
});

it('returns all connections', function (): void {
    $config = new ConfigService;

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
});

it('throws on invalid JSON', function (): void {
    Storage::put('clonio.json', 'not-valid-json');

    $config = new ConfigService;

    expect(fn () => $config->load())->toThrow(RuntimeException::class, 'Invalid JSON');
});

it('sets file permissions to 0600 on save', function (): void {
    $config = new ConfigService;

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

    $perms = fileperms($config->getConfigPath()) & 0777;
    expect($perms)->toBe(0600);
});

// ─── Audit channel methods ────────────────────────────────────────────────────

it('returns empty audit channels when none are configured', function (): void {
    $config = new ConfigService;

    expect($config->getAuditChannels())->toBeEmpty()
        ->and($config->getAuditChannel('missing'))->toBeNull()
        ->and($config->hasAuditChannel('missing'))->toBeFalse();
});

it('saves and retrieves an audit channel', function (): void {
    $config = new ConfigService;
    $channelConfig = ['type' => 'local', 'audit_log' => ['path' => './logs']];

    $config->setAuditChannel('my-local', $channelConfig);

    expect($config->hasAuditChannel('my-local'))->toBeTrue()
        ->and($config->getAuditChannel('my-local'))->toBe($channelConfig);
});

it('saves multiple audit channels independently', function (): void {
    $config = new ConfigService;

    $config->setAuditChannel('ch1', ['type' => 'local']);
    $config->setAuditChannel('ch2', ['type' => 's3', 'bucket' => 'my-bucket']);

    $channels = $config->getAuditChannels();
    expect($channels)->toHaveCount(2)
        ->and(array_keys($channels))->toBe(['ch1', 'ch2']);
});

it('deletes an audit channel and removes it from deliver_to lists', function (): void {
    $config = new ConfigService;

    $config->setAuditChannel('ch1', ['type' => 'local']);
    $config->setAuditDeliverTo('audit_log', ['ch1']);
    $config->setAuditDeliverTo('run_log', ['ch1']);

    $config->deleteAuditChannel('ch1');

    expect($config->hasAuditChannel('ch1'))->toBeFalse()
        ->and($config->getAuditDeliverTo('audit_log'))->toBeEmpty()
        ->and($config->getAuditDeliverTo('run_log'))->toBeEmpty();
});

it('deleteAuditChannel is a no-op when audit section is missing', function (): void {
    $config = new ConfigService;
    // Should not throw
    $config->deleteAuditChannel('nonexistent');
    expect($config->getAuditChannels())->toBeEmpty();
});

it('saves and retrieves audit deliver_to lists', function (): void {
    $config = new ConfigService;

    $config->setAuditChannel('ch1', ['type' => 'local']);
    $config->setAuditDeliverTo('audit_log', ['ch1']);
    $config->setAuditDeliverTo('run_log', []);

    expect($config->getAuditDeliverTo('audit_log'))->toBe(['ch1'])
        ->and($config->getAuditDeliverTo('run_log'))->toBeEmpty();
});

it('getAuditDeliverTo returns empty array when audit section is absent', function (): void {
    $config = new ConfigService;
    expect($config->getAuditDeliverTo('audit_log'))->toBeEmpty();
});
