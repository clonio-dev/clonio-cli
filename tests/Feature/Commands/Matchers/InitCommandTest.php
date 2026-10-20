<?php

declare(strict_types=1);

use App\Enums\ExitCode;
use App\Services\Pii\PiiMatcherBaselineProvider;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;

it('creates clonio.pii-matchers.yaml when it does not exist', function (): void {
    Storage::fake('local');

    $this->artisan('matchers:init')
        ->assertExitCode(ExitCode::Success->value);

    expect(Storage::disk('local')->exists('clonio.pii-matchers.yaml'))->toBeTrue();
});

it('writes valid YAML with group and matcher data', function (): void {
    Storage::fake('local');

    $this->artisan('matchers:init')
        ->assertExitCode(ExitCode::Success->value);

    $content = Storage::disk('local')->get('clonio.pii-matchers.yaml');
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
    Storage::disk('local')->put('clonio.pii-matchers.yaml', 'existing content');

    $this->artisan('matchers:init')
        ->expectsConfirmation('  clonio.pii-matchers.yaml already exists. Overwrite?', 'no')
        ->assertExitCode(ExitCode::Success->value);

    // File should remain unchanged
    expect(Storage::disk('local')->get('clonio.pii-matchers.yaml'))->toBe('existing content');
});

it('overwrites with --force without prompting', function (): void {
    Storage::fake('local');
    Storage::disk('local')->put('clonio.pii-matchers.yaml', 'existing content');

    $this->artisan('matchers:init', ['--force' => true])
        ->assertExitCode(ExitCode::Success->value);

    $content = Storage::disk('local')->get('clonio.pii-matchers.yaml');
    expect($content)->not->toBe('existing content');
    expect($content)->toContain('personal_identity');
});

it('overwrites when user confirms', function (): void {
    Storage::fake('local');
    Storage::disk('local')->put('clonio.pii-matchers.yaml', 'existing content');

    $this->artisan('matchers:init')
        ->expectsConfirmation('  clonio.pii-matchers.yaml already exists. Overwrite?', 'yes')
        ->assertExitCode(ExitCode::Success->value);

    $content = Storage::disk('local')->get('clonio.pii-matchers.yaml');
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

/**
 * @param  array<string, mixed>  $params
 * @return array{0: int, 1: string}
 */
function g8_init(array $params = []): array
{
    $exitCode = Artisan::call('matchers:init', $params);

    return [$exitCode, Artisan::output()];
}

function g8_initExpectedOutput(string $path): string
{
    $groups = (new PiiMatcherBaselineProvider)->getGroups();
    $lines = ['', sprintf('  Writing PII matcher baseline to %s ...', $path), '', '  Groups written:'];
    $total = 0;

    foreach ($groups as $group) {
        $total += count($group->matchers);
        $lines[] = sprintf('    %-20s  %d matchers', $group->key, count($group->matchers));
    }

    return implode("\n", [
        ...$lines,
        '',
        sprintf('  Total: %d matchers across %d groups', $total, count($groups)),
        '',
        sprintf('  Edit %s to customise detection, then commit it to your repository.', $path),
        '  Run matchers:update after upgrading Clonio to add new baseline matchers.',
        '',
        sprintf('  Tip: %s contains no credentials and is safe to commit.', $path),
        '       Make sure clonio.json is in your .gitignore.',
        '',
        '',
    ]);
}

it('prints the exact init output', function (): void {
    Storage::fake('local');

    expect(g8_init())->toBe([0, g8_initExpectedOutput('clonio.pii-matchers.yaml')]);
});

it('reports the known baseline group lines and totals', function (): void {
    Storage::fake('local');

    [, $output] = g8_init();

    expect($output)->toContain('    personal_identity     ')
        ->and($output)->toMatch('/  Total: [1-9]\d* matchers across 10 groups\n/');
});

it('prints the exact init output for a custom --path', function (): void {
    Storage::fake('local');

    expect(g8_init(['--path' => 'custom.yaml']))->toBe([0, g8_initExpectedOutput('custom.yaml')])
        ->and(Storage::disk('local')->exists('clonio.pii-matchers.yaml'))->toBeFalse();
});

it('falls back to the default path when --path is empty', function (): void {
    Storage::fake('local');

    expect(g8_init(['--path' => '']))->toBe([0, g8_initExpectedOutput('clonio.pii-matchers.yaml')])
        ->and(Storage::disk('local')->exists('clonio.pii-matchers.yaml'))->toBeTrue();
});

it('cancels by default without interaction when the file exists', function (): void {
    Storage::fake('local');
    Storage::disk('local')->put('clonio.pii-matchers.yaml', 'existing content');

    expect(g8_init(['--no-interaction' => true]))->toBe([0, "  Cancelled.\n"])
        ->and(Storage::disk('local')->get('clonio.pii-matchers.yaml'))->toBe('existing content');
});
