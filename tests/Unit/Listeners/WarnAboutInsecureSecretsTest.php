<?php

declare(strict_types=1);

use App\Listeners\WarnAboutInsecureSecrets;
use App\Services\Config\DotenvFile;
use App\Services\Secrets\SecretFiles;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

const G13_LEGACY_WARNING = '  ⚠  Clonio reads its key from APP_KEY. Run `clonio key:rotate` to move to a dedicated CLONIO_KEY.';

function g13_setEnv(string $name, ?string $value): void
{
    if ($value === null) {
        putenv($name);
        unset($_ENV[$name], $_SERVER[$name]);

        return;
    }

    putenv($name.'='.$value);
    $_ENV[$name] = $value;
    $_SERVER[$name] = $value;
}

function g13_run(string $command, ?OutputInterface $output = null): string
{
    $output ??= new BufferedOutput;

    (new WarnAboutInsecureSecrets(new SecretFiles('Linux'), new DotenvFile))
        ->handle(new CommandStarting($command, new ArrayInput([]), $output));

    return $output instanceof BufferedOutput ? $output->fetch() : '';
}

function g13_exposedConfig(): string
{
    Storage::put('clonio.json', '{}');
    $path = Storage::path('clonio.json');
    chmod($path, 0644);

    return $path;
}

function g13_fileWarning(string $path, string $mode): string
{
    return sprintf("  ⚠  %s is accessible to other users (mode %s). Run: chmod 600 '%s'\n", $path, $mode, $path);
}

beforeEach(function (): void {
    Storage::fake('local');
    g13_setEnv('CLONIO_KEY', null);
    g13_setEnv('APP_KEY', null);
});

afterEach(function (): void {
    g13_setEnv('CLONIO_KEY', null);
    g13_setEnv('APP_KEY', 'base64:ROzyPViGEkER6n3g0OHblde5CygEIcuDlAFbca99xvM=');
});

it('prints nothing when the files are private and the key is CLONIO_KEY', function (): void {
    Storage::put('clonio.json', '{}');
    chmod(Storage::path('clonio.json'), 0600);
    g13_setEnv('CLONIO_KEY', 'base64:clonio');

    expect(g13_run('cloning:run'))->toBe('');
});

it('prints nothing when there is no key', function (): void {
    expect(g13_run('connection:list'))->toBe('');
});

it('warns about each exposed file with its mode and a chmod hint', function (): void {
    $config = g13_exposedConfig();
    Storage::put('.env', "CLONIO_KEY=base64:clonio\n");
    $env = Storage::path('.env');
    chmod($env, 0660);

    expect(g13_run('cloning:run'))->toBe(g13_fileWarning($config, '0644').g13_fileWarning($env, '0660'));
});

it('warns when the key comes from APP_KEY in the environment', function (): void {
    g13_setEnv('APP_KEY', 'base64:app');

    expect(g13_run('cloning:dump'))->toBe(G13_LEGACY_WARNING."\n");
});

it('warns when the key comes from APP_KEY in .env', function (): void {
    Storage::put('.env', "APP_KEY=base64:app\n");
    chmod(Storage::path('.env'), 0600);

    expect(g13_run('connection:test'))->toBe(G13_LEGACY_WARNING."\n");
});

it('does not warn about APP_KEY when CLONIO_KEY is set too', function (): void {
    g13_setEnv('CLONIO_KEY', 'base64:clonio');
    g13_setEnv('APP_KEY', 'base64:app');

    expect(g13_run('cloning:run'))->toBe('');
});

it('leaves the APP_KEY hint to commands that handle it, but still checks the files', function (string $command): void {
    $config = g13_exposedConfig();
    g13_setEnv('APP_KEY', 'base64:app');

    expect(g13_run($command))->toBe(g13_fileWarning($config, '0644'));
})->with(['init', 'key:rotate']);

it('skips commands that never touch secrets', function (string $command): void {
    g13_exposedConfig();
    g13_setEnv('APP_KEY', 'base64:app');

    expect(g13_run($command))->toBe('');
})->with(['', 'list', 'help', 'completion', '_complete', 'about', 'update']);

it('writes to stderr, not stdout, on a console output', function (): void {
    $config = g13_exposedConfig();
    $stderr = new BufferedOutput;
    $output = Mockery::mock(ConsoleOutputInterface::class);
    $output->shouldReceive('getErrorOutput')->andReturn($stderr);
    $output->shouldNotReceive('writeln');

    g13_run('cloning:run', $output);

    expect($stderr->fetch())->toBe(g13_fileWarning($config, '0644'));
});
