<?php

declare(strict_types=1);

use App\Enums\ExitCode;
use App\Services\Pii\PiiMatcherBaselineProvider;
use App\Services\Pii\PiiMatcherYamlWriter;
use Illuminate\Support\Facades\Storage;

it('shows baseline source when no file exists', function (): void {
    Storage::fake('local');

    $this->artisan('matchers:list')
        ->expectsOutputToContain('binary baseline')
        ->assertExitCode(ExitCode::Success->value);
});

it('shows file source when pii-matchers.yaml exists', function (): void {
    Storage::fake('local');

    $provider = new PiiMatcherBaselineProvider;
    $writer = new PiiMatcherYamlWriter;
    $writer->write($provider->getGroups(), 'pii-matchers.yaml');

    $this->artisan('matchers:list')
        ->expectsOutputToContain('pii-matchers.yaml')
        ->assertExitCode(ExitCode::Success->value);
});

it('lists all groups and matchers from baseline', function (): void {
    Storage::fake('local');

    $this->artisan('matchers:list')
        ->expectsOutputToContain('email_address')
        ->expectsOutputToContain('password')
        ->assertExitCode(ExitCode::Success->value);
});

it('shows disabled status for disabled matchers', function (): void {
    Storage::fake('local');

    $this->artisan('matchers:list')
        ->expectsOutputToContain('api_token')
        ->expectsOutputToContain('disabled')
        ->assertExitCode(ExitCode::Success->value);
});

it('shows total count', function (): void {
    Storage::fake('local');

    $this->artisan('matchers:list')
        ->expectsOutputToContain('Total:')
        ->assertExitCode(ExitCode::Success->value);
});
