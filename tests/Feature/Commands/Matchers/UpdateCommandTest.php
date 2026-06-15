<?php

declare(strict_types=1);

use App\Enums\ExitCode;
use App\Services\Pii\PiiMatcherBaselineProvider;
use App\Services\Pii\PiiMatcherYamlWriter;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;

it('exits with IoError when clonio.pii-matchers.yaml is not found', function (): void {
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
    $writer->write($provider->getGroups(), 'clonio.pii-matchers.yaml');

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

    Storage::disk('local')->put('clonio.pii-matchers.yaml', $minimalYaml);

    $originalContent = Storage::disk('local')->get('clonio.pii-matchers.yaml');

    $this->artisan('matchers:update', ['--dry-run' => true])
        ->expectsOutputToContain('Dry run')
        ->assertExitCode(ExitCode::Success->value);

    // File must not be modified
    expect(Storage::disk('local')->get('clonio.pii-matchers.yaml'))->toBe($originalContent);
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

    Storage::disk('local')->put('clonio.pii-matchers.yaml', $minimalYaml);

    $this->artisan('matchers:update')
        ->expectsOutputToContain('matchers added')
        ->assertExitCode(ExitCode::Success->value);

    $updatedContent = Storage::disk('local')->get('clonio.pii-matchers.yaml');
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

    Storage::disk('local')->put('clonio.pii-matchers.yaml', $minimalYaml);

    $this->artisan('matchers:update', ['--dry-run' => true])
        ->expectsOutputToContain('New matchers added')
        ->assertExitCode(ExitCode::Success->value);

    // Verify the file was NOT updated
    $content = Storage::disk('local')->get('clonio.pii-matchers.yaml');
    expect($content)->not->toContain('email_address');
});

it('exits with IoError when the file cannot be read', function (): void {
    $disk = Mockery::mock(Filesystem::class);
    $disk->shouldReceive('exists')->andReturnTrue();
    $disk->shouldReceive('get')->andReturnNull();
    Storage::shouldReceive('disk')->with('local')->andReturn($disk);

    $this->artisan('matchers:update')
        ->expectsOutputToContain('Cannot read')
        ->assertExitCode(ExitCode::IoError->value);
});

it('exits with ValidationError on a parse error', function (): void {
    Storage::fake('local');
    Storage::disk('local')->put('clonio.pii-matchers.yaml', 'not: [a valid matcher file');

    $this->artisan('matchers:update')
        ->expectsOutputToContain('Parse error')
        ->assertExitCode(ExitCode::ValidationError->value);
});

it('reports orphaned matchers and writes when additions also exist', function (): void {
    Storage::fake('local');

    // One baseline matcher kept + one custom (orphan) matcher absent from baseline.
    // Missing baseline matchers → additions; custom_thing → orphan.
    $yaml = <<<'YAML'
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
      custom_thing:
        name: "Custom Thing"
        enabled: true
        patterns:
          - "/^custom_thing$/i"
        transformation:
          strategy: keep
YAML;
    Storage::disk('local')->put('clonio.pii-matchers.yaml', $yaml);

    $this->artisan('matchers:update')
        ->expectsOutputToContain('Orphaned matchers')
        ->expectsOutputToContain('orphaned matcher')
        ->assertExitCode(ExitCode::Success->value);

    expect(Storage::disk('local')->get('clonio.pii-matchers.yaml'))->toContain('custom_thing');
});
