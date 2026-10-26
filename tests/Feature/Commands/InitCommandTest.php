<?php

declare(strict_types=1);

use App\Enums\ExitCode;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Console\Tester\CommandTester;

beforeEach(function (): void {
    Storage::fake('local');
});

afterEach(function (): void {
    // Restore APP_KEY in process env in case a test cleared it
    putenv('APP_KEY=base64:ROzyPViGEkER6n3g0OHblde5CygEIcuDlAFbca99xvM=');
});

it('reports success when APP_KEY is in system environment', function (): void {
    // APP_KEY is already set via phpunit.xml env + putenv
    $this->artisan('init')
        ->expectsOutputToContain('APP_KEY found in environment')
        ->assertExitCode(0);
});

it('reports success when APP_KEY is only in .env file', function (): void {
    putenv('APP_KEY');
    unset($_ENV['APP_KEY'], $_SERVER['APP_KEY']);

    Storage::put('.env', "APP_KEY=base64:somekey\n");

    $this->artisan('init')
        ->expectsOutputToContain('APP_KEY found in .env')
        ->assertExitCode(0);
});

it('creates .env with APP_KEY when none exists', function (): void {
    putenv('APP_KEY');
    unset($_ENV['APP_KEY'], $_SERVER['APP_KEY']);

    $this->artisan('init')
        ->expectsOutputToContain('Generating .env with a new key')
        ->expectsOutputToContain('Created .env with APP_KEY')
        ->assertExitCode(0);

    Storage::assertExists('.env');

    $content = Storage::get('.env');
    expect($content)->toBeString()->toContain('APP_KEY=base64:');
});

it('appends APP_KEY to existing .env that has no key', function (): void {
    putenv('APP_KEY');
    unset($_ENV['APP_KEY'], $_SERVER['APP_KEY']);

    Storage::put('.env', "DB_HOST=localhost\nDB_PORT=5432\n");

    $this->artisan('init')
        ->expectsOutputToContain('Created .env with APP_KEY')
        ->assertExitCode(0);

    $content = Storage::get('.env');
    expect($content)
        ->toBeString()
        ->toContain('DB_HOST=localhost')
        ->toContain('APP_KEY=base64:');
});

it('auto-appends .env and clonio.json to .gitignore when both are missing', function (): void {
    putenv('APP_KEY');
    unset($_ENV['APP_KEY'], $_SERVER['APP_KEY']);

    Storage::put('.gitignore', "/vendor\n/builds\n");

    $this->artisan('init')
        ->expectsOutputToContain('Added to .gitignore: .env')
        ->expectsOutputToContain('Added to .gitignore: clonio.json')
        ->assertExitCode(0);

    $content = Storage::get('.gitignore');
    expect($content)->toContain('.env')->toContain('clonio.json');
});

it('only appends clonio.json when .env is already in .gitignore', function (): void {
    putenv('APP_KEY');
    unset($_ENV['APP_KEY'], $_SERVER['APP_KEY']);

    Storage::put('.gitignore', "/vendor\n.env\n");

    $this->artisan('init')
        ->doesntExpectOutputToContain('Added to .gitignore: .env')
        ->expectsOutputToContain('Added to .gitignore: clonio.json')
        ->assertExitCode(0);

    $content = Storage::get('.gitignore');
    expect($content)->toContain('clonio.json');
});

it('appends nothing when both .env and clonio.json are already in .gitignore', function (): void {
    putenv('APP_KEY');
    unset($_ENV['APP_KEY'], $_SERVER['APP_KEY']);

    Storage::put('.gitignore', "/vendor\n.env\nclonio.json\n");

    $this->artisan('init')
        ->doesntExpectOutputToContain('Added to .gitignore')
        ->assertExitCode(0);
});

it('prints a note when no .gitignore exists', function (): void {
    putenv('APP_KEY');
    unset($_ENV['APP_KEY'], $_SERVER['APP_KEY']);

    $this->artisan('init')
        ->expectsOutputToContain('No .gitignore found')
        ->assertExitCode(0);
});

it('auto-appends to .gitignore even when APP_KEY already exists in environment', function (): void {
    // APP_KEY already in env from beforeEach
    Storage::put('.gitignore', "/vendor\n");

    $this->artisan('init')
        ->expectsOutputToContain('Added to .gitignore: .env')
        ->expectsOutputToContain('Added to .gitignore: clonio.json')
        ->assertExitCode(0);
});

it('cancels force regeneration when user declines', function (): void {
    Storage::put('.env', "APP_KEY=base64:existingkey\n");

    $this->artisan('init', ['--force' => true])
        ->expectsOutputToContain('APP_KEY already exists')
        ->expectsConfirmation('  Regenerate key?', 'no')
        ->expectsOutputToContain('Cancelled.')
        ->assertExitCode(0);

    $content = Storage::get('.env');
    expect($content)->toBeString()->toContain('APP_KEY=base64:existingkey');
});

it('replaces APP_KEY in .env when force is confirmed', function (): void {
    Storage::put('.env', "APP_KEY=base64:oldkey\n");

    $this->artisan('init', ['--force' => true])
        ->expectsConfirmation('  Regenerate key?', 'yes')
        ->expectsOutputToContain('Created .env with APP_KEY')
        ->assertExitCode(0);

    $content = Storage::get('.env');
    expect($content)
        ->toBeString()
        ->toContain('APP_KEY=base64:')
        ->not->toContain('APP_KEY=base64:oldkey');
});

it('displays hint about created audit channels on fresh init', function (): void {
    putenv('APP_KEY');
    unset($_ENV['APP_KEY'], $_SERVER['APP_KEY']);

    $this->artisan('init')
        ->expectsOutputToContain('Created clonio.json with default audit channels: local, stdout, stderr')
        ->assertExitCode(0);
});

it('displays ready message when audit channels already exist', function (): void {
    // APP_KEY already in env from beforeEach
    // First init creates the channels
    $this->artisan('init')->assertExitCode(0);

    // Second init should find them already present
    $this->artisan('init')
        ->expectsOutputToContain('Audit channels in clonio.json')
        ->assertExitCode(0);
});

it('creates default audit channels in clonio.json on fresh init', function (): void {
    putenv('APP_KEY');
    unset($_ENV['APP_KEY'], $_SERVER['APP_KEY']);

    $this->artisan('init')->assertExitCode(0);

    Storage::assertExists('clonio.json');
    $raw = Storage::get('clonio.json');
    $config = json_decode($raw, true);

    expect($config['audit']['channels'])->toHaveKeys(['local', 'stdout', 'stderr']);
    expect($config['audit']['channels']['local']['type'])->toBe('local');
    expect($config['audit']['channels']['local']['path'])->toBe('./');
    expect($config['audit']['channels']['stdout']['type'])->toBe('stdout');
    expect($config['audit']['channels']['stderr']['type'])->toBe('stderr');
    expect($config['audit']['use'])->toBe(['local']);
});

it('creates default audit channels even when APP_KEY already exists', function (): void {
    // APP_KEY already in env from beforeEach
    $this->artisan('init')->assertExitCode(0);

    Storage::assertExists('clonio.json');
    $raw = Storage::get('clonio.json');
    $config = json_decode($raw, true);

    expect($config['audit']['channels'])->toHaveKeys(['local', 'stdout', 'stderr']);
});

it('does not overwrite existing audit channels on re-init', function (): void {
    putenv('APP_KEY');
    unset($_ENV['APP_KEY'], $_SERVER['APP_KEY']);

    // Pre-populate with a custom local channel
    Storage::put('clonio.json', json_encode([
        'connections' => [],
        'audit' => [
            'channels' => [
                'local' => [
                    'type' => 'local',
                    'path' => './custom-path',
                ],
            ],
            'use' => ['local'],
        ],
    ]));

    $this->artisan('init')->assertExitCode(0);

    $raw = Storage::get('clonio.json');
    $config = json_decode($raw, true);

    // Custom path must NOT be overwritten
    expect($config['audit']['channels']['local']['path'])->toBe('./custom-path');
    // stdout and stderr should be added since they didn't exist
    expect($config['audit']['channels'])->toHaveKeys(['stdout', 'stderr']);
});

it('returns an IO error when a new .env cannot be written', function (): void {
    // No APP_KEY anywhere -> the command tries to create a fresh .env.
    // Making the disk root read-only forces Storage::put() to fail, which
    // bubbles up as a RuntimeException and is reported as an IO error.
    putenv('APP_KEY');
    unset($_ENV['APP_KEY'], $_SERVER['APP_KEY']);

    $root = Storage::path('');
    chmod($root, 0500);

    try {
        $this->artisan('init')
            ->expectsOutputToContain('permission denied')
            ->assertExitCode(ExitCode::IoError->value);
    } finally {
        chmod($root, 0755);
    }
});

it('returns an IO error when an existing .env cannot be overwritten', function (): void {
    // An existing .env without an APP_KEY would normally be appended to, but
    // making the file itself read-only forces the write to fail.
    putenv('APP_KEY');
    unset($_ENV['APP_KEY'], $_SERVER['APP_KEY']);

    Storage::put('.env', "DB_HOST=localhost\n");
    $path = Storage::path('.env');
    chmod($path, 0400);

    try {
        $this->artisan('init')
            ->expectsOutputToContain('permission denied')
            ->assertExitCode(ExitCode::IoError->value);
    } finally {
        chmod($path, 0644);
    }
});

it('returns an IO error when an existing .env cannot be read', function (): void {
    // Simulate a permission-denied read: the .env exists but Storage::get()
    // returns null. This exercises both the keyInDotenvFile() guard and the
    // read guard inside writeDotenv().
    putenv('APP_KEY');
    unset($_ENV['APP_KEY'], $_SERVER['APP_KEY']);

    Storage::shouldReceive('exists')->with('.env')->andReturn(true);
    Storage::shouldReceive('get')->with('.env')->andReturn(null);
    Storage::shouldReceive('path')->with('.env')->andReturn('/tmp/clonio-unreadable.env');

    $this->artisan('init')
        ->expectsOutputToContain('permission denied')
        ->assertExitCode(ExitCode::IoError->value);
});

it('skips .gitignore handling when the file cannot be read', function (): void {
    // APP_KEY is present (system env) so the command proceeds to the
    // .gitignore/audit-channel housekeeping. A .gitignore that exists but
    // reads back as null must be skipped silently.
    $real = sys_get_temp_dir().'/clonio_init_'.uniqid();
    mkdir($real);

    Storage::shouldReceive('exists')->with('.env')->andReturn(false);
    Storage::shouldReceive('exists')->with('.gitignore')->andReturn(true);
    Storage::shouldReceive('get')->with('.gitignore')->andReturn(null);
    Storage::shouldReceive('exists')->with('clonio.json')->andReturn(false);
    Storage::shouldReceive('path')->andReturnUsing(function (string $file = '') use ($real): string {
        $p = $real.'/'.$file;

        if (! file_exists($p)) {
            touch($p);
        }

        return $p;
    });
    Storage::shouldReceive('put')->andReturnUsing(
        fn (string $file, string $contents): bool => (bool) file_put_contents($real.'/'.$file, $contents),
    );

    try {
        $this->artisan('init')
            ->doesntExpectOutputToContain('Added to .gitignore')
            ->assertExitCode(ExitCode::Success->value);
    } finally {
        exec('rm -rf '.escapeshellarg($real));
    }
});

function g9_runInit(array $args = [], ?array $inputs = null): array
{
    $tester = new CommandTester(Artisan::all()['init']);

    if ($inputs !== null) {
        $tester->setInputs($inputs);
    }

    $exit = $tester->execute($args, ['interactive' => $inputs !== null]);

    return [$exit, $tester->getDisplay()];
}

function g9_initLogo(): string
{
    $lines = explode("\n", (string) file_get_contents(resource_path('ascii-art/clonio-logo-with-shadow.txt')));

    return implode('', array_map(fn (string $line): string => '  '.$line."\n", $lines))
        ."  👻  Clone your production database to dev and test environments –\n"
        ."     with automatic anonymization, schema versioning, and full audit trails.\n"
        ."\n"
        ."  Checking for APP_KEY ...\n";
}

function g9_forgetAppKey(): void
{
    putenv('APP_KEY');
    unset($_ENV['APP_KEY'], $_SERVER['APP_KEY']);
}

function g9_initTail(): string
{
    return "  ℹ  No .gitignore found. Add .env and clonio.json to avoid committing secrets.\n"
        ."\n"
        ."  ✓  Created clonio.json with default audit channels: local, stdout, stderr\n"
        ."\n";
}

function g9_forceWarning(): string
{
    return "  APP_KEY already exists. --force was passed — regenerating.\n"
        ."\n"
        ."  ⚠  Warning: regenerating the key will make all existing encrypted\n"
        ."     passwords in clonio.json unreadable. You will need to re-enter\n"
        ."     them via `connection:update`.\n"
        ."\n"
        ."   Regenerate key? (yes/no) [no]:\n"
        ." > \n";
}

function g9_assertFreshDotenvKey(): string
{
    $content = (string) Storage::get('.env');

    expect($content)->toMatch('/^APP_KEY=base64:[A-Za-z0-9+\/]{43}=\n$/');

    $key = substr($content, strlen('APP_KEY='), -1);
    expect(strlen((string) base64_decode(substr($key, strlen('base64:')), true)))->toBe(32)
        ->and(fileperms(Storage::path('.env')) & 0777)->toBe(0600);

    return $key;
}

it('prints the exact transcript and writes nothing when APP_KEY is in the environment', function (): void {
    [$exit, $display] = g9_runInit();

    expect($exit)->toBe(ExitCode::Success->value)
        ->and($display)->toBe(
            g9_initLogo()
            ."  ✓  APP_KEY found in environment — no .env file needed.\n"
            .g9_initTail()
        );

    Storage::assertMissing('.env');
});

it('prints the exact transcript when an existing .env key is kept after declining --force', function (): void {
    g9_forgetAppKey();
    Storage::put('.env', "APP_KEY=base64:existingkey\n");

    // An empty answer must fall back to the default ("no").
    [$exit, $display] = g9_runInit(['--force' => true], ['']);

    expect($exit)->toBe(ExitCode::Success->value)
        ->and($display)->toBe(
            g9_initLogo()
            .g9_forceWarning()
            ."  Cancelled.\n"
            ."\n"
            .g9_initTail()
        )
        ->and(Storage::get('.env'))->toBe("APP_KEY=base64:existingkey\n");
});

it('prints the exact transcript when an environment key is regenerated with --force', function (): void {
    [$exit, $display] = g9_runInit(['--force' => true], ['yes']);

    expect($exit)->toBe(ExitCode::Success->value)
        ->and($display)->toBe(
            g9_initLogo()
            .g9_forceWarning()
            ."\n"
            ."  No APP_KEY found. Generating .env with a new key ...\n"
            ."\n"
            .sprintf("  ✓  Created .env with APP_KEY in %s\n", getcwd())
            ."\n"
            .g9_initTail()
        );

    g9_assertFreshDotenvKey();
});

it('does not warn about an existing key when --force is passed without any key', function (): void {
    g9_forgetAppKey();

    [$exit, $display] = g9_runInit(['--force' => true]);

    expect($exit)->toBe(ExitCode::Success->value)
        ->and($display)->toBe(
            g9_initLogo()
            ."  No APP_KEY found. Generating .env with a new key ...\n"
            ."\n"
            .sprintf("  ✓  Created .env with APP_KEY in %s\n", getcwd())
            ."\n"
            .g9_initTail()
        );

    g9_assertFreshDotenvKey();
});

it('treats an empty APP_KEY environment variable as missing', function (): void {
    putenv('APP_KEY=');
    unset($_ENV['APP_KEY'], $_SERVER['APP_KEY']);

    [$exit, $display] = g9_runInit();

    expect($exit)->toBe(ExitCode::Success->value)
        ->and($display)->toContain('  No APP_KEY found. Generating .env with a new key ...')
        ->and($display)->not->toContain('APP_KEY found in environment');

    g9_assertFreshDotenvKey();
});

it('appends the key after trimming trailing blank lines of an existing .env', function (): void {
    g9_forgetAppKey();
    Storage::put('.env', "DB_HOST=localhost\n\n\n");
    chmod(Storage::path('.env'), 0644);

    [$exit] = g9_runInit();

    expect($exit)->toBe(ExitCode::Success->value);

    $content = (string) Storage::get('.env');
    expect($content)->toMatch('/^DB_HOST=localhost\nAPP_KEY=base64:[A-Za-z0-9+\/]{43}=\n$/')
        ->and(fileperms(Storage::path('.env')) & 0777)->toBe(0600);
});

it('replaces an existing key in place and restricts the .env permissions', function (): void {
    g9_forgetAppKey();
    Storage::put('.env', "DB_HOST=localhost\nAPP_KEY=base64:oldkey\nDB_PORT=5432\n");
    chmod(Storage::path('.env'), 0644);

    [$exit] = g9_runInit(['--force' => true], ['yes']);

    expect($exit)->toBe(ExitCode::Success->value);

    $content = (string) Storage::get('.env');
    expect($content)->toMatch('/^DB_HOST=localhost\nAPP_KEY=base64:[A-Za-z0-9+\/]{43}=\nDB_PORT=5432\n$/')
        ->and(fileperms(Storage::path('.env')) & 0777)->toBe(0600);
});

it('writes the exact .gitignore entries after trimming trailing blank lines', function (): void {
    Storage::put('.gitignore', "/vendor\n/builds\n\n\n");

    [$exit, $display] = g9_runInit();

    expect($exit)->toBe(ExitCode::Success->value)
        ->and(Storage::get('.gitignore'))->toBe("/vendor\n/builds\n.env\nclonio.json\n")
        ->and($display)->toBe(
            g9_initLogo()
            ."  ✓  APP_KEY found in environment — no .env file needed.\n"
            ."  ✓  Added to .gitignore: .env\n"
            ."  ✓  Added to .gitignore: clonio.json\n"
            ."  ✓  Created clonio.json with default audit channels: local, stdout, stderr\n"
            ."\n"
        );
});

it('prints the ready message followed by a blank line when audit channels exist', function (): void {
    g9_runInit();

    [$exit, $display] = g9_runInit();

    expect($exit)->toBe(ExitCode::Success->value)
        ->and($display)->toEndWith("\n  ✓  Audit channels in clonio.json — ready.\n\n");
});

it('reports the exact .env path when a new .env cannot be written', function (): void {
    g9_forgetAppKey();

    $root = Storage::path('');
    chmod($root, 0500);

    try {
        [$exit, $display] = g9_runInit();
    } finally {
        chmod($root, 0755);
    }

    expect($exit)->toBe(ExitCode::IoError->value)
        ->and($display)->toEndWith(
            "  No APP_KEY found. Generating .env with a new key ...\n"
            ."\n"
            .sprintf("Cannot write %s/.env: permission denied\n", getcwd())
            ."\n"
        );
});
