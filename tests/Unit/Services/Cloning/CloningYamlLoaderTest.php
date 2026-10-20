<?php

declare(strict_types=1);

use App\Data\Cloning\CloningConfigData;
use App\Enums\ClearMode;
use App\Services\Cloning\CloningYamlLoader;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;

it('parses options.remap_keys: false', function (): void {
    Storage::fake('local');

    $yaml = <<<'YAML'
version: "1"
connection: production-db
options:
  chunk_size: 1000
  enforce_column_types: false
  drop_unknown_tables: false
  disable_foreign_key_checks: true
  remap_keys: false
  faker_locale: en_US
tables:
  users:
    rows:
      strategy: full
YAML;

    Storage::disk('local')->put('my.cloning.yaml', $yaml);

    $config = (new CloningYamlLoader)->load('my.cloning.yaml');

    expect($config->options->remapKeys)->toBeFalse();
});

it('loads a valid cloning yaml file', function (): void {
    Storage::fake('local');

    $yaml = <<<'YAML'
version: "1"
connection: production-db
options:
  chunk_size: 500
  enforce_column_types: true
  drop_unknown_tables: false
  disable_foreign_key_checks: true
  faker_locale: en_US
tables:
  users:
    rows:
      strategy: full
    columns:
      email:
        strategy: fake
        faker_method: safeEmail
        faker_arguments: []
      password:
        strategy: hash
        algorithm: sha256
        salt: "mysalt"
YAML;

    Storage::disk('local')->put('my.cloning.yaml', $yaml);

    $loader = new CloningYamlLoader;
    $config = $loader->load('my.cloning.yaml');

    expect($config->version)->toBe('1');
    expect($config->connectionName)->toBe('production-db');
    expect($config->options->chunkSize)->toBe(500);
    expect($config->options->enforceColumnTypes)->toBeTrue();
    expect($config->options->fakerLocale)->toBe('en_US');
    expect($config->options->remapKeys)->toBeTrue(); // absent → defaults to true
    expect($config->tables)->toHaveCount(1);

    $table = $config->getTable('users');
    expect($table)->not->toBeNull();
    expect($table?->rows->strategy)->toBe('full');
    expect($table?->columns)->toHaveCount(2);

    $emailCol = $table?->getColumn('email');
    expect($emailCol)->not->toBeNull();
    expect($emailCol?->strategy)->toBe('fake');
    expect($emailCol?->fakerMethod)->toBe('safeEmail');
    expect($emailCol?->fakerArguments)->toBe([]);
});

it('throws RuntimeException when file does not exist', function (): void {
    Storage::fake('local');

    $loader = new CloningYamlLoader;

    expect(fn () => $loader->load('nonexistent.yaml'))->toThrow(RuntimeException::class, 'not found');
});

it('throws RuntimeException for invalid YAML', function (): void {
    Storage::fake('local');
    Storage::disk('local')->put('bad.yaml', "key: [unclosed bracket\n");

    $loader = new CloningYamlLoader;

    expect(fn () => $loader->load('bad.yaml'))->toThrow(RuntimeException::class);
});

it('loads hash strategy column', function (): void {
    Storage::fake('local');

    $yaml = <<<'YAML'
version: "1"
connection: prod
options:
  chunk_size: 1000
  enforce_column_types: false
  drop_unknown_tables: false
  disable_foreign_key_checks: true
  faker_locale: en_US
tables:
  users:
    rows:
      strategy: first
      limit: 100
    columns:
      password:
        strategy: hash
        algorithm: sha512
        salt: "abc123"
YAML;

    Storage::disk('local')->put('config.yaml', $yaml);

    $loader = new CloningYamlLoader;
    $config = $loader->load('config.yaml');

    $table = $config->getTable('users');
    expect($table?->rows->strategy)->toBe('first');
    expect($table?->rows->limit)->toBe(100);

    $col = $table?->getColumn('password');
    expect($col?->strategy)->toBe('hash');
    expect($col?->hashAlgorithm)->toBe('sha512');
    expect($col?->hashSalt)->toBe('abc123');
});

it('loads mask strategy column', function (): void {
    Storage::fake('local');

    $yaml = <<<'YAML'
version: "1"
connection: prod
options:
  chunk_size: 1000
  enforce_column_types: false
  drop_unknown_tables: false
  disable_foreign_key_checks: true
  faker_locale: en_US
tables:
  users:
    rows:
      strategy: full
    columns:
      phone:
        strategy: mask
        visible_chars: 4
        mask_char: "*"
        preserve_format: true
YAML;

    Storage::disk('local')->put('mask.yaml', $yaml);

    $loader = new CloningYamlLoader;
    $config = $loader->load('mask.yaml');

    $col = $config->getTable('users')?->getColumn('phone');
    expect($col?->strategy)->toBe('mask');
    expect($col?->visibleChars)->toBe(4);
    expect($col?->maskChar)->toBe('*');
    expect($col?->preserveFormat)->toBeTrue();
});

it('loads static strategy column', function (): void {
    Storage::fake('local');

    $yaml = <<<'YAML'
version: "1"
connection: prod
options:
  chunk_size: 1000
  enforce_column_types: false
  drop_unknown_tables: false
  disable_foreign_key_checks: true
  faker_locale: en_US
tables:
  users:
    rows:
      strategy: full
    columns:
      status:
        strategy: static
        value: "active"
YAML;

    Storage::disk('local')->put('static.yaml', $yaml);

    $loader = new CloningYamlLoader;
    $config = $loader->load('static.yaml');

    $col = $config->getTable('users')?->getColumn('status');
    expect($col?->strategy)->toBe('static');
    expect($col?->staticValue)->toBe('active');
});

it('getTable returns null for non-existent table', function (): void {
    Storage::fake('local');

    $yaml = <<<'YAML'
version: "1"
connection: prod
options:
  chunk_size: 1000
  enforce_column_types: false
  drop_unknown_tables: false
  disable_foreign_key_checks: true
  faker_locale: en_US
tables:
  users:
    rows:
      strategy: full
YAML;

    Storage::disk('local')->put('config.yaml', $yaml);

    $loader = new CloningYamlLoader;
    $config = $loader->load('config.yaml');

    expect($config->getTable('nonexistent'))->toBeNull();
});

it('loads key_remapping section with new_uuid strategy', function (): void {
    Storage::fake('local');

    $yaml = <<<'YAML'
version: "1"
connection: prod
options:
  chunk_size: 1000
  enforce_column_types: false
  drop_unknown_tables: false
  disable_foreign_key_checks: true
  faker_locale: en_US
tables:
  users:
    rows:
      strategy: full
key_remapping:
  tables:
    - table: users
      primary_key: id
      strategy: new_uuid
      foreign_keys:
        - table: orders
          column: user_id
YAML;

    Storage::disk('local')->put('remapping.yaml', $yaml);

    $loader = new CloningYamlLoader;
    $config = $loader->load('remapping.yaml');

    expect($config->keyRemapping)->not->toBeNull();
    expect($config->keyRemapping?->isActive())->toBeTrue();
    expect($config->keyRemapping?->tables)->toHaveCount(1);

    $table = $config->keyRemapping?->getTable('users');
    expect($table)->not->toBeNull();
    expect($table?->primaryKey)->toBe('id');
    expect($table?->strategy->value)->toBe('new_uuid');
    expect($table?->foreignKeys)->toHaveCount(1);
    expect($table?->foreignKeys[0]->table)->toBe('orders');
    expect($table?->foreignKeys[0]->column)->toBe('user_id');
});

it('loads key_remapping section with random_integer strategy', function (): void {
    Storage::fake('local');

    $yaml = <<<'YAML'
version: "1"
connection: prod
options:
  chunk_size: 1000
  enforce_column_types: false
  drop_unknown_tables: false
  disable_foreign_key_checks: true
  faker_locale: en_US
tables:
  orders:
    rows:
      strategy: full
key_remapping:
  tables:
    - table: orders
      primary_key: id
      strategy: random_integer
YAML;

    Storage::disk('local')->put('int-remapping.yaml', $yaml);

    $loader = new CloningYamlLoader;
    $config = $loader->load('int-remapping.yaml');

    $table = $config->keyRemapping?->getTable('orders');
    expect($table)->not->toBeNull();
    expect($table?->strategy->value)->toBe('random_integer');
});

it('sets keyRemapping to null when section is absent', function (): void {
    Storage::fake('local');

    $yaml = <<<'YAML'
version: "1"
connection: prod
options:
  chunk_size: 1000
  enforce_column_types: false
  drop_unknown_tables: false
  disable_foreign_key_checks: true
  faker_locale: en_US
tables:
  users:
    rows:
      strategy: full
YAML;

    Storage::disk('local')->put('no-remapping.yaml', $yaml);

    $loader = new CloningYamlLoader;
    $config = $loader->load('no-remapping.yaml');

    expect($config->keyRemapping)->toBeNull();
});

it('loads clear as false when absent', function (): void {
    Storage::fake('local');

    $yaml = <<<'YAML'
version: "1"
connection: prod
options:
  chunk_size: 1000
  enforce_column_types: false
  drop_unknown_tables: false
  disable_foreign_key_checks: true
  faker_locale: en_US
tables:
  users:
    rows:
      strategy: full
YAML;

    Storage::disk('local')->put('test.yaml', $yaml);
    $config = (new CloningYamlLoader)->load('test.yaml');

    expect($config->getTable('users')?->rows->clear)->toBe(ClearMode::None);
});

it('loads clear as truncate', function (): void {
    Storage::fake('local');

    $yaml = <<<'YAML'
version: "1"
connection: prod
options:
  chunk_size: 1000
  enforce_column_types: false
  drop_unknown_tables: false
  disable_foreign_key_checks: true
  faker_locale: en_US
tables:
  users:
    rows:
      strategy: full
      clear: truncate
YAML;

    Storage::disk('local')->put('test.yaml', $yaml);
    $config = (new CloningYamlLoader)->load('test.yaml');

    expect($config->getTable('users')?->rows->clear)->toBe(ClearMode::Truncate);
});

it('loads clear as delete', function (): void {
    Storage::fake('local');

    $yaml = <<<'YAML'
version: "1"
connection: prod
options:
  chunk_size: 1000
  enforce_column_types: false
  drop_unknown_tables: false
  disable_foreign_key_checks: true
  faker_locale: en_US
tables:
  users:
    rows:
      strategy: full
      clear: delete
YAML;

    Storage::disk('local')->put('test.yaml', $yaml);
    $config = (new CloningYamlLoader)->load('test.yaml');

    expect($config->getTable('users')?->rows->clear)->toBe(ClearMode::Delete);
});

it('loads clear as false when set to an invalid value', function (): void {
    Storage::fake('local');

    $yaml = <<<'YAML'
version: "1"
connection: prod
options:
  chunk_size: 1000
  enforce_column_types: false
  drop_unknown_keys: false
  drop_unknown_tables: false
  disable_foreign_key_checks: true
  faker_locale: en_US
tables:
  users:
    rows:
      strategy: full
      clear: drop
YAML;

    Storage::disk('local')->put('test.yaml', $yaml);
    $config = (new CloningYamlLoader)->load('test.yaml');

    // invalid values silently fall back to None
    expect($config->getTable('users')?->rows->clear)->toBe(ClearMode::None);
});

it('loads inline remapping strategy column', function (): void {
    Storage::fake('local');

    $yaml = <<<'YAML'
version: "1"
connection: prod
options:
  chunk_size: 1000
  enforce_column_types: false
  drop_unknown_tables: false
  disable_foreign_key_checks: true
  faker_locale: en_US
tables:
  users:
    rows:
      strategy: full
    columns:
      id:
        strategy: remapping
        arguments:
          - use: random_integer
          - foreign_keys: []
      email:
        strategy: fake
        faker_method: safeEmail
        faker_arguments: []
YAML;

    Storage::disk('local')->put('remapping-inline.yaml', $yaml);

    $config = (new CloningYamlLoader)->load('remapping-inline.yaml');

    // keyRemapping is built from the inline column
    expect($config->keyRemapping)->not->toBeNull();
    expect($config->keyRemapping?->isActive())->toBeTrue();
    $krTable = $config->keyRemapping?->getTable('users');
    expect($krTable)->not->toBeNull();
    expect($krTable?->primaryKey)->toBe('id');
    expect($krTable?->strategy->value)->toBe('random_integer');
    expect($krTable?->foreignKeys)->toBe([]);

    // The remapping column itself is accessible
    $idCol = $config->getTable('users')?->getColumn('id');
    expect($idCol?->strategy)->toBe('remapping');
    expect($idCol?->remappingUse)->toBe('random_integer');
});

it('loads inline remapping with new_uuid strategy', function (): void {
    Storage::fake('local');

    $yaml = <<<'YAML'
version: "1"
connection: prod
options:
  chunk_size: 1000
  enforce_column_types: false
  drop_unknown_tables: false
  disable_foreign_key_checks: true
  faker_locale: en_US
tables:
  users:
    rows:
      strategy: full
    columns:
      id:
        strategy: remapping
        arguments:
          - use: new_uuid
          - foreign_keys: []
YAML;

    Storage::disk('local')->put('uuid-remapping.yaml', $yaml);

    $config = (new CloningYamlLoader)->load('uuid-remapping.yaml');

    $krTable = $config->keyRemapping?->getTable('users');
    expect($krTable?->strategy->value)->toBe('new_uuid');
});

it('inline remapping takes priority over legacy key_remapping section', function (): void {
    Storage::fake('local');

    $yaml = <<<'YAML'
version: "1"
connection: prod
options:
  chunk_size: 1000
  enforce_column_types: false
  drop_unknown_tables: false
  disable_foreign_key_checks: true
  faker_locale: en_US
tables:
  users:
    rows:
      strategy: full
    columns:
      id:
        strategy: remapping
        arguments:
          - use: new_uuid
          - foreign_keys: []
key_remapping:
  tables:
    - table: users
      primary_key: id
      strategy: random_integer
YAML;

    Storage::disk('local')->put('both.yaml', $yaml);

    $config = (new CloningYamlLoader)->load('both.yaml');

    // Inline wins — strategy from inline config (new_uuid), not legacy (random_integer)
    $krTable = $config->keyRemapping?->getTable('users');
    expect($krTable?->strategy->value)->toBe('new_uuid');
});

it('parses drop_extra_columns option when present', function (): void {
    Storage::fake('local');

    $yaml = <<<'YAML'
version: "1"
connection: production-db
options:
  chunk_size: 1000
  enforce_column_types: false
  drop_unknown_tables: false
  drop_extra_columns: true
  disable_foreign_key_checks: true
  faker_locale: en_US
tables:
  users:
    rows:
      strategy: full
YAML;

    Storage::disk('local')->put('test.cloning.yaml', $yaml);

    $config = (new CloningYamlLoader)->load('test.cloning.yaml');

    expect($config->options->dropExtraColumns)->toBeTrue();
});

it('defaults drop_extra_columns to false when not present', function (): void {
    Storage::fake('local');

    $yaml = <<<'YAML'
version: "1"
connection: production-db
options:
  chunk_size: 1000
  enforce_column_types: false
  drop_unknown_tables: false
  disable_foreign_key_checks: true
  faker_locale: en_US
tables:
  users:
    rows:
      strategy: full
YAML;

    Storage::disk('local')->put('test.cloning.yaml', $yaml);

    $config = (new CloningYamlLoader)->load('test.cloning.yaml');

    expect($config->options->dropExtraColumns)->toBeFalse();
});

it('parses top-level skip list into skipTables', function (): void {
    Storage::fake('local');

    $yaml = <<<'YAML'
version: "1"
connection: production-db
options:
  chunk_size: 1000
  enforce_column_types: false
  drop_unknown_tables: false
  disable_foreign_key_checks: true
  faker_locale: en_US
skip:
  - audit_logs
  - telescope_entries
tables:
  users:
    rows:
      strategy: full
YAML;

    Storage::disk('local')->put('test.cloning.yaml', $yaml);

    $config = (new CloningYamlLoader)->load('test.cloning.yaml');

    expect($config->skipTables)->toBe(['audit_logs', 'telescope_entries']);
});

it('returns empty skipTables when skip key is absent', function (): void {
    Storage::fake('local');

    $yaml = <<<'YAML'
version: "1"
connection: production-db
options:
  chunk_size: 1000
  enforce_column_types: false
  drop_unknown_tables: false
  disable_foreign_key_checks: true
  faker_locale: en_US
tables:
  users:
    rows:
      strategy: full
YAML;

    Storage::disk('local')->put('test.cloning.yaml', $yaml);

    $config = (new CloningYamlLoader)->load('test.cloning.yaml');

    expect($config->skipTables)->toBe([]);
});

it('ignores non-string entries in the skip list', function (): void {
    Storage::fake('local');

    $yaml = <<<'YAML'
version: "1"
connection: production-db
options:
  chunk_size: 1000
  enforce_column_types: false
  drop_unknown_tables: false
  disable_foreign_key_checks: true
  faker_locale: en_US
skip:
  - audit_logs
  - 42
  - ""
tables:
  users:
    rows:
      strategy: full
YAML;

    Storage::disk('local')->put('test.cloning.yaml', $yaml);

    $config = (new CloningYamlLoader)->load('test.cloning.yaml');

    expect($config->skipTables)->toBe(['audit_logs']);
});

it('collects tables with rows.strategy skip into skipTables', function (): void {
    Storage::fake('local');

    $yaml = <<<'YAML'
version: "1"
connection: production-db
options:
  chunk_size: 1000
  enforce_column_types: false
  drop_unknown_tables: false
  disable_foreign_key_checks: true
  faker_locale: en_US
tables:
  users:
    rows:
      strategy: full
  audit_logs:
    rows:
      strategy: skip
YAML;

    Storage::disk('local')->put('test.cloning.yaml', $yaml);

    $config = (new CloningYamlLoader)->load('test.cloning.yaml');

    expect($config->skipTables)->toBe(['audit_logs']);
});

it('deduplicates tables that appear in both skip list and as strategy skip', function (): void {
    Storage::fake('local');

    $yaml = <<<'YAML'
version: "1"
connection: production-db
options:
  chunk_size: 1000
  enforce_column_types: false
  drop_unknown_tables: false
  disable_foreign_key_checks: true
  faker_locale: en_US
skip:
  - audit_logs
tables:
  users:
    rows:
      strategy: full
  audit_logs:
    rows:
      strategy: skip
YAML;

    Storage::disk('local')->put('test.cloning.yaml', $yaml);

    $config = (new CloningYamlLoader)->load('test.cloning.yaml');

    expect($config->skipTables)->toBe(['audit_logs']);
});

it('skips a table from the top-level skip list even when its table entry has a non-skip strategy', function (): void {
    Storage::fake('local');

    $yaml = <<<'YAML'
version: "1"
connection: production-db
options:
  chunk_size: 1000
  enforce_column_types: false
  drop_unknown_tables: false
  disable_foreign_key_checks: true
  faker_locale: en_US
skip:
  - users
tables:
  users:
    rows:
      strategy: full
YAML;

    Storage::disk('local')->put('test.cloning.yaml', $yaml);

    $config = (new CloningYamlLoader)->load('test.cloning.yaml');

    expect($config->skipTables)->toBe(['users']);
});

it('throws when the config file exists but cannot be read', function (): void {
    $disk = Mockery::mock(Filesystem::class);
    $disk->shouldReceive('exists')->andReturnTrue();
    $disk->shouldReceive('get')->andReturnNull();
    Storage::shouldReceive('disk')->with('local')->andReturn($disk);

    expect(fn () => (new CloningYamlLoader)->load('x.yaml'))
        ->toThrow(RuntimeException::class, 'Could not read');
});

it('throws when the YAML root is not a mapping', function (): void {
    Storage::fake('local');
    Storage::disk('local')->put('scalar.yaml', 'just a scalar string');

    expect(fn () => (new CloningYamlLoader)->load('scalar.yaml'))
        ->toThrow(RuntimeException::class, 'root must be a mapping');
});

it('skips non-array table and column entries', function (): void {
    Storage::fake('local');
    $yaml = <<<'YAML'
connection: c
tables:
  broken_table: "not-a-mapping"
  users:
    rows:
      strategy: full
    columns:
      good:
        strategy: fake
        faker_method: safeEmail
      bad_column: "not-a-mapping"
YAML;
    Storage::disk('local')->put('c.yaml', $yaml);

    $config = (new CloningYamlLoader)->load('c.yaml');

    expect($config->tables)->toHaveCount(1)
        ->and($config->tables[0]->tableName)->toBe('users')
        ->and($config->tables[0]->columns)->toHaveCount(1)
        ->and($config->tables[0]->columns[0]->columnName)->toBe('good');
});

it('loads a template strategy column', function (): void {
    Storage::fake('local');
    $yaml = <<<'YAML'
connection: c
tables:
  users:
    rows:
      strategy: full
    columns:
      handle:
        strategy: template
        template: "{userName}@acme.test"
YAML;
    Storage::disk('local')->put('c.yaml', $yaml);

    $config = (new CloningYamlLoader)->load('c.yaml');

    expect($config->tables[0]->columns[0]->strategy)->toBe('template')
        ->and($config->tables[0]->columns[0]->template)->toBe('{userName}@acme.test');
});

it('parses inline remapping foreign keys and skips non-map entries', function (): void {
    Storage::fake('local');
    $yaml = <<<'YAML'
connection: c
tables:
  orders:
    rows:
      strategy: full
    columns:
      id:
        strategy: remapping
        arguments:
          - "not-a-map"
          - use: new_uuid
          - foreign_keys:
              - {table: users, column: user_id, self_referential: false}
              - "skip-me"
YAML;
    Storage::disk('local')->put('c.yaml', $yaml);

    $config = (new CloningYamlLoader)->load('c.yaml');

    expect($config->keyRemapping)->not->toBeNull();
    $krTable = $config->keyRemapping->getTable('orders');
    expect($krTable)->not->toBeNull()
        ->and($krTable->strategy->value)->toBe('new_uuid')
        ->and($krTable->foreignKeys)->toHaveCount(1)
        ->and($krTable->foreignKeys[0]->table)->toBe('users');
});

it('returns null key remapping for a legacy section with only invalid entries', function (): void {
    Storage::fake('local');
    $yaml = <<<'YAML'
connection: c
tables:
  users:
    rows:
      strategy: full
key_remapping:
  tables:
    - "not-a-map"
    - {primary_key: id, strategy: new_uuid}
    - {table: missing_pk, strategy: new_uuid}
    - {table: ok, primary_key: id, foreign_keys: ["bad-fk", {table: t, column: c}]}
YAML;
    Storage::disk('local')->put('c.yaml', $yaml);

    $config = (new CloningYamlLoader)->load('c.yaml');

    // The only valid legacy entry is 'ok' → keyRemapping present with one table + one FK
    expect($config->keyRemapping)->not->toBeNull();
    $ok = $config->keyRemapping->getTable('ok');
    expect($ok)->not->toBeNull()
        ->and($ok->foreignKeys)->toHaveCount(1);
});

it('returns null key remapping when the legacy tables list is empty', function (): void {
    Storage::fake('local');
    $yaml = <<<'YAML'
connection: c
tables:
  users:
    rows:
      strategy: full
key_remapping:
  tables: []
YAML;
    Storage::disk('local')->put('c.yaml', $yaml);

    expect((new CloningYamlLoader)->load('c.yaml')->keyRemapping)->toBeNull();
});

it('returns null key remapping when every legacy table entry is invalid', function (): void {
    Storage::fake('local');
    $yaml = <<<'YAML'
connection: c
tables:
  users:
    rows:
      strategy: full
key_remapping:
  tables:
    - "not-a-map"
    - {strategy: new_uuid}
    - {table: only_table_no_pk}
YAML;
    Storage::disk('local')->put('c.yaml', $yaml);

    expect((new CloningYamlLoader)->load('c.yaml')->keyRemapping)->toBeNull();
});

it('parses recreate_tables option and defaults it to false', function (bool $present): void {
    Storage::fake('local');

    $optionLine = $present ? "  recreate_tables: true\n" : '';
    $yaml = "version: \"1\"\nconnection: production-db\noptions:\n  chunk_size: 1000\n  enforce_column_types: false\n  drop_unknown_tables: false\n{$optionLine}  disable_foreign_key_checks: true\n  faker_locale: en_US\ntables:\n  users:\n    rows:\n      strategy: full\n";

    Storage::disk('local')->put('test.cloning.yaml', $yaml);

    $config = (new CloningYamlLoader)->load('test.cloning.yaml');

    expect($config->options->recreateTables)->toBe($present);
})->with(['present' => [true], 'absent' => [false]]);

function g6_loadCloningYaml(string $yaml): CloningConfigData
{
    Storage::fake('local');
    Storage::disk('local')->put('g6.cloning.yaml', $yaml);

    return (new CloningYamlLoader)->load('g6.cloning.yaml');
}

it('applies defaults for version, connection and all options when absent', function (): void {
    $config = g6_loadCloningYaml("tables: {}\n");

    expect($config->version)->toBe('1')
        ->and($config->connectionName)->toBe('')
        ->and($config->options->chunkSize)->toBe(1000)
        ->and($config->options->enforceColumnTypes)->toBeFalse()
        ->and($config->options->dropUnknownTables)->toBeFalse()
        ->and($config->options->dropExtraColumns)->toBeFalse()
        ->and($config->options->disableForeignKeyChecks)->toBeTrue()
        ->and($config->options->fakerLocale)->toBe('en_US')
        ->and($config->options->remapKeys)->toBeTrue()
        ->and($config->options->recreateTables)->toBeFalse()
        ->and($config->tables)->toBe([])
        ->and($config->keyRemapping)->toBeNull()
        ->and($config->skipTables)->toBe([]);
});

it('reads a custom version string and casts a numeric version to string', function (): void {
    expect(g6_loadCloningYaml("version: \"3\"\n")->version)->toBe('3')
        ->and(g6_loadCloningYaml("version: 2\n")->version)->toBe('2')
        ->and(g6_loadCloningYaml("version: [1, 2]\n")->version)->toBe('1');
});

it('falls back to an empty connection name when connection is not a string', function (): void {
    expect(g6_loadCloningYaml("connection: [a, b]\n")->connectionName)->toBe('');
});

it('casts truthy integer option values to booleans', function (): void {
    $yaml = <<<'YAML'
options:
  enforce_column_types: 1
  drop_unknown_tables: 1
  drop_extra_columns: 1
  disable_foreign_key_checks: 0
  remap_keys: 0
  recreate_tables: 1
YAML;

    $options = g6_loadCloningYaml($yaml)->options;

    expect($options->enforceColumnTypes)->toBeTrue()
        ->and($options->dropUnknownTables)->toBeTrue()
        ->and($options->dropExtraColumns)->toBeTrue()
        ->and($options->disableForeignKeyChecks)->toBeFalse()
        ->and($options->remapKeys)->toBeFalse()
        ->and($options->recreateTables)->toBeTrue();
});

it('reads explicitly set boolean options and faker locale', function (): void {
    $yaml = <<<'YAML'
options:
  drop_unknown_tables: true
  disable_foreign_key_checks: false
  faker_locale: de_DE
YAML;

    $options = g6_loadCloningYaml($yaml)->options;

    expect($options->dropUnknownTables)->toBeTrue()
        ->and($options->disableForeignKeyChecks)->toBeFalse()
        ->and($options->fakerLocale)->toBe('de_DE');
});

it('casts numeric table and column names to strings', function (): void {
    $yaml = <<<'YAML'
tables:
  123:
    columns:
      456:
        strategy: keep
YAML;

    $config = g6_loadCloningYaml($yaml);

    expect($config->tables)->toHaveCount(1)
        ->and($config->tables[0]->tableName)->toBe('123')
        ->and($config->tables[0]->columns[0]->columnName)->toBe('456');
});

it('reads rows.sort_by', function (): void {
    $yaml = <<<'YAML'
tables:
  users:
    rows:
      strategy: limit
      limit: 10
      sort_by: created_at
YAML;

    $rows = g6_loadCloningYaml($yaml)->tables[0]->rows;

    expect($rows->strategy)->toBe('limit')
        ->and($rows->limit)->toBe(10)
        ->and($rows->sortBy)->toBe('created_at');
});

it('skips non-mapping column entries but keeps following columns', function (): void {
    $yaml = <<<'YAML'
tables:
  users:
    columns:
      broken: just-a-string
      email:
        strategy: keep
YAML;

    $columns = g6_loadCloningYaml($yaml)->tables[0]->columns;

    expect($columns)->toHaveCount(1)
        ->and($columns[0]->columnName)->toBe('email');
});

it('collects inline remapping columns that follow non-remapping columns', function (): void {
    $yaml = <<<'YAML'
tables:
  users:
    columns:
      name:
        strategy: keep
      id:
        strategy: remapping
        arguments:
          - use: new_uuid
YAML;

    $config = g6_loadCloningYaml($yaml);

    expect($config->keyRemapping?->tables)->toHaveCount(1)
        ->and($config->keyRemapping?->tables[0]->table)->toBe('users')
        ->and($config->keyRemapping?->tables[0]->primaryKey)->toBe('id')
        ->and($config->keyRemapping?->tables[0]->strategy->value)->toBe('new_uuid');
});

it('returns null key remapping when key_remapping.tables is not a list', function (): void {
    $yaml = <<<'YAML'
key_remapping:
  tables: not-a-list
YAML;

    expect(g6_loadCloningYaml($yaml)->keyRemapping)->toBeNull();
});

it('returns null key remapping when key_remapping.tables is empty', function (): void {
    $yaml = <<<'YAML'
key_remapping:
  tables: []
YAML;

    expect(g6_loadCloningYaml($yaml)->keyRemapping)->toBeNull();
});

it('ignores key_remapping entries without a table name', function (): void {
    $yaml = <<<'YAML'
key_remapping:
  tables:
    - primary_key: id
      strategy: new_uuid
YAML;

    expect(g6_loadCloningYaml($yaml)->keyRemapping)->toBeNull();
});

it('defaults missing key_remapping foreign key fields and casts self_referential', function (): void {
    $yaml = <<<'YAML'
key_remapping:
  tables:
    - table: users
      primary_key: id
      foreign_keys:
        - {}
        - table: users
          column: parent_id
          self_referential: 1
YAML;

    $fks = g6_loadCloningYaml($yaml)->keyRemapping?->tables[0]->foreignKeys ?? [];

    expect($fks)->toHaveCount(2)
        ->and($fks[0]->table)->toBe('')
        ->and($fks[0]->column)->toBe('')
        ->and($fks[0]->selfReferential)->toBeFalse()
        ->and($fks[1]->table)->toBe('users')
        ->and($fks[1]->column)->toBe('parent_id')
        ->and($fks[1]->selfReferential)->toBeTrue();
});

it('defaults faker arguments to an empty list when absent', function (): void {
    $yaml = <<<'YAML'
tables:
  users:
    columns:
      email:
        strategy: fake
        faker_method: safeEmail
YAML;

    $column = g6_loadCloningYaml($yaml)->tables[0]->columns[0];

    expect($column->fakerMethod)->toBe('safeEmail')
        ->and($column->fakerArguments)->toBe([]);
});

it('converts mapped faker arguments into a list', function (): void {
    $yaml = <<<'YAML'
tables:
  users:
    columns:
      age:
        strategy: fake
        faker_method: numberBetween
        faker_arguments:
          min: 18
          max: 99
YAML;

    expect(g6_loadCloningYaml($yaml)->tables[0]->columns[0]->fakerArguments)->toBe([18, 99]);
});

it('casts scalar hash salt, static value and preserve_format', function (): void {
    $yaml = <<<'YAML'
tables:
  users:
    columns:
      password:
        strategy: hash
        algorithm: sha256
        salt: 123
      status:
        strategy: static
        value: 5
      phone:
        strategy: mask
        preserve_format: 1
YAML;

    $columns = g6_loadCloningYaml($yaml)->tables[0]->columns;

    expect($columns[0]->hashSalt)->toBe('123')
        ->and($columns[1]->staticValue)->toBe('5')
        ->and($columns[2]->preserveFormat)->toBeTrue();
});

it('skips non-mapping inline remapping foreign keys and defaults missing fields', function (): void {
    $yaml = <<<'YAML'
tables:
  users:
    columns:
      id:
        strategy: remapping
        arguments:
          - foreign_keys:
              - not-a-mapping
              - {}
              - table: users
                column: parent_id
                self_referential: 1
YAML;

    $fks = g6_loadCloningYaml($yaml)->tables[0]->columns[0]->remappingForeignKeys ?? [];

    expect($fks)->toHaveCount(2)
        ->and($fks[0]->table)->toBe('')
        ->and($fks[0]->column)->toBe('')
        ->and($fks[0]->selfReferential)->toBeFalse()
        ->and($fks[1]->table)->toBe('users')
        ->and($fks[1]->column)->toBe('parent_id')
        ->and($fks[1]->selfReferential)->toBeTrue();
});
