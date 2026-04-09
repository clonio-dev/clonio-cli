<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage;

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
    expect($config['audit']['channels']['stdout']['type'])->toBe('stdout');
    expect($config['audit']['channels']['stderr']['type'])->toBe('stderr');
    expect($config['audit']['audit_log']['deliver_to'])->toContain('local');
    expect($config['audit']['run_log']['deliver_to'])->toContain('local');
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
            'audit_log' => ['deliver_to' => ['local']],
            'run_log' => ['deliver_to' => ['local']],
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
