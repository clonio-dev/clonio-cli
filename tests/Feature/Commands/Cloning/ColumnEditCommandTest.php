<?php

declare(strict_types=1);

use App\Enums\ExitCode;
use Illuminate\Support\Facades\Storage;

function makeTestCloningYaml(): string
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
      password:
        strategy: hash
        algorithm: sha256
        salt: ""
YAML;
}

it('exits with IoError when file does not exist', function (): void {
    Storage::fake('local');

    $this->artisan('cloning:column:edit', ['file' => 'missing.cloning.yaml'])
        ->expectsOutputToContain('File not found')
        ->assertExitCode(ExitCode::IoError->value);
});

it('exits with ValidationError when table not found', function (): void {
    Storage::fake('local');
    Storage::disk('local')->put('test.cloning.yaml', makeTestCloningYaml());

    $this->artisan('cloning:column:edit', [
        'file' => 'test.cloning.yaml',
        '--table' => 'nonexistent',
        '--column' => 'email',
        '--strategy' => 'keep',
    ])
        ->expectsOutputToContain("Table 'nonexistent' not found")
        ->assertExitCode(ExitCode::ValidationError->value);
});

it('exits with ValidationError for unknown strategy', function (): void {
    Storage::fake('local');
    Storage::disk('local')->put('test.cloning.yaml', makeTestCloningYaml());

    $this->artisan('cloning:column:edit', [
        'file' => 'test.cloning.yaml',
        '--table' => 'users',
        '--column' => 'email',
        '--strategy' => 'unknown_strategy',
    ])
        ->expectsOutputToContain("Unknown strategy: 'unknown_strategy'")
        ->assertExitCode(ExitCode::ValidationError->value);
});

it('updates column to keep strategy', function (): void {
    Storage::fake('local');
    Storage::disk('local')->put('test.cloning.yaml', makeTestCloningYaml());

    $this->artisan('cloning:column:edit', [
        'file' => 'test.cloning.yaml',
        '--table' => 'users',
        '--column' => 'email',
        '--strategy' => 'keep',
    ])
        ->expectsConfirmation('Apply this change?', 'yes')
        ->expectsOutputToContain('Updated users.email')
        ->assertExitCode(ExitCode::Success->value);

    $content = Storage::disk('local')->get('test.cloning.yaml');
    expect($content)->toBeString();
    expect($content)->toContain('strategy: keep');
});

it('updates column to fake strategy via flags', function (): void {
    Storage::fake('local');
    Storage::disk('local')->put('test.cloning.yaml', makeTestCloningYaml());

    $this->artisan('cloning:column:edit', [
        'file' => 'test.cloning.yaml',
        '--table' => 'users',
        '--column' => 'email',
        '--strategy' => 'fake',
        '--faker-method' => 'userName',
        '--faker-arguments' => '',
    ])
        ->expectsConfirmation('Apply this change?', 'yes')
        ->expectsOutputToContain('Updated users.email')
        ->assertExitCode(ExitCode::Success->value);

    $content = Storage::disk('local')->get('test.cloning.yaml');
    expect($content)->toContain('faker_method: userName');
});

it('updates column to hash strategy via flags', function (): void {
    Storage::fake('local');
    Storage::disk('local')->put('test.cloning.yaml', makeTestCloningYaml());

    $this->artisan('cloning:column:edit', [
        'file' => 'test.cloning.yaml',
        '--table' => 'users',
        '--column' => 'password',
        '--strategy' => 'hash',
        '--algorithm' => 'sha512',
        '--salt' => 'my-salt',
    ])
        ->expectsConfirmation('Apply this change?', 'yes')
        ->expectsOutputToContain('Updated users.password')
        ->assertExitCode(ExitCode::Success->value);

    $content = Storage::disk('local')->get('test.cloning.yaml');
    expect($content)->toContain('algorithm: sha512');
    expect($content)->toContain('salt: my-salt');
});

it('updates column to mask strategy via flags', function (): void {
    Storage::fake('local');
    Storage::disk('local')->put('test.cloning.yaml', makeTestCloningYaml());

    $this->artisan('cloning:column:edit', [
        'file' => 'test.cloning.yaml',
        '--table' => 'users',
        '--column' => 'email',
        '--strategy' => 'mask',
        '--visible-chars' => '4',
        '--mask-char' => '#',
        '--preserve-format' => true,
    ])
        ->expectsConfirmation('Apply this change?', 'yes')
        ->expectsOutputToContain('Updated users.email')
        ->assertExitCode(ExitCode::Success->value);

    $content = Storage::disk('local')->get('test.cloning.yaml');
    expect($content)->toContain('visible_chars: 4');
    expect($content)->toContain("mask_char: '#'");
    expect($content)->toContain('preserve_format: true');
});

it('updates column to static strategy via flags', function (): void {
    Storage::fake('local');
    Storage::disk('local')->put('test.cloning.yaml', makeTestCloningYaml());

    $this->artisan('cloning:column:edit', [
        'file' => 'test.cloning.yaml',
        '--table' => 'users',
        '--column' => 'email',
        '--strategy' => 'static',
        '--value' => 'redacted@example.com',
    ])
        ->expectsConfirmation('Apply this change?', 'yes')
        ->expectsOutputToContain('Updated users.email')
        ->assertExitCode(ExitCode::Success->value);

    $content = Storage::disk('local')->get('test.cloning.yaml');
    expect($content)->toContain('value: redacted@example.com');
});

it('cancels when user declines confirmation', function (): void {
    Storage::fake('local');
    Storage::disk('local')->put('test.cloning.yaml', makeTestCloningYaml());
    $original = Storage::disk('local')->get('test.cloning.yaml');

    $this->artisan('cloning:column:edit', [
        'file' => 'test.cloning.yaml',
        '--table' => 'users',
        '--column' => 'email',
        '--strategy' => 'keep',
    ])
        ->expectsConfirmation('Apply this change?', 'no')
        ->expectsOutputToContain('Cancelled.')
        ->assertExitCode(ExitCode::Success->value);

    // File should be unchanged
    expect(Storage::disk('local')->get('test.cloning.yaml'))->toBe($original);
});

it('shows no-arguments hint and skips faker_arguments prompt for no-arg methods', function (): void {
    Storage::fake('local');
    Storage::disk('local')->put('test.cloning.yaml', makeTestCloningYaml());

    // firstName takes no arguments — the hint should appear and no argument prompt shown
    $this->artisan('cloning:column:edit', [
        'file' => 'test.cloning.yaml',
        '--table' => 'users',
        '--column' => 'email',
        '--strategy' => 'fake',
        '--faker-method' => 'firstName',
        // No --faker-arguments flag: the command should skip asking for them
    ])
        ->expectsOutputToContain('firstName() takes no arguments')
        ->expectsConfirmation('Apply this change?', 'yes')
        ->assertExitCode(ExitCode::Success->value);

    $content = Storage::disk('local')->get('test.cloning.yaml');
    expect($content)->toContain('faker_method: firstName');
    // faker_arguments should be written as an empty collection ([] or {})
    expect($content)->toMatch('/faker_arguments:\s*(\[\]|\{.*\})/');
});

it('shows argument hint for methods that accept arguments', function (): void {
    Storage::fake('local');
    Storage::disk('local')->put('test.cloning.yaml', makeTestCloningYaml());

    $this->artisan('cloning:column:edit', [
        'file' => 'test.cloning.yaml',
        '--table' => 'users',
        '--column' => 'email',
        '--strategy' => 'fake',
        '--faker-method' => 'numberBetween',
        '--faker-arguments' => '1,100',
    ])
        ->expectsOutputToContain('numberBetween() arguments:')
        ->expectsConfirmation('Apply this change?', 'yes')
        ->assertExitCode(ExitCode::Success->value);

    $content = Storage::disk('local')->get('test.cloning.yaml');
    expect($content)->toContain('faker_method: numberBetween');
});
