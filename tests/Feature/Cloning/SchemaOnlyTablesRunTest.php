<?php

declare(strict_types=1);

use App\Data\Cloning\CloningConfigData;
use App\Data\Cloning\CloningOptionsData;
use App\Data\Cloning\RunResultData;
use App\Data\Cloning\TableCloningConfigData;
use App\Data\Cloning\TableRowConfigData;
use App\Data\Cloning\TableRunResultData;
use App\Data\ConnectionData;
use App\Enums\ClearMode;
use App\Enums\DatabaseConnectionType;
use App\Services\Cloning\CloningRunOrchestrator;
use App\Services\Schema\SchemaInspector;
use App\Services\SqlDump\DumpArchiver;
use App\Services\SqlDump\DumpDialectFactory;
use App\Services\SqlDump\SqlDumpService;
use Illuminate\Support\Facades\Storage;

// Issue #157: the 'schema' row strategy creates the table on the target but
// copies no rows. Exercised against real SQLite databases.

function schemaOnlySqlitePath(): string
{
    $path = tempnam(sys_get_temp_dir(), 'clonio_schema_').'.sqlite';
    touch($path);

    return $path;
}

function schemaOnlyConnection(string $name, string $path): ConnectionData
{
    return new ConnectionData($name, DatabaseConnectionType::Sqlite, null, null, $path, null, null, '', false);
}

function seedSchemaOnlySource(): string
{
    $path = schemaOnlySqlitePath();
    $pdo = new PDO('sqlite:'.$path);
    $pdo->exec('CREATE TABLE teams (id INTEGER PRIMARY KEY, name TEXT NOT NULL)');
    $pdo->exec('CREATE TABLE members (id INTEGER PRIMARY KEY, team_id INTEGER REFERENCES teams(id), nickname TEXT)');
    $pdo->exec('CREATE TABLE cache (key TEXT PRIMARY KEY, value TEXT NOT NULL, expiration INTEGER NOT NULL)');
    $pdo->exec("INSERT INTO teams VALUES (1, 'Red'), (2, 'Blue')");
    $pdo->exec("INSERT INTO members VALUES (1, 1, 'ace'), (2, 2, 'bee')");
    $pdo->exec("INSERT INTO cache VALUES ('k1', 'secret', 0), ('k2', 'token', 0)");

    return $path;
}

function schemaOnlyConfig(ClearMode $cacheClear = ClearMode::None): CloningConfigData
{
    return new CloningConfigData(
        version: '1',
        connectionName: 'source',
        options: new CloningOptionsData(100, false, false, false, true, 'en_US'),
        tables: [
            new TableCloningConfigData('teams', new TableRowConfigData('schema', null, null), []),
            new TableCloningConfigData('members', new TableRowConfigData('full', null, null), []),
            new TableCloningConfigData('cache', new TableRowConfigData('schema', null, null, $cacheClear), []),
        ],
    );
}

function runSchemaOnly(ConnectionData $source, ConnectionData $target, bool $skipSchema, ClearMode $cacheClear = ClearMode::None, ?SqlDumpService $sink = null): RunResultData
{
    return resolve(CloningRunOrchestrator::class)->run(
        config: schemaOnlyConfig($cacheClear),
        source: $source,
        target: $target,
        sourceSchema: resolve(SchemaInspector::class)->inspect($source),
        skipSchema: $skipSchema,
        skipTables: [],
        onlyTables: [],
        onProgress: function (): void {},
        dumpSink: $sink,
    );
}

function countRows(string $path, string $table): int
{
    return (int) (new PDO('sqlite:'.$path))->query(sprintf('SELECT COUNT(*) FROM "%s"', $table))->fetchColumn();
}

function resultFor(RunResultData $result, string $table): TableRunResultData
{
    foreach ($result->tables as $tableResult) {
        if ($tableResult->tableName === $table) {
            return $tableResult;
        }
    }

    throw new RuntimeException('No result for '.$table);
}

it('creates schema-only tables on the target without copying rows', function (): void {
    $sourcePath = seedSchemaOnlySource();
    $targetPath = schemaOnlySqlitePath();

    $result = runSchemaOnly(schemaOnlyConnection('source', $sourcePath), schemaOnlyConnection('target', $targetPath), false);

    expect($result->success)->toBeTrue()
        ->and(countRows($targetPath, 'cache'))->toBe(0)
        ->and(countRows($targetPath, 'teams'))->toBe(0)
        ->and(countRows($targetPath, 'members'))->toBe(2)
        ->and(resultFor($result, 'cache')->rowsTransferred)->toBe(0);

    @unlink($sourcePath);
    @unlink($targetPath);
});

it('notes foreign keys that point to schema-only tables', function (): void {
    $sourcePath = seedSchemaOnlySource();
    $targetPath = schemaOnlySqlitePath();

    $result = runSchemaOnly(schemaOnlyConnection('source', $sourcePath), schemaOnlyConnection('target', $targetPath), false);

    expect(resultFor($result, 'members')->notes)->toContain(
        "References tables copied without data ('schema' strategy): teams. Foreign key values may point to missing rows, or inserts fail when foreign key checks are enabled."
    )->and(resultFor($result, 'cache')->notes)->toBe([]);

    @unlink($sourcePath);
    @unlink($targetPath);
});

it('honours rows.clear for schema-only tables', function (ClearMode $clear, int $expected): void {
    $sourcePath = seedSchemaOnlySource();
    $targetPath = schemaOnlySqlitePath();
    $target = new PDO('sqlite:'.$targetPath);
    $target->exec('CREATE TABLE teams (id INTEGER PRIMARY KEY, name TEXT NOT NULL)');
    $target->exec('CREATE TABLE members (id INTEGER PRIMARY KEY, team_id INTEGER REFERENCES teams(id), nickname TEXT)');
    $target->exec('CREATE TABLE cache (key TEXT PRIMARY KEY, value TEXT NOT NULL, expiration INTEGER NOT NULL)');
    $target->exec("INSERT INTO cache VALUES ('local', 'dev', 0)");
    unset($target);

    $result = runSchemaOnly(schemaOnlyConnection('source', $sourcePath), schemaOnlyConnection('target', $targetPath), true, $clear);

    expect($result->success)->toBeTrue()
        ->and(countRows($targetPath, 'cache'))->toBe($expected);

    @unlink($sourcePath);
    @unlink($targetPath);
})->with([
    'none keeps target rows' => [ClearMode::None, 1],
    'delete empties the table' => [ClearMode::Delete, 0],
]);

it('dumps the DDL of schema-only tables without INSERT statements', function (): void {
    Storage::fake('local');
    $sourcePath = seedSchemaOnlySource();
    $dump = new ConnectionData('dump', DatabaseConnectionType::Dump, null, null, null, null, null, '', false, dialect: DatabaseConnectionType::Mysql);
    $sink = new SqlDumpService(new DumpDialectFactory, new DumpArchiver);

    $result = runSchemaOnly(schemaOnlyConnection('source', $sourcePath), $dump, false, sink: $sink);
    $dumpResult = $sink->finish();

    $zip = new ZipArchive;
    $zip->open(Storage::disk('local')->path($dumpResult->zipFileName));
    $sql = (string) $zip->getFromIndex(0);
    $zip->close();

    expect($result->success)->toBeTrue()
        ->and($sql)->toContain('CREATE TABLE IF NOT EXISTS `cache`')
        ->and($sql)->toContain('CREATE TABLE IF NOT EXISTS `teams`')
        ->and($sql)->not->toContain('INSERT INTO `cache`')
        ->and($sql)->not->toContain('INSERT INTO `teams`')
        ->and($sql)->toContain('INSERT INTO `members`')
        ->and($sql)->not->toContain('secret');

    @unlink($sourcePath);
});
