<?php

declare(strict_types=1);

use App\Enums\ExitCode;
use Illuminate\Support\Facades\Storage;

it('creates pii-matchers.yaml when it does not exist', function (): void {
    Storage::fake('local');

    $this->artisan('matchers:init')
        ->assertExitCode(ExitCode::Success->value);

    expect(Storage::disk('local')->exists('pii-matchers.yaml'))->toBeTrue();
});

it('writes valid YAML with group and matcher data', function (): void {
    Storage::fake('local');

    $this->artisan('matchers:init')
        ->assertExitCode(ExitCode::Success->value);

    $content = Storage::disk('local')->get('pii-matchers.yaml');
    expect($content)->toBeString();
    expect($content)->toContain('personal_identity');
    expect($content)->toContain('email_address');
});

it('outputs group names and matcher counts', function (): void {
    Storage::fake('local');

    $this->artisan('matchers:init')
        ->expectsOutputToContain('personal_identity')
        ->expectsOutputToContain('matchers')
        ->assertExitCode(ExitCode::Success->value);
});

it('prompts for confirmation when file exists without --force', function (): void {
    Storage::fake('local');
    Storage::disk('local')->put('pii-matchers.yaml', 'existing content');

    $this->artisan('matchers:init')
        ->expectsConfirmation('  pii-matchers.yaml already exists. Overwrite?', 'no')
        ->assertExitCode(ExitCode::Success->value);

    // File should remain unchanged
    expect(Storage::disk('local')->get('pii-matchers.yaml'))->toBe('existing content');
});

it('overwrites with --force without prompting', function (): void {
    Storage::fake('local');
    Storage::disk('local')->put('pii-matchers.yaml', 'existing content');

    $this->artisan('matchers:init', ['--force' => true])
        ->assertExitCode(ExitCode::Success->value);

    $content = Storage::disk('local')->get('pii-matchers.yaml');
    expect($content)->not->toBe('existing content');
    expect($content)->toContain('personal_identity');
});

it('overwrites when user confirms', function (): void {
    Storage::fake('local');
    Storage::disk('local')->put('pii-matchers.yaml', 'existing content');

    $this->artisan('matchers:init')
        ->expectsConfirmation('  pii-matchers.yaml already exists. Overwrite?', 'yes')
        ->assertExitCode(ExitCode::Success->value);

    $content = Storage::disk('local')->get('pii-matchers.yaml');
    expect($content)->not->toBe('existing content');
});

it('writes to custom path when --path is specified', function (): void {
    Storage::fake('local');

    $this->artisan('matchers:init', ['--path' => 'custom/matchers.yaml'])
        ->assertExitCode(ExitCode::Success->value);

    expect(Storage::disk('local')->exists('custom/matchers.yaml'))->toBeTrue();
});

it('outputs tip about gitignore', function (): void {
    Storage::fake('local');

    $this->artisan('matchers:init')
        ->expectsOutputToContain('safe to commit')
        ->assertExitCode(ExitCode::Success->value);
});
