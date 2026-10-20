<?php

declare(strict_types=1);

use App\Enums\ExitCode;
use App\Services\Pii\PiiMatcherBaselineProvider;
use App\Services\Pii\PiiMatcherYamlWriter;
use Illuminate\Support\Facades\Artisan;
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

/**
 * @param  array<string, mixed>  $params
 * @return array{0: int, 1: string}
 */
function g8_list(array $params = []): array
{
    $exitCode = Artisan::call('matchers:list', $params);

    return [$exitCode, Artisan::output()];
}

function g8_writeListFixture(string $path = 'clonio.pii-matchers.yaml'): void
{
    Storage::disk('local')->put($path, <<<'YAML'
version: "1"
groups:
  custom_stuff:
    name: "Custom Group"
    matchers:
      fk:
        name: "Fake Method"
        sensitivity: high
        transformation:
          strategy: fake
          faker_method: safeEmail
      fk_plain:
        name: "Fake Plain"
        transformation:
          strategy: fake
      hs:
        name: "Hash Md5"
        sensitivity: critical
        transformation:
          strategy: hash
          algorithm: md5
      hs_default:
        name: "Hash Default"
        sensitivity: low
        transformation:
          strategy: hash
      mk:
        name: "Mask"
        transformation:
          strategy: mask
          visible_chars: 2
      tp:
        name: "Template"
        transformation:
          strategy: template
          template: "{a}"
      tp_plain:
        name: "Template Plain"
        transformation:
          strategy: template
      off:
        name: "Off"
        enabled: false
        transformation:
          strategy: keep
  second:
    name: "Second Group"
    matchers:
      st:
        name: "Static"
        transformation:
          strategy: static
          value: "X"
YAML);
}

function g8_writeSingleListFixture(): void
{
    Storage::disk('local')->put('clonio.pii-matchers.yaml', <<<'YAML'
version: "1"
groups:
  only:
    name: "Only"
    matchers:
      one:
        name: "One"
        transformation:
          strategy: keep
YAML);
}

it('renders the exact table and totals for a custom matchers file', function (): void {
    Storage::fake('local');
    g8_writeListFixture();

    [$exitCode, $output] = g8_list();

    expect($exitCode)->toBe(ExitCode::Success->value);
    expect($output)->toBe(<<<'TXT'

  Effective PII matchers  (source: clonio.pii-matchers.yaml)

+---+--------------+------------+----------------+-------------+------------------+----------------+
|   | Group        | Key        | Name           | Sensitivity | Transformation   | Source         |
+---+--------------+------------+----------------+-------------+------------------+----------------+
| ✓ | Custom Group | fk         | Fake Method    | high        | fake → safeEmail | file           |
| ✓ | Custom Group | fk_plain   | Fake Plain     | medium      | fake             | file           |
| ✓ | Custom Group | hs         | Hash Md5       | critical    | hash → md5       | file           |
| ✓ | Custom Group | hs_default | Hash Default   | low         | hash → sha256    | file           |
| ✓ | Custom Group | mk         | Mask           | medium      | mask             | file           |
| ✓ | Custom Group | tp         | Template       | medium      | template → {a}   | file           |
| ✓ | Custom Group | tp_plain   | Template Plain | medium      | template         | file           |
| — | Custom Group | off        | Off            | medium      |                  | file, disabled |
| ✓ | Second Group | st         | Static         | medium      | static           | file           |
+---+--------------+------------+----------------+-------------+------------------+----------------+

  Total: 8 active matchers across 2 groups (1 disabled)
  Source: clonio.pii-matchers.yaml


TXT);
});

it('uses singular wording and no disabled suffix for a single active matcher', function (): void {
    Storage::fake('local');
    g8_writeSingleListFixture();

    [, $output] = g8_list();

    expect($output)->toBe(<<<'TXT'

  Effective PII matchers  (source: clonio.pii-matchers.yaml)

+---+-------+-----+------+-------------+----------------+--------+
|   | Group | Key | Name | Sensitivity | Transformation | Source |
+---+-------+-----+------+-------------+----------------+--------+
| ✓ | Only  | one | One  | medium      | keep           | file   |
+---+-------+-----+------+-------------+----------------+--------+

  Total: 1 active matcher across 1 group
  Source: clonio.pii-matchers.yaml


TXT);
});

it('reads matchers and the source label from the --path option', function (): void {
    Storage::fake('local');
    g8_writeSingleListFixture();
    Storage::disk('local')->move('clonio.pii-matchers.yaml', 'custom.yaml');

    [, $output] = g8_list(['--path' => 'custom.yaml']);

    expect($output)->toContain('  Effective PII matchers  (source: custom.yaml)');
    expect($output)->toContain('| ✓ | Only  | one | One  | medium      | keep           | file   |');
    expect($output)->toContain('  Source: custom.yaml');
});

it('falls back to the default file when --path is empty', function (): void {
    Storage::fake('local');
    g8_writeSingleListFixture();

    [, $output] = g8_list(['--path' => '']);

    expect($output)->toContain('  Effective PII matchers  (source: clonio.pii-matchers.yaml)');
    expect($output)->toContain('| ✓ | Only  | one | One  | medium      | keep           | file   |');
});

it('derives group display names from keys and labels baseline rows', function (): void {
    Storage::fake('local');

    [, $output] = g8_list();

    $baselineSource = 'binary baseline — run matchers:init to customise';
    expect($output)->toStartWith("\n  Effective PII matchers  (source: {$baselineSource})\n\n");
    expect($output)->toMatch('/\| ✓ \| Government Ids +\| national_id +\| National ID \/ SSN +\| critical +\| fake → numerify +\| baseline +\|/u');
    expect($output)->toMatch('/\| — \| Authentication +\| api_token +\|[^|]+\|[^|]+\| +\| baseline, disabled \|/u');
    expect($output)->toContain("  Source: {$baselineSource}\n");
});

it('prints nothing under no-interaction', function (): void {
    Storage::fake('local');

    [$exitCode, $output] = g8_list(['--no-interaction' => true]);

    expect($exitCode)->toBe(ExitCode::Success->value);
    expect($output)->toBe('');
});
