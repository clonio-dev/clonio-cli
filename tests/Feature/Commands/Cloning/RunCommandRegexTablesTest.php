<?php

declare(strict_types=1);

use App\Enums\ExitCode;
use Illuminate\Support\Facades\Storage;

// cloning:run with regex table keys against real SQLite databases.

beforeEach(function (): void {
    Storage::fake('local');
});

/** Seed the source: two log years, a critical log, users and a cache table. */
function regexTablesSeedSource(): string
{
    Storage::put('source.sqlite', '');
    $pdo = new PDO('sqlite:'.Storage::path('source.sqlite'));

    foreach (['logs_2025' => 5, 'logs_2026' => 5, 'logs_critical' => 5, 'users' => 3, 'tmp_cache' => 2] as $table => $rows) {
        $pdo->exec(sprintf('CREATE TABLE %s (id INTEGER PRIMARY KEY NOT NULL, note TEXT NOT NULL)', $table));

        for ($id = 1; $id <= $rows; $id++) {
            $pdo->exec(sprintf("INSERT INTO %s VALUES (%d, 'row %d')", $table, $id, $id));
        }
    }

    return Storage::path('source.sqlite');
}

/** @return list<int> */
function regexTablesTargetIds(string $table): array
{
    $pdo = new PDO('sqlite:'.Storage::path('target.sqlite'));
    $exists = $pdo->query(sprintf("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = '%s'", $table))->fetchColumn();

    return (int) $exists === 0
        ? []
        : array_map(intval(...), $pdo->query(sprintf('SELECT id FROM %s ORDER BY id', $table))->fetchAll(PDO::FETCH_COLUMN));
}

it('copies tables matched by regex keys, lets the last match win and skips by regex', function (): void {
    $source = regexTablesSeedSource();
    Storage::put('target.sqlite', '');
    Storage::put('clonio.json', (string) json_encode(['connections' => [
        'production-db' => ['type' => 'sqlite', 'database' => $source, 'is_production' => true],
        'staging' => ['type' => 'sqlite', 'database' => Storage::path('target.sqlite'), 'is_production' => false],
    ]]));
    Storage::put('regex.cloning.yaml', <<<'YAML'
version: "1"
connection: production-db
options:
  chunk_size: 2
  enforce_column_types: false
  drop_unknown_tables: false
  disable_foreign_key_checks: true
  faker_locale: en_US
tables:
  '/^logs_/':
    rows:
      strategy: last
      limit: 2
      sort_by: id
  logs_critical:
    rows:
      strategy: full
  Users:
    rows:
      strategy: full
  '/^tmp_/':
    rows:
      strategy: full
skip:
  - '/^tmp_/'
YAML);

    $this->artisan('cloning:run', ['file' => 'regex.cloning.yaml', '--target' => 'staging'])
        ->assertExitCode(ExitCode::Success->value);

    expect(regexTablesTargetIds('logs_2025'))->toBe([4, 5])
        ->and(regexTablesTargetIds('logs_2026'))->toBe([4, 5])
        ->and(regexTablesTargetIds('logs_critical'))->toBe([1, 2, 3, 4, 5]) // the later literal entry wins
        ->and(regexTablesTargetIds('users'))->toBe([1, 2, 3]) // 'Users' falls back to the only case-insensitive match
        ->and(regexTablesTargetIds('tmp_cache'))->toBe([]); // skipped by the regex in skip:
});

it('lets a later entry override a regex skip and keeps earlier column rules', function (): void {
    $source = regexTablesSeedSource();
    Storage::put('target.sqlite', '');
    Storage::put('clonio.json', (string) json_encode(['connections' => [
        'production-db' => ['type' => 'sqlite', 'database' => $source, 'is_production' => true],
        'staging' => ['type' => 'sqlite', 'database' => Storage::path('target.sqlite'), 'is_production' => false],
    ]]));
    Storage::put('override.cloning.yaml', <<<'YAML'
version: "1"
connection: production-db
options:
  chunk_size: 2
  enforce_column_types: false
  drop_unknown_tables: false
  disable_foreign_key_checks: true
  faker_locale: en_US
tables:
  users:
    rows:
      strategy: full
    columns:
      note:
        strategy: static
        value: redacted
  '/^logs_/':
    rows:
      strategy: skip
  logs_critical:
    rows:
      strategy: full
  '/^users$/':
    rows:
      strategy: full
YAML);

    $this->artisan('cloning:run', ['file' => 'override.cloning.yaml', '--target' => 'staging'])
        ->assertExitCode(ExitCode::Success->value);

    $notes = (new PDO('sqlite:'.Storage::path('target.sqlite')))->query('SELECT note FROM users ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);

    expect(regexTablesTargetIds('logs_2025'))->toBe([])
        ->and(regexTablesTargetIds('logs_critical'))->toBe([1, 2, 3, 4, 5])
        ->and(regexTablesTargetIds('users'))->toBe([1, 2, 3])
        ->and($notes)->toBe(['redacted', 'redacted', 'redacted']); // the later '/^users$/' keeps the column rule
});

/** Seed the source, point clonio.json at it and an empty target, and write cloning.yaml. */
function regexTablesSetUp(string $tables): void
{
    $source = regexTablesSeedSource();
    Storage::put('target.sqlite', '');
    Storage::put('clonio.json', (string) json_encode(['connections' => [
        'production-db' => ['type' => 'sqlite', 'database' => $source, 'is_production' => true],
        'staging' => ['type' => 'sqlite', 'database' => Storage::path('target.sqlite'), 'is_production' => false],
    ]]));
    Storage::put('cloning.yaml', <<<YAML
version: "1"
connection: production-db
options:
  chunk_size: 2
  enforce_column_types: false
  drop_unknown_tables: false
  disable_foreign_key_checks: true
  faker_locale: en_US
{$tables}
YAML);
}

it('warns about a skip entry that matches no source table, also with --ci', function (): void {
    regexTablesSetUp(<<<'YAML'
tables:
  '/^tmp_/':
    rows:
      strategy: full
skip:
  - '/^tmp_cahce/'
YAML);

    $this->artisan('cloning:run', ['file' => 'cloning.yaml', '--target' => 'staging', '--ci' => true])
        ->expectsOutputToContain("Warning: skip entry '/^tmp_cahce/' matches no source table")
        ->assertExitCode(ExitCode::Success->value);

    expect(regexTablesTargetIds('tmp_cache'))->toBe([1, 2]);
});

it('resolves --skip-tables names like literal table keys', function (): void {
    regexTablesSetUp(<<<'YAML'
tables:
  Users:
    rows:
      strategy: full
  logs_critical:
    rows:
      strategy: full
YAML);

    $this->artisan('cloning:run', ['file' => 'cloning.yaml', '--target' => 'staging', '--ci' => true, '--skip-tables' => 'USERS,ghost'])
        ->expectsOutputToContain("Warning: --skip-tables 'ghost' matches no source table")
        ->assertExitCode(ExitCode::Success->value);

    expect(regexTablesTargetIds('users'))->toBe([])
        ->and(regexTablesTargetIds('logs_critical'))->toBe([1, 2, 3, 4, 5]);
});

it('resolves --only-tables names like literal table keys, copying nothing for a typo', function (string $only, array $copied): void {
    regexTablesSetUp(<<<'YAML'
tables:
  users:
    rows:
      strategy: full
  '/^logs_/':
    rows:
      strategy: full
YAML);

    $this->artisan('cloning:run', ['file' => 'cloning.yaml', '--target' => 'staging', '--ci' => true, '--only-tables' => $only])
        ->assertExitCode(ExitCode::Success->value);

    expect(array_keys(array_filter(
        array_map(regexTablesTargetIds(...), array_combine(['users', 'logs_2025', 'logs_2026', 'logs_critical'], ['users', 'logs_2025', 'logs_2026', 'logs_critical'])),
    )))->toBe($copied);
})->with([
    'case-insensitive name' => ['Users,LOGS_2025', ['users', 'logs_2025']],
    'typo' => ['ussers', []],
]);

it('keeps skipping YAML skips with --only-tables', function (): void {
    regexTablesSetUp(<<<'YAML'
tables:
  users:
    rows:
      strategy: full
  '/^tmp_/':
    rows:
      strategy: full
  '/^logs_20/':
    rows:
      strategy: skip
skip:
  - '/^tmp_/'
YAML);

    $this->artisan('cloning:run', ['file' => 'cloning.yaml', '--target' => 'staging', '--ci' => true, '--only-tables' => 'tmp_cache,users,logs_2025'])
        ->assertExitCode(ExitCode::Success->value);

    expect(regexTablesTargetIds('users'))->toBe([1, 2, 3])
        ->and(regexTablesTargetIds('tmp_cache'))->toBe([])
        ->and(regexTablesTargetIds('logs_2025'))->toBe([]);
});

it('counts only configured tables when a regex skip entry matches unconfigured ones', function (): void {
    regexTablesSetUp(<<<'YAML'
tables:
  users:
    rows:
      strategy: full
  logs_critical:
    rows:
      strategy: full
skip:
  - '/^logs_/'
  - '/^tmp_/'
YAML);

    $this->artisan('cloning:run', ['file' => 'cloning.yaml', '--target' => 'staging'])
        ->expectsOutputToContain('Tables: 1/2')
        ->assertExitCode(ExitCode::Success->value);

    expect(regexTablesTargetIds('users'))->toBe([1, 2, 3])
        ->and(regexTablesTargetIds('logs_critical'))->toBe([]);
});

it('reports a mistyped skip key as not found, also with --ci', function (): void {
    regexTablesSetUp(<<<'YAML'
tables:
  tmp_cahce:
    rows:
      strategy: skip
  users:
    rows:
      strategy: full
YAML);

    $this->artisan('cloning:run', ['file' => 'cloning.yaml', '--target' => 'staging', '--ci' => true])
        ->expectsOutputToContain('Warning: 1 table not found in source (tmp_cahce)')
        ->assertExitCode(ExitCode::Success->value);
});
