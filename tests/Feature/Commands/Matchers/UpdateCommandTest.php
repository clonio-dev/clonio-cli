<?php

declare(strict_types=1);

use App\Data\Cloning\ColumnCloningConfigData;
use App\Data\Pii\PiiMatcherData;
use App\Data\Pii\PiiMatcherGroupData;
use App\Enums\ExitCode;
use App\Services\Pii\PiiMatcherBaselineProvider;
use App\Services\Pii\PiiMatcherYamlReader;
use App\Services\Pii\PiiMatcherYamlWriter;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;
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

/**
 * @param  array<string, mixed>  $params
 * @return array{0: int, 1: string}
 */
function g8_update(array $params = []): array
{
    $exitCode = Artisan::call('matchers:update', $params);

    return [$exitCode, Artisan::output()];
}

/**
 * Writes the baseline to disk without the given group/matcher keys and with
 * the given extra (orphan) matcher keys appended to a "custom" group.
 *
 * @param  list<string>  $dropGroups
 * @param  list<string>  $dropMatchers  composite "group.matcher" keys
 * @param  list<string>  $orphans
 */
function g8_writeUpdateFixture(array $dropGroups = [], array $dropMatchers = [], array $orphans = [], string $path = 'clonio.pii-matchers.yaml'): void
{
    $groups = [];

    foreach ((new PiiMatcherBaselineProvider)->getGroups() as $group) {
        if (in_array($group->key, $dropGroups, true)) {
            continue;
        }

        $matchers = array_values(array_filter(
            $group->matchers,
            fn ($m): bool => ! in_array($group->key.'.'.$m->key, $dropMatchers, true),
        ));
        $groups[] = new PiiMatcherGroupData($group->key, $group->name, $matchers);
    }

    if ($orphans !== []) {
        $groups[] = new PiiMatcherGroupData('custom', 'Custom', array_map(
            fn (string $key): PiiMatcherData => new PiiMatcherData(
                key: $key,
                group: 'custom',
                name: ucfirst($key).' Name',
                enabled: true,
                patterns: [$key],
                transformation: new ColumnCloningConfigData($key, 'keep', null, [], null, null, null, null, null, null),
                isBaseline: false,
            ),
            $orphans,
        ));
    }

    (new PiiMatcherYamlWriter)->write($groups, $path);
}

function g8_updateRow(string $group, string $key, string $name): string
{
    return sprintf('    %-20s  →  %-30s  "%s"', $group, $key, $name);
}

it('prints the exact up-to-date output', function (): void {
    Storage::fake('local');
    g8_writeUpdateFixture();

    expect(g8_update())->toBe([0, "\n  Checking clonio.pii-matchers.yaml against binary baseline ...\n\n  clonio.pii-matchers.yaml is up to date. No changes needed.\n\n"]);
});

it('falls back to the default file when --path is empty', function (): void {
    Storage::fake('local');
    g8_writeUpdateFixture();

    expect(g8_update(['--path' => '']))->toBe([0, "\n  Checking clonio.pii-matchers.yaml against binary baseline ...\n\n  clonio.pii-matchers.yaml is up to date. No changes needed.\n\n"]);
});

it('prints the exact not-found output for a custom --path', function (): void {
    Storage::fake('local');
    g8_writeUpdateFixture();

    expect(g8_update(['--path' => 'custom.yaml']))->toBe([5, "\n  Checking custom.yaml against binary baseline ...\n\n  File not found: custom.yaml\n  Run `matchers:init` to create custom.yaml first.\n\n"]);
});

it('updates the file given via --path', function (): void {
    Storage::fake('local');
    g8_writeUpdateFixture(dropMatchers: ['authentication.private_key'], path: 'custom.yaml');

    [$code, $output] = g8_update(['--path' => 'custom.yaml']);

    expect($code)->toBe(0)
        ->and($output)->toContain('  1 matcher added.')
        ->and(Storage::disk('local')->get('custom.yaml'))->toContain('private_key:')
        ->and(Storage::disk('local')->exists('clonio.pii-matchers.yaml'))->toBeFalse();
});

it('prints the exact output for a single added matcher', function (): void {
    Storage::fake('local');
    g8_writeUpdateFixture(dropMatchers: ['authentication.private_key']);

    expect(g8_update())->toBe([0, implode("\n", [
        '',
        '  Checking clonio.pii-matchers.yaml against binary baseline ...',
        '',
        '  New matchers added:',
        g8_updateRow('authentication', 'private_key', 'Private / Encryption Key'),
        '',
        '  1 matcher added. Your existing customisations were not changed.',
        '',
        '',
    ])]);
    expect(Storage::disk('local')->get('clonio.pii-matchers.yaml'))->toContain('private_key:');
});

function g8_updateAdditionsAndOrphansLines(): array
{
    return [
        '',
        '  Checking clonio.pii-matchers.yaml against binary baseline ...',
        '',
        '  New matchers added:',
        g8_updateRow('biometric  (new group)', 'biometric_data', 'Biometric Identifier'),
        g8_updateRow('biometric  (new group)', 'dna_profile', 'DNA / Genetic Data'),
        '',
        '  Orphaned matchers (in file but no longer in baseline):',
        g8_updateRow('custom', 'alpha', 'Alpha Name'),
        g8_updateRow('custom', 'beta', 'Beta Name'),
        '  ⚠  These are no longer recognised by the current Clonio baseline.',
        '     They may be custom entries or from an older version.',
        '     Remove them manually if they are no longer needed.',
        '',
    ];
}

it('prints the exact output for new groups and orphans and writes the file', function (): void {
    Storage::fake('local');
    g8_writeUpdateFixture(dropGroups: ['biometric'], orphans: ['alpha', 'beta']);

    expect(g8_update())->toBe([0, implode("\n", [
        ...g8_updateAdditionsAndOrphansLines(),
        '  2 matchers added. 2 orphaned matchers reported. Your existing customisations were not changed.',
        '',
        '',
    ])]);

    $groups = (new PiiMatcherYamlReader)->read((string) Storage::disk('local')->get('clonio.pii-matchers.yaml'));
    $keys = array_map(fn ($g): string => $g->key, $groups);

    expect($keys)->toContain('biometric')
        ->and($keys)->toContain('custom');
});

it('prints the exact dry-run output and leaves the file untouched', function (): void {
    Storage::fake('local');
    g8_writeUpdateFixture(dropGroups: ['biometric'], orphans: ['alpha', 'beta']);
    $before = Storage::disk('local')->get('clonio.pii-matchers.yaml');

    expect(g8_update(['--dry-run' => true]))->toBe([0, implode("\n", [
        ...g8_updateAdditionsAndOrphansLines(),
        '  Dry run — no changes written.',
        '',
        '',
    ])]);
    expect(Storage::disk('local')->get('clonio.pii-matchers.yaml'))->toBe($before);
});

it('prints the exact output when only a single orphan exists', function (): void {
    Storage::fake('local');
    g8_writeUpdateFixture(orphans: ['alpha']);

    expect(g8_update())->toBe([0, implode("\n", [
        '',
        '  Checking clonio.pii-matchers.yaml against binary baseline ...',
        '',
        '  Orphaned matchers (in file but no longer in baseline):',
        g8_updateRow('custom', 'alpha', 'Alpha Name'),
        '  ⚠  These are no longer recognised by the current Clonio baseline.',
        '     They may be custom entries or from an older version.',
        '     Remove them manually if they are no longer needed.',
        '',
        '  0 matchers added. 1 orphaned matcher reported. Your existing customisations were not changed.',
        '',
        '',
    ])]);
});
