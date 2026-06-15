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

it('shows file source when clonio.pii-matchers.yaml exists', function (): void {
    Storage::fake('local');

    $provider = new PiiMatcherBaselineProvider;
    $writer = new PiiMatcherYamlWriter;
    $writer->write($provider->getGroups(), 'clonio.pii-matchers.yaml');

    $this->artisan('matchers:list')
        ->expectsOutputToContain('clonio.pii-matchers.yaml')
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

it('exits early under no-interaction without rendering the table', function (): void {
    Storage::fake('local');

    $this->artisan('matchers:list', ['--no-interaction' => true])
        ->assertExitCode(ExitCode::Success->value);
});

it('exits with ValidationError for an invalid matchers file', function (): void {
    Storage::fake('local');
    Storage::disk('local')->put('clonio.pii-matchers.yaml', "groups: not-a-mapping\n");

    $this->artisan('matchers:list')
        ->expectsOutputToContain('Error loading matchers')
        ->assertExitCode(ExitCode::ValidationError->value);
});

it('renders mask and template transformation labels', function (): void {
    Storage::fake('local');
    $yaml = <<<'YAML'
version: "1"
groups:
  custom:
    name: "Custom Group"
    matchers:
      phone:
        name: "Phone"
        enabled: true
        patterns:
          - "/^phone$/i"
        transformation:
          strategy: mask
          visible_chars: 4
          mask_char: "*"
          preserve_format: true
      handle:
        name: "Handle"
        enabled: true
        patterns:
          - "/^handle$/i"
        transformation:
          strategy: template
          template: "{userName}@x.test"
YAML;
    Storage::disk('local')->put('clonio.pii-matchers.yaml', $yaml);

    $this->artisan('matchers:list')
        ->expectsOutputToContain('mask')
        ->expectsOutputToContain('template')
        ->assertExitCode(ExitCode::Success->value);
});
