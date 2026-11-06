<?php

declare(strict_types=1);

use App\Data\Cloning\CloningConfigData;
use App\Data\Cloning\CloningOptionsData;
use App\Data\Cloning\RunResultData;
use App\Data\Cloning\TableCloningConfigData;
use App\Data\Cloning\TableRowConfigData;
use App\Data\ConnectionData;
use App\Enums\DatabaseConnectionType;
use App\Services\Cloning\CloningRunOrchestrator;
use App\Services\Schema\SchemaInspector;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;

// Chunked reads against real SQLite databases: every row must arrive exactly once,
// whether the table is paged with a keyset cursor or falls back to LIMIT/OFFSET.

beforeEach(function (): void {
    Storage::fake('local');
});

/** An empty SQLite database file on the fake disk. */
function keysetSqlitePath(string $name): string
{
    Storage::put($name, '');

    return Storage::path($name);
}

function keysetConnection(string $name, string $path): ConnectionData
{
    return new ConnectionData($name, DatabaseConnectionType::Sqlite, null, null, $path, null, null, '', false);
}

/**
 * 23 events with duplicate ranks and a NULL created_at on every third row. `events`
 * declares its key NOT NULL (keyset); `legacy_events` uses a plain rowid key,
 * which SQLite reports as nullable (LIMIT/OFFSET). `scores` sorts by a REAL with
 * tied values whose text form loses digits; `blobs` has a BLOB primary key that
 * SQLite sorts after any bound text value.
 */
function seedKeysetSource(): string
{
    $path = keysetSqlitePath('source.sqlite');
    $pdo = new PDO('sqlite:'.$path);

    foreach (['events' => 'INTEGER PRIMARY KEY NOT NULL', 'legacy_events' => 'INTEGER PRIMARY KEY'] as $table => $key) {
        $pdo->exec(sprintf('CREATE TABLE %s (id %s, rank INTEGER NOT NULL, created_at TEXT)', $table, $key));

        for ($id = 1; $id <= 23; $id++) {
            $createdAt = $id % 3 === 0 ? 'NULL' : sprintf("'2026-01-%02d'", $id);
            $pdo->exec(sprintf('INSERT INTO %s VALUES (%d, %d, %s)', $table, $id, $id % 5, $createdAt));
        }
    }

    $pdo->exec('CREATE TABLE scores (id INTEGER PRIMARY KEY NOT NULL, score REAL NOT NULL)');
    $pdo->exec('INSERT INTO scores VALUES (1, 0.1 + 0.2), (2, 0.1 + 0.2), (3, 0.5), (4, 0.7), (5, 0.9)');
    $pdo->exec('CREATE TABLE blobs (id BLOB PRIMARY KEY NOT NULL, n INTEGER NOT NULL)');
    $pdo->exec("INSERT INTO blobs VALUES (x'01', 1), (x'02', 2), (x'03', 3), (x'04', 4), (x'05', 5)");

    return $path;
}

/**
 * @return array{RunResultData, list<string>}
 */
function runKeyset(string $sourcePath, string $targetPath, string $table, TableRowConfigData $rows): array
{
    $source = keysetConnection('source', $sourcePath);
    $queries = [];
    Event::listen(QueryExecuted::class, static function (QueryExecuted $query) use (&$queries): void {
        $queries[] = $query->sql;
    });

    $result = resolve(CloningRunOrchestrator::class)->run(
        new CloningConfigData(
            version: '1',
            connectionName: 'source',
            options: new CloningOptionsData(4, false, false, false, false, 'en_US'),
            tables: [new TableCloningConfigData($table, $rows, [])],
        ),
        $source,
        keysetConnection('target', $targetPath),
        resolve(SchemaInspector::class)->inspect($source),
        function (): void {},
    );

    return [$result, $queries];
}

/** @return list<int> */
function keysetTargetIds(string $path, string $table): array
{
    return array_map(intval(...), (new PDO('sqlite:'.$path))->query(sprintf('SELECT id FROM %s ORDER BY id', $table))->fetchAll(PDO::FETCH_COLUMN));
}

/**
 * The ids a strategy should copy: sorted by (rank, id) in the given direction.
 *
 * @return list<int>
 */
function keysetExpectedIds(int $limit, bool $descending): array
{
    $ids = range(1, 23);
    usort($ids, static fn (int $a, int $b): int => [$a % 5, $a] <=> [$b % 5, $b]);
    if ($descending) {
        $ids = array_reverse($ids);
    }

    $ids = array_slice($ids, 0, $limit);
    sort($ids);

    return $ids;
}

it('copies every row exactly once with a keyset cursor', function (TableRowConfigData $rows, array $expectedIds, string $seek): void {
    $sourcePath = seedKeysetSource();
    $targetPath = keysetSqlitePath('target.sqlite');

    [$result, $queries] = runKeyset($sourcePath, $targetPath, 'events', $rows);

    expect($result->success)->toBeTrue()
        ->and($result->totalRows)->toBe(count($expectedIds))
        ->and(keysetTargetIds($targetPath, 'events'))->toBe($expectedIds)
        ->and(array_filter($queries, static fn (string $sql): bool => str_contains($sql, $seek)))->not->toBeEmpty()
        ->and(array_filter($queries, static fn (string $sql): bool => str_contains($sql, 'OFFSET')))->toBeEmpty();
})->with([
    'full' => [new TableRowConfigData('full', null, null), range(1, 23), 'WHERE ("id") > (?)'],
    'first by a column with duplicates' => [new TableRowConfigData('first', 10, 'rank'), keysetExpectedIds(10, descending: false), 'WHERE ("rank", "id") > (?, ?)'],
    'last by a column with duplicates' => [new TableRowConfigData('last', 7, 'rank'), keysetExpectedIds(7, descending: true), 'WHERE ("rank", "id") < (?, ?)'],
]);

it('copies every row exactly once when it falls back to LIMIT/OFFSET', function (string $table, TableRowConfigData $rows, int $expectedRows): void {
    $sourcePath = seedKeysetSource();
    $targetPath = keysetSqlitePath('target.sqlite');

    [$result, $queries] = runKeyset($sourcePath, $targetPath, $table, $rows);

    expect($result->success)->toBeTrue()
        ->and($result->totalRows)->toBe($expectedRows)
        ->and((int) (new PDO('sqlite:'.$targetPath))->query(sprintf('SELECT COUNT(DISTINCT id) FROM %s', $table))->fetchColumn())->toBe($expectedRows)
        ->and(array_filter($queries, static fn (string $sql): bool => str_contains($sql, 'OFFSET')))->not->toBeEmpty();
})->with([
    'nullable sort column' => ['events', new TableRowConfigData('first', 15, 'created_at'), 15],
    'rowid key SQLite reports as nullable' => ['legacy_events', new TableRowConfigData('full', null, null), 23],
    'REAL sort column with tied values' => ['scores', new TableRowConfigData('first', 5, 'score'), 5],
    'BLOB primary key' => ['blobs', new TableRowConfigData('full', null, null), 5],
]);
