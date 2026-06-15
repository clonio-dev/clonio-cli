<?php

declare(strict_types=1);

use App\Enums\ExitCode;
use Illuminate\Support\Facades\Storage;

function makeTableEditYaml(): string
{
    return <<<'YAML'
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
      clear: delete
    columns:
      email:
        strategy: fake
        faker_method: safeEmail
        faker_arguments: []
  orders:
    rows:
      strategy: first
      limit: 50
      sort_by: created_at
YAML;
}

it('exits with IoError when file does not exist', function (): void {
    Storage::fake('local');

    $this->artisan('cloning:table:edit', ['file' => 'missing.cloning.yaml'])
        ->expectsOutputToContain('File not found')
        ->assertExitCode(ExitCode::IoError->value);
});

it('exits with ValidationError for unknown row strategy', function (): void {
    Storage::fake('local');
    Storage::disk('local')->put('test.cloning.yaml', makeTableEditYaml());

    $this->artisan('cloning:table:edit', [
        'file' => 'test.cloning.yaml',
        '--table' => 'users',
        '--rows-strategy' => 'bogus',
        '--rows-clear' => 'none',
    ])
        ->expectsOutputToContain("Unknown row strategy: 'bogus'")
        ->assertExitCode(ExitCode::ValidationError->value);
});

it('exits with ValidationError for unknown clear mode', function (): void {
    Storage::fake('local');
    Storage::disk('local')->put('test.cloning.yaml', makeTableEditYaml());

    $this->artisan('cloning:table:edit', [
        'file' => 'test.cloning.yaml',
        '--table' => 'users',
        '--rows-strategy' => 'full',
        '--rows-clear' => 'bogus',
    ])
        ->expectsOutputToContain("Unknown clear mode: 'bogus'")
        ->assertExitCode(ExitCode::ValidationError->value);
});

it('updates an existing table to full strategy with truncate clear', function (): void {
    Storage::fake('local');
    Storage::disk('local')->put('test.cloning.yaml', makeTableEditYaml());

    $this->artisan('cloning:table:edit', [
        'file' => 'test.cloning.yaml',
        '--table' => 'users',
        '--rows-strategy' => 'full',
        '--rows-clear' => 'truncate',
    ])
        ->expectsConfirmation('Apply this change?', 'yes')
        ->expectsOutputToContain('Updated users')
        ->assertExitCode(ExitCode::Success->value);

    $content = Storage::disk('local')->get('test.cloning.yaml');
    expect($content)->toBeString();
    expect($content)->toContain('strategy: full');
    expect($content)->toContain('clear: truncate');
    // Existing column config must be preserved
    expect($content)->toContain('faker_method: safeEmail');
});

it('writes clear: false when clear mode is none', function (): void {
    Storage::fake('local');
    Storage::disk('local')->put('test.cloning.yaml', makeTableEditYaml());

    $this->artisan('cloning:table:edit', [
        'file' => 'test.cloning.yaml',
        '--table' => 'users',
        '--rows-strategy' => 'full',
        '--rows-clear' => 'none',
    ])
        ->expectsConfirmation('Apply this change?', 'yes')
        ->assertExitCode(ExitCode::Success->value);

    $content = Storage::disk('local')->get('test.cloning.yaml');
    expect($content)->toContain('clear: false');
});

it('updates table to first strategy with limit and sort_by via flags', function (): void {
    Storage::fake('local');
    Storage::disk('local')->put('test.cloning.yaml', makeTableEditYaml());

    $this->artisan('cloning:table:edit', [
        'file' => 'test.cloning.yaml',
        '--table' => 'orders',
        '--rows-strategy' => 'first',
        '--rows-limit' => '25',
        '--rows-sort-by' => 'created_at',
        '--rows-clear' => 'delete',
    ])
        ->expectsConfirmation('Apply this change?', 'yes')
        ->assertExitCode(ExitCode::Success->value);

    $content = Storage::disk('local')->get('test.cloning.yaml');
    expect($content)->toContain('strategy: first');
    expect($content)->toContain('limit: 25');
    expect($content)->toContain('sort_by: created_at');
    expect($content)->toContain('clear: delete');
});

it('updates table to last strategy with limit only (no sort_by)', function (): void {
    Storage::fake('local');
    Storage::disk('local')->put('test.cloning.yaml', makeTableEditYaml());

    $this->artisan('cloning:table:edit', [
        'file' => 'test.cloning.yaml',
        '--table' => 'orders',
        '--rows-strategy' => 'last',
        '--rows-limit' => '10',
        '--rows-clear' => 'none',
    ])
        ->expectsQuestion('Sort by column (optional, leave blank to skip)', '')
        ->expectsConfirmation('Apply this change?', 'yes')
        ->assertExitCode(ExitCode::Success->value);

    $content = Storage::disk('local')->get('test.cloning.yaml');
    expect($content)->toContain('strategy: last');
    expect($content)->toContain('limit: 10');
    expect($content)->not->toContain('sort_by:');
});

it('updates table to skip strategy', function (): void {
    Storage::fake('local');
    Storage::disk('local')->put('test.cloning.yaml', makeTableEditYaml());

    $this->artisan('cloning:table:edit', [
        'file' => 'test.cloning.yaml',
        '--table' => 'orders',
        '--rows-strategy' => 'skip',
        '--rows-clear' => 'none',
    ])
        ->expectsConfirmation('Apply this change?', 'yes')
        ->assertExitCode(ExitCode::Success->value);

    $content = Storage::disk('local')->get('test.cloning.yaml');
    expect($content)->toContain('strategy: skip');
});

it('rejects non-numeric --rows-limit', function (): void {
    Storage::fake('local');
    Storage::disk('local')->put('test.cloning.yaml', makeTableEditYaml());

    $this->artisan('cloning:table:edit', [
        'file' => 'test.cloning.yaml',
        '--table' => 'orders',
        '--rows-strategy' => 'first',
        '--rows-limit' => 'abc',
        '--rows-clear' => 'none',
    ])
        ->expectsOutputToContain('--rows-limit must be a positive integer')
        ->assertExitCode(ExitCode::ValidationError->value);
});

it('rejects --rows-limit less than 1', function (): void {
    Storage::fake('local');
    Storage::disk('local')->put('test.cloning.yaml', makeTableEditYaml());

    $this->artisan('cloning:table:edit', [
        'file' => 'test.cloning.yaml',
        '--table' => 'orders',
        '--rows-strategy' => 'first',
        '--rows-limit' => '0',
        '--rows-clear' => 'none',
    ])
        ->expectsOutputToContain('--rows-limit must be a positive integer')
        ->assertExitCode(ExitCode::ValidationError->value);
});

it('auto-creates a new table non-interactively when --table is unknown', function (): void {
    Storage::fake('local');
    Storage::disk('local')->put('test.cloning.yaml', makeTableEditYaml());

    $this->artisan('cloning:table:edit', [
        'file' => 'test.cloning.yaml',
        '--table' => 'audit_events',
        '--rows-strategy' => 'skip',
        '--rows-clear' => 'none',
    ])
        ->expectsConfirmation('Apply this change?', 'yes')
        ->assertExitCode(ExitCode::Success->value);

    $content = Storage::disk('local')->get('test.cloning.yaml');
    expect($content)->toContain('audit_events');
    expect($content)->toContain('strategy: skip');
});

it('cancels when user declines confirmation', function (): void {
    Storage::fake('local');
    Storage::disk('local')->put('test.cloning.yaml', makeTableEditYaml());
    $original = Storage::disk('local')->get('test.cloning.yaml');

    $this->artisan('cloning:table:edit', [
        'file' => 'test.cloning.yaml',
        '--table' => 'users',
        '--rows-strategy' => 'skip',
        '--rows-clear' => 'none',
    ])
        ->expectsConfirmation('Apply this change?', 'no')
        ->expectsOutputToContain('Cancelled.')
        ->assertExitCode(ExitCode::Success->value);

    expect(Storage::disk('local')->get('test.cloning.yaml'))->toBe($original);
});

it('exits with ValidationError for invalid YAML missing tables section', function (): void {
    Storage::fake('local');
    Storage::disk('local')->put('test.cloning.yaml', "version: \"1\"\nconnection: production-db\n");

    $this->artisan('cloning:table:edit', [
        'file' => 'test.cloning.yaml',
        '--table' => 'users',
        '--rows-strategy' => 'full',
        '--rows-clear' => 'none',
    ])
        ->expectsOutputToContain('Invalid cloning YAML: missing tables section.')
        ->assertExitCode(ExitCode::ValidationError->value);
});

it('errors when no .cloning.yaml files exist and no file argument given', function (): void {
    Storage::fake('local');

    $this->artisan('cloning:table:edit', [
        '--table' => 'users',
        '--rows-strategy' => 'full',
        '--rows-clear' => 'none',
    ])
        ->expectsOutputToContain('No .cloning.yaml files found')
        ->assertExitCode(ExitCode::IoError->value);
});

it('auto-selects the single .cloning.yaml file when no file argument given', function (): void {
    Storage::fake('local');
    Storage::disk('local')->put('only.cloning.yaml', makeTableEditYaml());

    $this->artisan('cloning:table:edit', [
        '--table' => 'users',
        '--rows-strategy' => 'full',
        '--rows-clear' => 'none',
    ])
        ->expectsConfirmation('Apply this change?', 'yes')
        ->expectsOutputToContain('only.cloning.yaml')
        ->assertExitCode(ExitCode::Success->value);
});

it('prompts to select among multiple .cloning.yaml files', function (): void {
    Storage::fake('local');
    Storage::disk('local')->put('a.cloning.yaml', makeTableEditYaml());
    Storage::disk('local')->put('b.cloning.yaml', makeTableEditYaml());

    $this->artisan('cloning:table:edit', [
        '--table' => 'users',
        '--rows-strategy' => 'full',
        '--rows-clear' => 'none',
    ])
        ->expectsChoice('Select a cloning YAML file', 'b.cloning.yaml', ['a.cloning.yaml', 'b.cloning.yaml'])
        ->expectsConfirmation('Apply this change?', 'yes')
        ->expectsOutputToContain('b.cloning.yaml')
        ->assertExitCode(ExitCode::Success->value);
});

it('asks for the table name when no tables are listed yet', function (): void {
    Storage::fake('local');
    Storage::disk('local')->put('test.cloning.yaml', "version: \"1\"\ntables: {}\n");

    $this->artisan('cloning:table:edit', [
        'file' => 'test.cloning.yaml',
        '--rows-strategy' => 'skip',
        '--rows-clear' => 'none',
    ])
        ->expectsQuestion('Table name (no tables listed yet — enter the name to add)', 'new_table')
        ->expectsConfirmation('Apply this change?', 'yes')
        ->assertExitCode(ExitCode::Success->value);

    expect(Storage::disk('local')->get('test.cloning.yaml'))->toContain('new_table');
});

it('selects an existing table from the interactive list', function (): void {
    Storage::fake('local');
    Storage::disk('local')->put('test.cloning.yaml', makeTableEditYaml());

    $this->artisan('cloning:table:edit', [
        'file' => 'test.cloning.yaml',
        '--rows-strategy' => 'skip',
        '--rows-clear' => 'none',
    ])
        ->expectsChoice('Select a table', 'orders', ['users', 'orders', '+ Add new table'])
        ->expectsConfirmation('Apply this change?', 'yes')
        ->assertExitCode(ExitCode::Success->value);

    expect(Storage::disk('local')->get('test.cloning.yaml'))->toContain('strategy: skip');
});

it('adds a new table via the interactive "+ Add new table" choice', function (): void {
    Storage::fake('local');
    Storage::disk('local')->put('test.cloning.yaml', makeTableEditYaml());

    $this->artisan('cloning:table:edit', [
        'file' => 'test.cloning.yaml',
        '--rows-strategy' => 'skip',
        '--rows-clear' => 'none',
    ])
        ->expectsChoice('Select a table', '+ Add new table', ['users', 'orders', '+ Add new table'])
        ->expectsQuestion('Table name', 'invoices')
        ->expectsConfirmation('Apply this change?', 'yes')
        ->assertExitCode(ExitCode::Success->value);

    expect(Storage::disk('local')->get('test.cloning.yaml'))->toContain('invoices');
});

it('errors when the entered table name is empty', function (): void {
    Storage::fake('local');
    Storage::disk('local')->put('test.cloning.yaml', "version: \"1\"\ntables: {}\n");

    $this->artisan('cloning:table:edit', [
        'file' => 'test.cloning.yaml',
        '--rows-strategy' => 'skip',
        '--rows-clear' => 'none',
    ])
        ->expectsQuestion('Table name (no tables listed yet — enter the name to add)', '')
        ->expectsOutputToContain('Table name cannot be empty.')
        ->assertExitCode(ExitCode::ValidationError->value);
});

it('selects the row strategy interactively', function (): void {
    Storage::fake('local');
    Storage::disk('local')->put('test.cloning.yaml', makeTableEditYaml());

    $this->artisan('cloning:table:edit', [
        'file' => 'test.cloning.yaml',
        '--table' => 'users',
        '--rows-clear' => 'none',
    ])
        ->expectsChoice('Select a row strategy', 'Skip — don\'t transfer this table', [
            'Full — copy every row',
            'First N — copy the first N rows after sorting',
            'Last N — copy the last N rows after sorting',
            "Skip — don't transfer this table",
        ])
        ->expectsConfirmation('Apply this change?', 'yes')
        ->assertExitCode(ExitCode::Success->value);

    expect(Storage::disk('local')->get('test.cloning.yaml'))->toContain('strategy: skip');
});

it('asks for the row limit and sort-by interactively for the first strategy', function (): void {
    Storage::fake('local');
    Storage::disk('local')->put('test.cloning.yaml', makeTableEditYaml());

    $this->artisan('cloning:table:edit', [
        'file' => 'test.cloning.yaml',
        '--table' => 'users',
        '--rows-strategy' => 'first',
        '--rows-clear' => 'none',
    ])
        ->expectsQuestion('Row limit', '42')
        ->expectsQuestion('Sort by column (optional, leave blank to skip)', 'id')
        ->expectsConfirmation('Apply this change?', 'yes')
        ->assertExitCode(ExitCode::Success->value);

    $content = Storage::disk('local')->get('test.cloning.yaml');
    expect($content)->toContain('limit: 42');
    expect($content)->toContain('sort_by: id');
});

it('rejects an interactively entered non-positive row limit', function (): void {
    Storage::fake('local');
    Storage::disk('local')->put('test.cloning.yaml', makeTableEditYaml());

    $this->artisan('cloning:table:edit', [
        'file' => 'test.cloning.yaml',
        '--table' => 'users',
        '--rows-strategy' => 'first',
        '--rows-clear' => 'none',
    ])
        ->expectsQuestion('Row limit', '0')
        ->expectsOutputToContain('Row limit must be a positive integer.')
        ->assertExitCode(ExitCode::ValidationError->value);
});

it('selects the clear mode interactively', function (): void {
    Storage::fake('local');
    Storage::disk('local')->put('test.cloning.yaml', makeTableEditYaml());

    $this->artisan('cloning:table:edit', [
        'file' => 'test.cloning.yaml',
        '--table' => 'users',
        '--rows-strategy' => 'full',
    ])
        ->expectsChoice('Select a clear mode', 'Truncate — fast wipe before insert (no FKs)', [
            'None — leave existing target rows',
            'Truncate — fast wipe before insert (no FKs)',
            'Delete — DELETE FROM before insert (FK-safe)',
        ])
        ->expectsConfirmation('Apply this change?', 'yes')
        ->assertExitCode(ExitCode::Success->value);

    expect(Storage::disk('local')->get('test.cloning.yaml'))->toContain('clear: truncate');
});

it('preserves unmanaged rows.* keys when rewriting a table', function (): void {
    Storage::fake('local');
    $yaml = <<<'YAML'
version: "1"
connection: production-db
tables:
  orders:
    rows:
      strategy: full
      where: "status = 'paid'"
YAML;
    Storage::disk('local')->put('test.cloning.yaml', $yaml);

    $this->artisan('cloning:table:edit', [
        'file' => 'test.cloning.yaml',
        '--table' => 'orders',
        '--rows-strategy' => 'full',
        '--rows-clear' => 'none',
    ])
        ->expectsConfirmation('Apply this change?', 'yes')
        ->assertExitCode(ExitCode::Success->value);

    expect(Storage::disk('local')->get('test.cloning.yaml'))->toContain("where: \"status = 'paid'\"");
});
