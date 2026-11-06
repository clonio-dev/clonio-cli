<?php

declare(strict_types=1);

use App\Services\Secrets\SecretFiles;
use Illuminate\Support\Facades\Storage;

function g12_file(string $name, int $mode): string
{
    Storage::put($name, 'content');
    $path = Storage::path($name);
    chmod($path, $mode);

    return $path;
}

beforeEach(function (): void {
    Storage::fake('local');
});

it('reports nothing when neither file exists', function (): void {
    expect((new SecretFiles('Darwin'))->exposed())->toBe([]);
});

it('reports nothing for files only the owner can access', function (): void {
    g12_file('clonio.json', 0600);
    g12_file('.env', 0400);

    expect((new SecretFiles('Linux'))->exposed())->toBe([]);
});

it('reports a file that group or others can read or write, with its mode', function (int $mode): void {
    $path = g12_file('clonio.json', $mode);

    expect((new SecretFiles('Linux'))->exposed())->toBe([$path => $mode]);
})->with([
    'group read' => 0640,
    'group write' => 0620,
    'others read' => 0604,
    'others write' => 0602,
    'default for new files' => 0644,
    'others execute' => 0601,
    'others read and execute' => 0645,
]);

it('reports both files, clonio.json first', function (): void {
    $env = g12_file('.env', 0644);
    $config = g12_file('clonio.json', 0660);

    expect((new SecretFiles('Linux'))->exposed())->toBe([$config => 0660, $env => 0644]);
});

it('ignores a directory with the same name', function (): void {
    Storage::makeDirectory('.env');
    chmod(Storage::path('.env'), 0755);

    expect((new SecretFiles('Linux'))->exposed())->toBe([]);
});

it('skips the check on Windows', function (): void {
    g12_file('clonio.json', 0644);

    expect((new SecretFiles('Windows'))->exposed())->toBe([]);
});

it('checks the current platform by default', function (): void {
    $path = g12_file('.env', 0644);

    expect((new SecretFiles)->exposed())->toBe([$path => 0644]);
});
