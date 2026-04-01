<?php

declare(strict_types=1);

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
