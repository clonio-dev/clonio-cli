<?php

declare(strict_types=1);

use App\Enums\ExitCode;
use App\Services\Pii\PiiMatcherBaselineProvider;
use App\Services\Pii\PiiMatcherYamlWriter;
use Illuminate\Support\Facades\Storage;

it('exits with IoError when pii-matchers.yaml is not found', function (): void {
    Storage::fake('local');

    $this->artisan('matchers:update')
        ->expectsOutputToContain('File not found')
        ->expectsOutputToContain('matchers:init')
        ->assertExitCode(ExitCode::IoError->value);
});

it('reports up to date when no new matchers exist', function (): void {
    Storage::fake('local');

    // Write the current baseline to disk
    $provider = new PiiMatcherBaselineProvider;
    $writer = new PiiMatcherYamlWriter;
    $writer->write($provider->getGroups(), 'pii-matchers.yaml');

    $this->artisan('matchers:update')
        ->expectsOutputToContain('up to date')
        ->assertExitCode(ExitCode::Success->value);
});

it('dry run shows what would be added without writing', function (): void {
    Storage::fake('local');

    // Write a file with only one group (missing most baseline matchers)
    $minimalYaml = <<<'YAML'
version: "1"
groups:
  personal_identity:
    name: "Personal Identity"
    matchers:
      first_name:
        name: "First Name"
        enabled: true
        patterns:
          - "/^first_name$/i"
        transformation:
          strategy: fake
          faker_method: firstName
          faker_arguments: []
YAML;

    Storage::disk('local')->put('pii-matchers.yaml', $minimalYaml);

    $originalContent = Storage::disk('local')->get('pii-matchers.yaml');

    $this->artisan('matchers:update', ['--dry-run' => true])
        ->expectsOutputToContain('Dry run')
        ->assertExitCode(ExitCode::Success->value);

    // File must not be modified
    expect(Storage::disk('local')->get('pii-matchers.yaml'))->toBe($originalContent);
});

it('adds new matchers and writes the file', function (): void {
    Storage::fake('local');

    $minimalYaml = <<<'YAML'
version: "1"
groups:
  personal_identity:
    name: "Personal Identity"
    matchers:
      first_name:
        name: "First Name"
        enabled: true
        patterns:
          - "/^first_name$/i"
        transformation:
          strategy: fake
          faker_method: firstName
          faker_arguments: []
YAML;

    Storage::disk('local')->put('pii-matchers.yaml', $minimalYaml);

    $this->artisan('matchers:update')
        ->expectsOutputToContain('matchers added')
        ->assertExitCode(ExitCode::Success->value);

    $updatedContent = Storage::disk('local')->get('pii-matchers.yaml');
    expect($updatedContent)->toContain('email_address');
});

it('dry run shows new matchers without writing', function (): void {
    Storage::fake('local');

    $minimalYaml = <<<'YAML'
version: "1"
groups:
  personal_identity:
    name: "Personal Identity"
    matchers:
      first_name:
        name: "First Name"
        enabled: true
        patterns:
          - "/^first_name$/i"
        transformation:
          strategy: fake
          faker_method: firstName
          faker_arguments: []
YAML;

    Storage::disk('local')->put('pii-matchers.yaml', $minimalYaml);

    $this->artisan('matchers:update', ['--dry-run' => true])
        ->expectsOutputToContain('New matchers added')
        ->assertExitCode(ExitCode::Success->value);

    // Verify the file was NOT updated
    $content = Storage::disk('local')->get('pii-matchers.yaml');
    expect($content)->not->toContain('email_address');
});
