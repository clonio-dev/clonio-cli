<?php

declare(strict_types=1);

use App\Enums\ClearMode;
use App\Services\Cloning\CloningYamlLoader;
use Illuminate\Support\Facades\Storage;

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
      range_min: 100000
      range_max: 9999999
YAML;

    Storage::disk('local')->put('int-remapping.yaml', $yaml);

    $loader = new CloningYamlLoader;
    $config = $loader->load('int-remapping.yaml');

    $table = $config->keyRemapping?->getTable('orders');
    expect($table)->not->toBeNull();
    expect($table?->strategy->value)->toBe('random_integer');
    expect($table?->rangeMin)->toBe(100000);
    expect($table?->rangeMax)->toBe(9999999);
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
          - min: 100000
          - max: 9999999
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
    expect($krTable?->rangeMin)->toBe(100000);
    expect($krTable?->rangeMax)->toBe(9999999);
    expect($krTable?->foreignKeys)->toBe([]);

    // The remapping column itself is accessible
    $idCol = $config->getTable('users')?->getColumn('id');
    expect($idCol?->strategy)->toBe('remapping');
    expect($idCol?->remappingUse)->toBe('random_integer');
    expect($idCol?->remappingMin)->toBe(100000);
    expect($idCol?->remappingMax)->toBe(9999999);
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
          - use: random_integer
          - min: 200000
          - max: 8888888
          - foreign_keys: []
key_remapping:
  tables:
    - table: users
      primary_key: id
      strategy: random_integer
      range_min: 1
      range_max: 99
YAML;

    Storage::disk('local')->put('both.yaml', $yaml);

    $config = (new CloningYamlLoader)->load('both.yaml');

    // Inline wins — range from inline config
    $krTable = $config->keyRemapping?->getTable('users');
    expect($krTable?->rangeMin)->toBe(200000);
    expect($krTable?->rangeMax)->toBe(8888888);
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
