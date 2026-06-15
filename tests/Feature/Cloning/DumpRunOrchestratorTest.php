<?php

declare(strict_types=1);

use App\Data\Cloning\CloningConfigData;
use App\Data\Cloning\CloningOptionsData;
use App\Data\Cloning\TableCloningConfigData;
use App\Data\Cloning\TableRowConfigData;
use App\Data\ConnectionData;
use App\Data\Schema\ColumnSchemaData;
use App\Data\Schema\DatabaseSchemaData;
use App\Data\Schema\TableSchemaData;
use App\Enums\DatabaseConnectionType;
use App\Services\Cloning\CloningRunOrchestrator;
use App\Services\SqlDump\DumpArchiver;
use App\Services\SqlDump\DumpDialectFactory;
use App\Services\SqlDump\SqlDumpService;
use Illuminate\Support\Facades\Storage;

/** Drive a real SQLite source through the dump pipeline and return the generated SQL. */
function runDumpPipeline(DatabaseConnectionType $dialect): array
{
    $dbPath = tempnam(sys_get_temp_dir(), 'clonio_src_').'.sqlite';
    $pdo = new PDO('sqlite:'.$dbPath);
    $pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, email TEXT NOT NULL)');
    $pdo->exec("INSERT INTO users (id, email) VALUES (1, 'alice@example.com'), (2, 'bob@example.com')");
    $pdo = null;

    $source = new ConnectionData('source', DatabaseConnectionType::Sqlite, null, null, $dbPath, null, null, '', false);
    $dump = new ConnectionData('dump', DatabaseConnectionType::Dump, null, null, null, null, null, '', false, dialect: $dialect);

    $schema = new DatabaseSchemaData('source', [
        new TableSchemaData('users', [
            new ColumnSchemaData('id', 'int', false, null, true),
            new ColumnSchemaData('email', 'varchar', false, null, false),
        ], []),
    ]);

    $config = new CloningConfigData(
        version: '1',
        connectionName: 'source',
        options: new CloningOptionsData(100, false, false, false, true, 'en_US'),
        tables: [new TableCloningConfigData('users', new TableRowConfigData('full', null, null), [])],
    );

    $sink = new SqlDumpService(new DumpDialectFactory, new DumpArchiver);
    $orchestrator = resolve(CloningRunOrchestrator::class);

    $result = $orchestrator->run(
        config: $config,
        source: $source,
        target: $dump,
        sourceSchema: $schema,
        skipSchema: false,
        skipTables: [],
        onlyTables: [],
        onProgress: function (): void {},
        keyRemapping: null,
        breakOnFailure: false,
        onTableStart: null,
        dumpSink: $sink,
    );

    $dumpResult = $sink->finish();

    $zip = new ZipArchive;
    $zip->open(Storage::disk('local')->path($dumpResult->zipFileName));
    $sql = (string) $zip->getFromIndex(0);
    $zip->close();

    @unlink($dbPath);

    return [$result, $dumpResult, $sql];
}

it('dumps a real SQLite source into a dialect-correct, importable ZIP', function (): void {
    Storage::fake('local');

    [$result, $dumpResult, $sql] = runDumpPipeline(DatabaseConnectionType::Sqlite);

    expect($result->success)->toBeTrue()
        ->and($result->totalRows)->toBe(2)
        ->and($dumpResult->zipFileName)->toEndWith('.zip')
        ->and($dumpResult->encrypted)->toBeFalse();

    expect($sql)->toContain('CREATE TABLE IF NOT EXISTS "users"')
        ->and($sql)->toContain('INSERT OR REPLACE INTO "users" ("id", "email") VALUES')
        ->and($sql)->toContain("(1, 'alice@example.com')")
        ->and($sql)->toContain("(2, 'bob@example.com')")
        ->and($sql)->toContain('PRAGMA foreign_keys = OFF;');
});

it('honours the chosen target dialect when source is SQLite', function (): void {
    Storage::fake('local');

    [, , $sql] = runDumpPipeline(DatabaseConnectionType::PostgreSQL);

    expect($sql)->toContain('Clonio for PostgreSQL')
        ->and($sql)->toContain('CREATE TABLE IF NOT EXISTS "users"')
        ->and($sql)->toContain('"email" VARCHAR(255) NOT NULL')
        ->and($sql)->toContain("session_replication_role = 'replica'");
});

it('reads source rows in chunks smaller than the table size', function (): void {
    Storage::fake('local');

    // chunk_size 1 forces multiple read iterations through the do/while loop.
    $dbPath = tempnam(sys_get_temp_dir(), 'clonio_src_').'.sqlite';
    $pdo = new PDO('sqlite:'.$dbPath);
    $pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, email TEXT NOT NULL)');
    $pdo->exec("INSERT INTO users (id, email) VALUES (1, 'a@x'), (2, 'b@x'), (3, 'c@x')");
    $pdo = null;

    $source = new ConnectionData('source', DatabaseConnectionType::Sqlite, null, null, $dbPath, null, null, '', false);
    $dump = new ConnectionData('dump', DatabaseConnectionType::Dump, null, null, null, null, null, '', false, dialect: DatabaseConnectionType::Mysql);
    $schema = new DatabaseSchemaData('source', [
        new TableSchemaData('users', [
            new ColumnSchemaData('id', 'int', false, null, true),
            new ColumnSchemaData('email', 'varchar', false, null, false),
        ], []),
    ]);
    $config = new CloningConfigData('1', 'source', new CloningOptionsData(1, false, false, false, true, 'en_US'), [
        new TableCloningConfigData('users', new TableRowConfigData('full', null, null), []),
    ]);

    $sink = new SqlDumpService(new DumpDialectFactory, new DumpArchiver);
    $result = resolve(CloningRunOrchestrator::class)->run(
        config: $config, source: $source, target: $dump, sourceSchema: $schema,
        skipSchema: false, skipTables: [], onlyTables: [], onProgress: function (): void {},
        keyRemapping: null, breakOnFailure: false, onTableStart: null, dumpSink: $sink,
    );
    $dumpResult = $sink->finish();

    $zip = new ZipArchive;
    $zip->open(Storage::disk('local')->path($dumpResult->zipFileName));
    $sql = (string) $zip->getFromIndex(0);
    $zip->close();
    @unlink($dbPath);

    expect($result->totalRows)->toBe(3)
        ->and(substr_count($sql, 'INSERT INTO `users`'))->toBe(3);
});
