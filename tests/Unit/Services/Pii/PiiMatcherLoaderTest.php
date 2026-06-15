<?php

declare(strict_types=1);

use App\Services\Pii\PiiMatcherBaselineProvider;
use App\Services\Pii\PiiMatcherLoader;
use App\Services\Pii\PiiMatcherYamlReader;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    Storage::fake('local');
    $this->loader = new PiiMatcherLoader(new PiiMatcherBaselineProvider, new PiiMatcherYamlReader);
});

it('returns the baseline matcher set when no file exists', function (): void {
    $baseline = (new PiiMatcherBaselineProvider)->getMatcherSet();

    $set = $this->loader->load();

    expect($set->matchers)->toHaveCount(count($baseline->matchers));
});

it('loads matchers from a custom file path', function (): void {
    $yaml = <<<'YAML'
version: "1"
groups:
  g:
    name: "Group"
    matchers:
      email:
        name: "Email"
        patterns:
          - email
        transformation:
          strategy: fake
          faker_method: safeEmail
YAML;
    Storage::disk('local')->put('custom.yaml', $yaml);

    $set = $this->loader->load('custom.yaml');

    expect($set->matchers)->toHaveCount(1);
    expect($set->matchers[0]->key)->toBe('email');
    expect($set->match('email'))->not->toBeNull();
});

it('falls back to baseline when the file exists but cannot be read as a string', function (): void {
    $disk = Mockery::mock(Filesystem::class);
    $disk->shouldReceive('exists')->once()->andReturnTrue();
    $disk->shouldReceive('get')->once()->andReturnNull();
    Storage::shouldReceive('disk')->with('local')->andReturn($disk);

    $baseline = (new PiiMatcherBaselineProvider)->getMatcherSet();

    $set = $this->loader->load();

    expect($set->matchers)->toHaveCount(count($baseline->matchers));
});

it('flattens matchers across multiple groups', function (): void {
    $yaml = <<<'YAML'
version: "1"
groups:
  a:
    name: "A"
    matchers:
      one:
        name: "One"
        transformation:
          strategy: keep
  b:
    name: "B"
    matchers:
      two:
        name: "Two"
        transformation:
          strategy: keep
      three:
        name: "Three"
        transformation:
          strategy: keep
YAML;
    Storage::disk('local')->put('clonio.pii-matchers.yaml', $yaml);

    $set = $this->loader->load();

    expect($set->matchers)->toHaveCount(3);
});
