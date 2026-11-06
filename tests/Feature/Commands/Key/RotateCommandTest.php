<?php

declare(strict_types=1);

use App\Enums\ExitCode;
use App\Services\Config\ConfigService;
use App\Services\Config\DotenvFile;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Console\Tester\CommandTester;

const G11_TEST_KEY = 'base64:ROzyPViGEkER6n3g0OHblde5CygEIcuDlAFbca99xvM=';

beforeEach(function (): void {
    Storage::fake('local');
});

afterEach(function (): void {
    putenv('APP_KEY='.G11_TEST_KEY);
    $_ENV['APP_KEY'] = G11_TEST_KEY;
    putenv('CLONIO_KEY');
    unset($_ENV['CLONIO_KEY'], $_SERVER['CLONIO_KEY']);
});

function g11_forgetAppKey(): void
{
    putenv('APP_KEY');
    unset($_ENV['APP_KEY'], $_SERVER['APP_KEY']);
}

/**
 * @param  array<string, mixed>  $args
 * @param  list<string>|null  $inputs
 * @return array{0: int, 1: string}
 */
function g11_rotate(array $args = ['--force' => true], ?array $inputs = null): array
{
    $tester = new CommandTester(Artisan::all()['key:rotate']);

    if ($inputs !== null) {
        $tester->setInputs($inputs);
    }

    $exit = $tester->execute($args, ['interactive' => $inputs !== null]);

    return [$exit, $tester->getDisplay()];
}

/**
 * @return array<string, mixed>
 */
function g11_config(): array
{
    return [
        'connections' => [
            'prod' => ['host' => 'db', 'port' => 3306, 'password' => 'encrypted:'.Crypt::encryptString('prod-secret')],
            'dev' => ['host' => 'localhost', 'password' => 'env:DEV_PASSWORD'],
            'local' => ['password' => 'plain'],
        ],
        'audit' => [
            'channels' => [
                'hook' => ['type' => 'webhook', 'webhook_url' => 'encrypted:'.Crypt::encryptString('https://hook'), 'enabled' => true],
            ],
            'use' => ['hook'],
        ],
    ];
}

function g11_saveConfig(): void
{
    Storage::put('clonio.json', (string) json_encode(g11_config()));
}

/**
 * @return array<string, mixed>
 */
function g11_loadConfig(): array
{
    /** @var array<string, mixed> */
    return json_decode((string) Storage::get('clonio.json'), true);
}

function g11_decrypt(string $stored, string $key): string
{
    expect($stored)->toStartWith('encrypted:');

    return (new Encrypter((string) base64_decode(substr($key, strlen('base64:')), true), 'AES-256-CBC'))
        ->decryptString(substr($stored, strlen('encrypted:')));
}

function g11_summary(string $current, string $source, int $count): string
{
    return sprintf("  Current key: %s (%s)\n", $current, $source)
        .sprintf("  Encrypted secrets in clonio.json: %d\n", $count)
        ."\n"
        ."  ⚠  Audit logs signed so far verify only with the current key.\n"
        ."     Keep it for as long as you need to verify them.\n"
        ."\n";
}

function g11_assertRotated(string $newKey): void
{
    $config = g11_loadConfig();
    $expected = g11_config();

    expect(g11_decrypt($config['connections']['prod']['password'], $newKey))->toBe('prod-secret')
        ->and(g11_decrypt($config['audit']['channels']['hook']['webhook_url'], $newKey))->toBe('https://hook')
        ->and($config['connections']['prod']['port'])->toBe(3306)
        ->and($config['connections']['dev'])->toBe($expected['connections']['dev'])
        ->and($config['connections']['local'])->toBe($expected['connections']['local'])
        ->and($config['audit']['channels']['hook']['enabled'])->toBeTrue()
        ->and($config['audit']['use'])->toBe(['hook'])
        ->and(fileperms(Storage::path('clonio.json')) & 0777)->toBe(0600);
}

it('fails when no encryption key is set', function (): void {
    g11_forgetAppKey();
    g11_saveConfig();
    $before = Storage::get('clonio.json');

    [$exit, $display] = g11_rotate();

    expect($exit)->toBe(ExitCode::ConfigError->value)
        ->and($display)->toBe("No encryption key found. Set CLONIO_KEY or run `clonio init` first.\n")
        ->and(Storage::get('clonio.json'))->toBe($before);
    Storage::assertMissing('.env');
});

it('re-encrypts clonio.json and prints the new key when the key comes from the environment', function (): void {
    g11_saveConfig();

    [$exit, $display] = g11_rotate();

    expect($exit)->toBe(ExitCode::Success->value);
    expect(preg_match('/^  CLONIO_KEY=(base64:[A-Za-z0-9+\/]{43}=)$/m', $display, $match))->toBe(1);
    $newKey = $match[1];

    expect($display)->toBe(
        g11_summary('APP_KEY', 'environment', 2)
        ."  ✓  Re-encrypted 2 secrets in clonio.json.\n"
        ."\n"
        ."  Store the new key as CLONIO_KEY wherever Clonio runs (e.g. your CI secret store).\n"
        ."  It is shown only now:\n"
        ."\n"
        ."  CLONIO_KEY={$newKey}\n"
        ."\n"
    );
    expect(strlen((string) base64_decode(substr($newKey, strlen('base64:')), true)))->toBe(32);
    g11_assertRotated($newKey);
    Storage::assertMissing('.env');
});

it('prefers CLONIO_KEY from the environment and uses the singular for one secret', function (): void {
    putenv('CLONIO_KEY='.G11_TEST_KEY);
    Storage::put('clonio.json', (string) json_encode(['connections' => ['prod' => ['password' => 'encrypted:'.Crypt::encryptString('x')]]]));

    [$exit, $display] = g11_rotate();

    expect($exit)->toBe(ExitCode::Success->value)
        ->and($display)->toStartWith(g11_summary('CLONIO_KEY', 'environment', 1)."  ✓  Re-encrypted 1 secret in clonio.json.\n");
});

it('writes CLONIO_KEY next to APP_KEY in .env and keeps APP_KEY', function (): void {
    g11_forgetAppKey();
    Storage::put('.env', "DB_HOST=db\nAPP_KEY=".G11_TEST_KEY."\n");
    g11_saveConfig();

    [$exit, $display] = g11_rotate();

    $dotenv = (string) Storage::get('.env');
    expect($exit)->toBe(ExitCode::Success->value)
        ->and($dotenv)->toMatch('/^DB_HOST=db\nAPP_KEY='.preg_quote(G11_TEST_KEY, '/').'\nCLONIO_KEY=base64:[A-Za-z0-9+\/]{43}=\n$/')
        ->and(fileperms(Storage::path('.env')) & 0777)->toBe(0600)
        ->and($display)->toBe(
            g11_summary('APP_KEY', '.env', 2)
            ."  ✓  Wrote the new CLONIO_KEY to .env.\n"
            ."  ✓  Re-encrypted 2 secrets in clonio.json.\n"
            ."  ℹ  Clonio now uses CLONIO_KEY. APP_KEY stays in .env for other tools;\n"
            ."     remove it if nothing else needs it.\n"
            ."\n"
        )
        ->and($display)->not->toContain('base64:');

    g11_assertRotated((string) (new DotenvFile)->get('CLONIO_KEY'));
});

it('replaces CLONIO_KEY in .env without the APP_KEY hint', function (): void {
    g11_forgetAppKey();
    Storage::put('.env', 'CLONIO_KEY='.G11_TEST_KEY."\nB=2\n");
    g11_saveConfig();

    [$exit, $display] = g11_rotate();

    $newKey = (string) (new DotenvFile)->get('CLONIO_KEY');
    expect($exit)->toBe(ExitCode::Success->value)
        ->and($newKey)->not->toBe(G11_TEST_KEY)
        ->and((string) Storage::get('.env'))->toBe("CLONIO_KEY={$newKey}\nB=2\n")
        ->and($display)->toBe(
            g11_summary('CLONIO_KEY', '.env', 2)
            ."  ✓  Wrote the new CLONIO_KEY to .env.\n"
            ."  ✓  Re-encrypted 2 secrets in clonio.json.\n"
            ."\n"
        );

    g11_assertRotated($newKey);
});

it('writes a new key to .env when there is no clonio.json', function (): void {
    g11_forgetAppKey();
    Storage::put('.env', 'CLONIO_KEY='.G11_TEST_KEY."\n");

    [$exit, $display] = g11_rotate();

    expect($exit)->toBe(ExitCode::Success->value)
        ->and((string) Storage::get('.env'))->toMatch('/^CLONIO_KEY=base64:[A-Za-z0-9+\/]{43}=\n$/')
        ->and($display)->toContain("  ✓  Re-encrypted 0 secrets in clonio.json.\n");
    Storage::assertMissing('clonio.json');
});

it('does not create clonio.json in environment mode when there is none', function (): void {
    [$exit, $display] = g11_rotate();

    expect($exit)->toBe(ExitCode::Success->value)
        ->and($display)->toStartWith(g11_summary('APP_KEY', 'environment', 0));
    Storage::assertMissing('clonio.json');
});

it('changes nothing when the user declines', function (): void {
    g11_forgetAppKey();
    Storage::put('.env', 'CLONIO_KEY='.G11_TEST_KEY."\n");
    g11_saveConfig();
    $before = Storage::get('clonio.json');

    [$exit, $display] = g11_rotate([], ['no']);

    expect($exit)->toBe(ExitCode::Success->value)
        ->and($display)->toBe(
            g11_summary('CLONIO_KEY', '.env', 2)
            ."   Rotate the key? (yes/no) [no]:\n"
            ." > \n"
            ."  Cancelled.\n"
        )
        ->and(Storage::get('clonio.json'))->toBe($before)
        ->and(Storage::get('.env'))->toBe('CLONIO_KEY='.G11_TEST_KEY."\n");
});

it('rotates when the user confirms', function (): void {
    g11_saveConfig();

    [$exit, $display] = g11_rotate([], ['yes']);

    expect($exit)->toBe(ExitCode::Success->value)
        ->and($display)->toContain("   Rotate the key? (yes/no) [no]:\n > \n  ✓  Re-encrypted 2 secrets in clonio.json.\n");
});

it('fails and changes nothing when a secret cannot be decrypted', function (): void {
    Storage::put('clonio.json', (string) json_encode(['connections' => ['prod' => ['password' => 'encrypted:garbage']]]));
    $before = Storage::get('clonio.json');

    [$exit, $display] = g11_rotate();

    expect($exit)->toBe(ExitCode::ConfigError->value)
        ->and($display)->toBe("Could not decrypt connections.prod.password — check CLONIO_KEY (or APP_KEY).\n")
        ->and(Storage::get('clonio.json'))->toBe($before);
});

it('names a numeric top-level key when its secret cannot be decrypted', function (): void {
    Storage::put('clonio.json', '{"0": "encrypted:garbage"}');

    [$exit, $display] = g11_rotate();

    expect($exit)->toBe(ExitCode::ConfigError->value)
        ->and($display)->toBe("Could not decrypt 0 — check CLONIO_KEY (or APP_KEY).\n");
});

it('fails when clonio.json is not valid JSON', function (): void {
    Storage::put('clonio.json', '{');

    [$exit, $display] = g11_rotate();

    expect($exit)->toBe(ExitCode::ConfigError->value)
        ->and($display)->toBe(sprintf("Invalid JSON in %s\n", Storage::path('clonio.json')));
});

it('fails and keeps clonio.json when .env cannot be written', function (): void {
    g11_forgetAppKey();
    Storage::put('.env', 'CLONIO_KEY='.G11_TEST_KEY."\n");
    g11_saveConfig();
    $before = Storage::get('clonio.json');

    $dotenv = Mockery::mock(DotenvFile::class)->makePartial();
    $dotenv->shouldReceive('set')->once()->andThrow(new RuntimeException('Cannot write .env'));
    $this->app->instance(DotenvFile::class, $dotenv);

    [$exit, $display] = g11_rotate();

    expect($exit)->toBe(ExitCode::IoError->value)
        ->and($display)->toEndWith("Cannot write .env\n")
        ->and(Storage::get('clonio.json'))->toBe($before);
});

it('puts .env back when clonio.json cannot be saved', function (): void {
    g11_forgetAppKey();
    Storage::put('.env', 'APP_KEY='.G11_TEST_KEY."\n");
    g11_saveConfig();

    $config = Mockery::mock(ConfigService::class)->makePartial();
    $config->shouldReceive('save')->once()->andThrow(new RuntimeException('Cannot write clonio.json'));
    $this->app->instance(ConfigService::class, $config);

    [$exit, $display] = g11_rotate();

    expect($exit)->toBe(ExitCode::IoError->value)
        ->and($display)->toEndWith("Cannot write clonio.json\n")
        ->and($display)->not->toContain('✓')
        ->and(Storage::get('.env'))->toBe('APP_KEY='.G11_TEST_KEY."\n");
});

it('fails without printing the key when clonio.json cannot be saved in environment mode', function (): void {
    g11_saveConfig();

    $config = Mockery::mock(ConfigService::class)->makePartial();
    $config->shouldReceive('save')->once()->andThrow(new RuntimeException('Cannot write clonio.json'));
    $this->app->instance(ConfigService::class, $config);

    [$exit, $display] = g11_rotate();

    expect($exit)->toBe(ExitCode::IoError->value)
        ->and($display)->toBe(g11_summary('APP_KEY', 'environment', 2)."Cannot write clonio.json\n");
});

it('counts the secrets of each run separately', function (): void {
    putenv('CLONIO_KEY='.G11_TEST_KEY);
    g11_saveConfig();

    g11_rotate();
    g11_saveConfig();
    [, $display] = g11_rotate();

    expect($display)->toStartWith(g11_summary('CLONIO_KEY', 'environment', 2));
});
