<?php

declare(strict_types=1);

use App\Enums\ExitCode;
use Illuminate\Support\Facades\Storage;

// Issue #164 / PR #168: cloning:run with pattern tables: keys and skip: entries
// against real SQLite databases. Based on the tests in PR #168 by GALHP.

beforeEach(function (): void {
    Storage::fake('local');
});

/** Seed the source, point clonio.json at it and an empty target, and write cloning.yaml. */
function tablePatternsSetUp(string $tables): void
{
    Storage::put('source.sqlite', '');
    $pdo = new PDO('sqlite:'.Storage::path('source.sqlite'));

    foreach (['logs_2025' => 5, 'logs_2026' => 5, 'logs_critical' => 5, 'users' => 3, 'tmp_cache' => 2] as $table => $rows) {
        $pdo->exec(sprintf('CREATE TABLE %s (id INTEGER PRIMARY KEY NOT NULL, note TEXT NOT NULL)', $table));

        for ($id = 1; $id <= $rows; $id++) {
            $pdo->exec(sprintf("INSERT INTO %s VALUES (%d, 'row %d')", $table, $id, $id));
        }
    }

    $pdo->exec('ALTER TABLE logs_critical ADD COLUMN ip TEXT');
    $pdo->exec("UPDATE logs_critical SET ip = '10.0.0.1'");

    Storage::put('target.sqlite', '');
    Storage::put('clonio.json', (string) json_encode(['connections' => [
        'production-db' => ['type' => 'sqlite', 'database' => Storage::path('source.sqlite'), 'is_production' => true],
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

/** @return list<mixed> */
function tablePatternsTarget(string $table, string $column = 'id'): array
{
    $pdo = new PDO('sqlite:'.Storage::path('target.sqlite'));
    $exists = $pdo->query(sprintf("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = '%s'", $table))->fetchColumn();

    return (int) $exists === 0 ? [] : $pdo->query(sprintf('SELECT %s FROM %s ORDER BY id', $column, $table))->fetchAll(PDO::FETCH_COLUMN);
}

it('copies the tables a pattern key matches and skips the tables a pattern skip entry matches', function (): void {
    tablePatternsSetUp(<<<'YAML'
tables:
  '/^logs_(?!critical$)/':
    rows:
      strategy: last
      limit: 2
      sort_by: id
    columns:
      ip:
        strategy: "null"
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

    $this->artisan('cloning:run', ['file' => 'cloning.yaml', '--target' => 'staging', '--ci' => true])
        ->assertExitCode(ExitCode::Success->value);

    expect(tablePatternsTarget('logs_2025'))->toBe([4, 5])
        ->and(tablePatternsTarget('logs_2026'))->toBe([4, 5])
        ->and(tablePatternsTarget('logs_critical'))->toBe([1, 2, 3, 4, 5])
        ->and(tablePatternsTarget('logs_critical', 'ip'))->toBe(array_fill(0, 5, '10.0.0.1'))
        ->and(tablePatternsTarget('users'))->toBe([1, 2, 3])
        ->and(tablePatternsTarget('tmp_cache'))->toBe([]);
});

it('applies a pattern column rule to the matching tables that have the column', function (): void {
    tablePatternsSetUp(<<<'YAML'
tables:
  '/^logs_/':
    rows:
      strategy: full
    columns:
      ip:
        strategy: "null"
YAML);

    $this->artisan('cloning:run', ['file' => 'cloning.yaml', '--target' => 'staging', '--ci' => true])
        ->assertExitCode(ExitCode::Success->value);

    expect(tablePatternsTarget('logs_critical', 'ip'))->toBe(array_fill(0, 5, null))
        ->and(tablePatternsTarget('logs_2025'))->toBe([1, 2, 3, 4, 5]);
});

it('refuses a table that matches a pattern key and a name key before copying anything', function (): void {
    tablePatternsSetUp(<<<'YAML'
tables:
  '/^logs_/':
    rows:
      strategy: full
  logs_critical:
    rows:
      strategy: schema
YAML);

    $this->artisan('cloning:run', ['file' => 'cloning.yaml', '--target' => 'staging', '--ci' => true])
        ->expectsOutputToContain('Table "logs_critical" matches more than one tables: entry: "/^logs_/" and "logs_critical". Each table may match only one entry.')
        ->assertExitCode(ExitCode::ValidationError->value);

    expect(tablePatternsTarget('logs_2025'))->toBe([]);
});

it('rejects a pattern key that does not compile', function (): void {
    tablePatternsSetUp(<<<'YAML'
tables:
  '/^logs_(/':
    rows:
      strategy: full
YAML);

    $this->artisan('cloning:run', ['file' => 'cloning.yaml', '--target' => 'staging', '--ci' => true])
        ->expectsOutputToContain("Table '/^logs_(/': not a valid regular expression")
        ->assertExitCode(ExitCode::ValidationError->value);
});
