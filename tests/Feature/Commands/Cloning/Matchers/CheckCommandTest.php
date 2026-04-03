<?php

declare(strict_types=1);

use App\Enums\ExitCode;
use Illuminate\Support\Facades\Storage;

it('returns match info for email column', function (): void {
    Storage::fake('local');

    $this->artisan('matchers:check', ['column' => 'email'])
        ->expectsOutputToContain('email_address')
        ->expectsOutputToContain('contact')
        ->expectsOutputToContain('safeEmail')
        ->assertExitCode(ExitCode::Success->value);
});

it('returns no matcher found for created_at column', function (): void {
    Storage::fake('local');

    $this->artisan('matchers:check', ['column' => 'created_at'])
        ->expectsOutputToContain('no matcher found')
        ->expectsOutputToContain('strategy: keep')
        ->assertExitCode(ExitCode::Success->value);
});

it('always exits with code 0', function (): void {
    Storage::fake('local');

    $this->artisan('matchers:check', ['column' => 'some_random_column_xyz'])
        ->assertExitCode(ExitCode::Success->value);
});

it('shows transformation details for a matched column', function (): void {
    Storage::fake('local');

    $this->artisan('matchers:check', ['column' => 'password'])
        ->expectsOutputToContain('password')
        ->expectsOutputToContain('hash')
        ->expectsOutputToContain('sha256')
        ->assertExitCode(ExitCode::Success->value);
});

it('shows which pattern matched and its type', function (): void {
    Storage::fake('local');

    $this->artisan('matchers:check', ['column' => 'email'])
        ->expectsOutputToContain('Matched by:')
        ->assertExitCode(ExitCode::Success->value);
});

it('shows source as binary baseline when no file present', function (): void {
    Storage::fake('local');

    $this->artisan('matchers:check', ['column' => 'email'])
        ->expectsOutputToContain('binary baseline')
        ->assertExitCode(ExitCode::Success->value);
});
