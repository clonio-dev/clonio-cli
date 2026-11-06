<?php

declare(strict_types=1);

use App\Data\Cloning\CloningConfigData;
use App\Data\Cloning\CloningOptionsData;
use App\Data\Cloning\RunResultData;
use App\Data\Cloning\TableCloningConfigData;
use App\Data\Cloning\TableRowConfigData;
use App\Data\Cloning\TableRunResultData;
use App\Data\Cloning\TableRunStatus;
use App\Data\ConnectionData;
use App\Enums\DatabaseConnectionType;
use App\Services\Cloning\CloningRunOrchestrator;
use App\Services\Schema\SchemaInspector;

// Issue #239: --only-tables must never copy a table the YAML skips, and a
// 'skip' row strategy never copies rows, whatever the caller passes in.

function skipRunSqlitePath(): string
{
    $path = tempnam(sys_get_temp_dir(), 'clonio_skip_').'.sqlite';
    touch($path);

    return $path;
}

function skipRunConnection(string $name, string $path): ConnectionData
{
    return new ConnectionData($name, DatabaseConnectionType::Sqlite, null, null, $path, null, null, '', false);
}

function skipRunSeedSource(): string
{
    $path = skipRunSqlitePath();
    $pdo = new PDO('sqlite:'.$path);
    $pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT NOT NULL)');
    $pdo->exec('CREATE TABLE audit_logs (id INTEGER PRIMARY KEY, message TEXT NOT NULL)');
    $pdo->exec("INSERT INTO users VALUES (1, 'ann'), (2, 'bob')");
    $pdo->exec("INSERT INTO audit_logs VALUES (1, 'secret'), (2, 'token')");

    return $path;
}

/**
 * @param  list<string>  $skipTables
 * @param  list<string>  $onlyTables
 */
function skipRunRun(string $auditStrategy, array $skipTables, array $onlyTables, string $sourcePath, string $targetPath): RunResultData
{
    $source = skipRunConnection('source', $sourcePath);

    return resolve(CloningRunOrchestrator::class)->run(
        config: new CloningConfigData(
            version: '1',
            connectionName: 'source',
            options: new CloningOptionsData(100, false, false, false, true, 'en_US'),
            tables: [
                new TableCloningConfigData('users', new TableRowConfigData('full', null, null), []),
                new TableCloningConfigData('audit_logs', new TableRowConfigData($auditStrategy, null, null), []),
            ],
        ),
        source: $source,
        target: skipRunConnection('target', $targetPath),
        sourceSchema: resolve(SchemaInspector::class)->inspect($source),
        skipSchema: false,
        skipTables: $skipTables,
        onlyTables: $onlyTables,
        onProgress: function (): void {},
    );
}

function skipRunTargetHasTable(string $path, string $table): bool
{
    $stmt = (new PDO('sqlite:'.$path))->prepare("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = ?");
    $stmt->execute([$table]);

    return (int) $stmt->fetchColumn() === 1;
}

function skipRunResultFor(RunResultData $result, string $table): TableRunResultData
{
    foreach ($result->tables as $tableResult) {
        if ($tableResult->tableName === $table) {
            return $tableResult;
        }
    }

    throw new RuntimeException('No result for '.$table);
}

it('never copies a skipped table named in --only-tables', function (string $auditStrategy, array $skipTables): void {
    $sourcePath = skipRunSeedSource();
    $targetPath = skipRunSqlitePath();

    $result = skipRunRun($auditStrategy, $skipTables, ['audit_logs'], $sourcePath, $targetPath);
    $audit = skipRunResultFor($result, 'audit_logs');
    $users = skipRunResultFor($result, 'users');

    expect($result->success)->toBeTrue()
        ->and(skipRunTargetHasTable($targetPath, 'audit_logs'))->toBeFalse()
        ->and($audit->status)->toBe(TableRunStatus::SkippedByFlag)
        ->and($audit->failureReason)->toBe('explicitly excluded via --skip')
        ->and($users->status)->toBe(TableRunStatus::SkippedByFlag)
        ->and($users->failureReason)->toBe('excluded by --only filter')
        ->and(count($result->tables))->toBe(2);

    @unlink($sourcePath);
    @unlink($targetPath);
})->with([
    'rows.strategy: skip' => ['skip', ['audit_logs']],
    'top-level skip: list' => ['full', ['audit_logs']],
]);

it('never copies a skip-strategy table the caller does not exclude', function (): void {
    $sourcePath = skipRunSeedSource();
    $targetPath = skipRunSqlitePath();

    $result = skipRunRun('skip', [], [], $sourcePath, $targetPath);

    expect($result->success)->toBeTrue()
        ->and(skipRunTargetHasTable($targetPath, 'audit_logs'))->toBeFalse()
        ->and(skipRunResultFor($result, 'audit_logs')->status)->toBe(TableRunStatus::SkippedByFlag)
        ->and(skipRunResultFor($result, 'users')->rowsTransferred)->toBe(2);

    @unlink($sourcePath);
    @unlink($targetPath);
});

it('still copies the --only-tables entries the config does not skip', function (): void {
    $sourcePath = skipRunSeedSource();
    $targetPath = skipRunSqlitePath();

    $result = skipRunRun('skip', ['audit_logs'], ['users', 'audit_logs'], $sourcePath, $targetPath);

    expect($result->success)->toBeTrue()
        ->and(skipRunResultFor($result, 'users')->rowsTransferred)->toBe(2)
        ->and(skipRunTargetHasTable($targetPath, 'audit_logs'))->toBeFalse()
        ->and(count($result->tables))->toBe(2);

    @unlink($sourcePath);
    @unlink($targetPath);
});

it('lists a table once when it is both skipped and outside --only-tables', function (): void {
    $sourcePath = skipRunSeedSource();
    $targetPath = skipRunSqlitePath();

    $result = skipRunRun('full', ['audit_logs'], ['users'], $sourcePath, $targetPath);

    expect($result->tables)->toHaveCount(2)
        ->and(skipRunResultFor($result, 'audit_logs')->failureReason)->toBe('explicitly excluded via --skip');

    @unlink($sourcePath);
    @unlink($targetPath);
});

it('lists a table once when the caller also passes its skip strategy', function (): void {
    $sourcePath = skipRunSeedSource();
    $targetPath = skipRunSqlitePath();

    $result = skipRunRun('skip', ['audit_logs'], [], $sourcePath, $targetPath);

    expect($result->tables)->toHaveCount(2)
        ->and(skipRunResultFor($result, 'users')->rowsTransferred)->toBe(2);

    @unlink($sourcePath);
    @unlink($targetPath);
});
