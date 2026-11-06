<?php

declare(strict_types=1);

use App\Data\Cloning\CloningConfigData;
use App\Data\Cloning\CloningOptionsData;
use App\Data\Cloning\CloningRunSettingsData;
use App\Data\Cloning\KeyRemappingConfigData;
use App\Data\Cloning\KeyRemappingForeignKeyData;
use App\Data\Cloning\KeyRemappingTableData;
use App\Data\Cloning\TableCloningConfigData;
use App\Data\Cloning\TableRowConfigData;
use App\Data\ConnectionData;
use App\Enums\DatabaseConnectionType;
use App\Enums\KeyRemappingStrategy;
use App\Services\Cloning\CloningRunOrchestrator;
use App\Services\Cloning\KeyRemappingService;
use App\Services\Cloning\NullCloningRunObserver;
use App\Services\Schema\SchemaInspector;

// Issue #161: a polymorphic foreign key (addressable_type + addressable_id)
// is rewritten with the mapping of the table its type names. Exercised
// against real SQLite databases.

function polySqlitePath(): string
{
    $path = tempnam(sys_get_temp_dir(), 'clonio_poly_').'.sqlite';
    touch($path);

    return $path;
}

function polyConnection(string $name, string $path): ConnectionData
{
    return new ConnectionData($name, DatabaseConnectionType::Sqlite, null, null, $path, null, null, '', false);
}

function seedPolySource(): string
{
    $path = polySqlitePath();
    $pdo = new PDO('sqlite:'.$path);
    $pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT NOT NULL)');
    $pdo->exec('CREATE TABLE companies (id INTEGER PRIMARY KEY, name TEXT NOT NULL)');
    $pdo->exec('CREATE TABLE addresses (id INTEGER PRIMARY KEY, addressable_type TEXT NOT NULL, addressable_id INTEGER NOT NULL, city TEXT)');
    $pdo->exec("INSERT INTO users VALUES (1, 'Ann'), (2, 'Bob')");
    $pdo->exec("INSERT INTO companies VALUES (1, 'Acme'), (2, 'Globex')");
    $pdo->exec("INSERT INTO addresses VALUES (1, 'App\\Models\\User', 1, 'Berlin'), (2, 'company', 1, 'Hamburg'), (3, 'App\\Models\\User', 2, 'Munich'), (4, 'team', 1, 'Cologne')");

    return $path;
}

function polyConfig(): CloningConfigData
{
    $addressable = static fn (string $type): KeyRemappingForeignKeyData => new KeyRemappingForeignKeyData(
        'addresses',
        'addressable_id',
        typeColumn: 'addressable_type',
        typeValue: $type,
    );

    return new CloningConfigData(
        version: '1',
        connectionName: 'source',
        options: new CloningOptionsData(100, false, false, false, true, 'en_US'),
        tables: [
            new TableCloningConfigData('users', new TableRowConfigData('full', null, null), []),
            new TableCloningConfigData('companies', new TableRowConfigData('full', null, null), []),
            new TableCloningConfigData('addresses', new TableRowConfigData('full', null, null), []),
        ],
        keyRemapping: new KeyRemappingConfigData([
            new KeyRemappingTableData('users', 'id', KeyRemappingStrategy::NewUuid, [$addressable('App\Models\User')]),
            new KeyRemappingTableData('companies', 'id', KeyRemappingStrategy::NewUuid, [$addressable('company')]),
        ]),
    );
}

it('rewrites each polymorphic row with the mapping of its own type', function (): void {
    $sourcePath = seedPolySource();
    $targetPath = polySqlitePath();
    $source = polyConnection('source', $sourcePath);
    $target = polyConnection('target', $targetPath);
    $config = polyConfig();
    $schema = resolve(SchemaInspector::class)->inspect($source);

    $remapping = resolve(KeyRemappingService::class);
    $remapping->generateMappings($config->keyRemapping, $source, $schema, ['users', 'companies', 'addresses']);

    $result = resolve(CloningRunOrchestrator::class)->run(
        $config,
        $source,
        $target,
        $schema,
        new NullCloningRunObserver,
        new CloningRunSettingsData(keyRemapping: $remapping),
    );

    $pdo = new PDO('sqlite:'.$targetPath);
    $userIds = $pdo->query('SELECT name, id FROM users')->fetchAll(PDO::FETCH_KEY_PAIR);
    $companyIds = $pdo->query('SELECT name, id FROM companies')->fetchAll(PDO::FETCH_KEY_PAIR);
    $addresses = $pdo->query('SELECT city, addressable_id FROM addresses')->fetchAll(PDO::FETCH_KEY_PAIR);

    expect($result->success)->toBeTrue()
        ->and($userIds['Ann'])->not->toBe($companyIds['Acme'])
        ->and($addresses['Berlin'])->toBe($userIds['Ann'])
        ->and($addresses['Munich'])->toBe($userIds['Bob'])
        ->and($addresses['Hamburg'])->toBe($companyIds['Acme'])
        // No entry for "team": its rows keep the source id.
        ->and((string) $addresses['Cologne'])->toBe('1');

    $remapping->cleanup();
    @unlink($sourcePath);
    @unlink($targetPath);
});
