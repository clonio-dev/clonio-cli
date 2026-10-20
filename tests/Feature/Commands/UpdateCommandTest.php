<?php

declare(strict_types=1);

use App\Services\Update\BinaryResolver;
use Carbon\CarbonInterval;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

function fakeBinaryResolver(string $path, string $filename = 'clonio.phar'): BinaryResolver
{
    $mock = Mockery::mock(BinaryResolver::class);
    $mock->shouldReceive('resolve')->andReturn([$path, $filename]);

    return $mock;
}

it('reports already up to date when versions match', function (): void {
    Http::fake([
        'api.github.com/*' => Http::response(['tag_name' => app()->version()]),
    ]);

    $this->app->instance(BinaryResolver::class, fakeBinaryResolver('/tmp/clonio_fake'));

    $this->artisan('update')
        ->expectsOutputToContain('Already at version')
        ->assertExitCode(0);
});

it('downloads and replaces the binary when a newer version is available', function (): void {
    $tmpBinary = (string) tempnam(sys_get_temp_dir(), 'clonio_test_');

    Http::fake([
        'api.github.com/*' => Http::response(['tag_name' => 'v99.0.0']),
        'github.com/*' => Http::response('fake-binary-content'),
    ]);

    $this->app->instance(BinaryResolver::class, fakeBinaryResolver($tmpBinary));

    $this->artisan('update')
        ->expectsOutputToContain('Target version')
        ->expectsOutputToContain('Downloading')
        ->expectsOutputToContain('Updated successfully')
        ->assertExitCode(0);

    @unlink($tmpBinary);
});

it('fails gracefully when github api is unreachable', function (): void {
    Http::fake([
        'api.github.com/*' => Http::response(null, 503),
    ]);

    $this->app->instance(BinaryResolver::class, fakeBinaryResolver('/tmp/clonio_fake'));

    $this->artisan('update')
        ->expectsOutputToContain('Could not reach GitHub')
        ->assertExitCode(1);
});

it('shows ssl warning and succeeds with --no-verify-ssl', function (): void {
    Http::fake([
        'api.github.com/*' => Http::response(['tag_name' => app()->version()]),
    ]);

    $this->app->instance(BinaryResolver::class, fakeBinaryResolver('/tmp/clonio_fake'));

    $this->artisan('update', ['--no-verify-ssl' => true])
        ->expectsOutputToContain('SSL verification disabled')
        ->expectsOutputToContain('Already at version')
        ->assertExitCode(0);
});

it('updates to a specific version when version argument is given', function (): void {
    $tmpBinary = (string) tempnam(sys_get_temp_dir(), 'clonio_test_');

    Http::fake([
        'api.github.com/*' => Http::response(['tag_name' => 'v2.1.0']),
        'github.com/*' => Http::response('fake-binary-content'),
    ]);

    $this->app->instance(BinaryResolver::class, fakeBinaryResolver($tmpBinary));

    $this->artisan('update', ['version' => '2.1.0'])
        ->expectsOutputToContain('Target version: 2.1.0')
        ->expectsOutputToContain('Updated successfully to 2.1.0')
        ->assertExitCode(0);

    @unlink($tmpBinary);
});

it('accepts version with v prefix', function (): void {
    $tmpBinary = (string) tempnam(sys_get_temp_dir(), 'clonio_test_');

    Http::fake([
        'api.github.com/*' => Http::response(['tag_name' => 'v2.1.0']),
        'github.com/*' => Http::response('fake-binary-content'),
    ]);

    $this->app->instance(BinaryResolver::class, fakeBinaryResolver($tmpBinary));

    $this->artisan('update', ['version' => 'v2.1.0'])
        ->expectsOutputToContain('Target version: 2.1.0')
        ->assertExitCode(0);

    @unlink($tmpBinary);
});

it('fails when requested version does not exist', function (): void {
    Http::fake([
        'api.github.com/*' => Http::response(['message' => 'Not Found'], 404),
    ]);

    $this->app->instance(BinaryResolver::class, fakeBinaryResolver('/tmp/clonio_fake'));

    $this->artisan('update', ['version' => '99.99.99'])
        ->assertExitCode(1);
});

it('fails gracefully when download fails', function (): void {
    Http::fake([
        'api.github.com/*' => Http::response(['tag_name' => 'v99.0.0']),
        'github.com/*' => Http::response(null, 503),
    ]);

    $this->app->instance(BinaryResolver::class, fakeBinaryResolver('/tmp/clonio_fake'));

    $this->artisan('update')
        ->expectsOutputToContain('Download failed')
        ->assertExitCode(1);
});

it('skips ssl verification for both tag lookup and download with a specific version', function (): void {
    // Exercises the --no-verify-ssl branches in resolveTargetTag (tag lookup)
    // and in the download request, while still completing the update.
    $tmpBinary = (string) tempnam(sys_get_temp_dir(), 'clonio_test_');

    Http::fake([
        'api.github.com/*' => Http::response(['tag_name' => 'v3.2.1']),
        'github.com/*' => Http::response('fake-binary-content'),
    ]);

    $this->app->instance(BinaryResolver::class, fakeBinaryResolver($tmpBinary));

    $this->artisan('update', ['version' => '3.2.1', '--no-verify-ssl' => true])
        ->expectsOutputToContain('SSL verification disabled')
        ->expectsOutputToContain('Target version: 3.2.1')
        ->expectsOutputToContain('Updated successfully to 3.2.1')
        ->assertExitCode(0);

    @unlink($tmpBinary);
});

it('fails when the downloaded file is empty', function (): void {
    // Download returns 200 but with an empty body — the binary write produces
    // a zero-byte file, which must be rejected.
    $tmpBinary = sys_get_temp_dir().'/clonio_empty_'.uniqid();

    Http::fake([
        'api.github.com/*' => Http::response(['tag_name' => 'v99.0.0']),
        'github.com/*' => Http::response('', 200),
    ]);

    $this->app->instance(BinaryResolver::class, fakeBinaryResolver($tmpBinary));

    $this->artisan('update')
        ->expectsOutputToContain('Download failed')
        ->assertExitCode(1);

    @unlink($tmpBinary.'.update');
    @unlink($tmpBinary);
});

it('fails when the latest release response has no tag_name', function (): void {
    // GitHub responds successfully but without a usable tag_name string.
    Http::fake([
        'api.github.com/*' => Http::response(['tag_name' => null]),
    ]);

    $this->app->instance(BinaryResolver::class, fakeBinaryResolver('/tmp/clonio_fake'));

    $this->artisan('update')
        ->expectsOutputToContain('Unexpected response from GitHub API')
        ->assertExitCode(1);
});

// ─── Mutation hardening ───────────────────────────────────────────────────────

/**
 * Fake GitHub with a router closure and record every request (URL, method,
 * headers and Guzzle options such as timeout/verify/sink).
 *
 * @param  Closure(string): mixed  $router
 */
function g9_fakeGithub(array &$log, Closure $router): void
{
    Sleep::fake();

    Http::fake(function (Request $request, array $options) use (&$log, $router) {
        $log[] = [
            'url' => $request->url(),
            'accept' => $request->header('Accept')[0] ?? null,
            'agent' => $request->header('User-Agent')[0] ?? null,
            'timeout' => $options['timeout'] ?? null,
            'verify' => $options['verify'] ?? null,
            'sink' => $options['sink'] ?? null,
        ];

        return $router($request->url());
    });
}

function g9_tempBinary(): string
{
    $path = sys_get_temp_dir().'/clonio_g9_update_'.uniqid();
    file_put_contents($path, 'old-binary');
    chmod($path, 0600);

    return $path;
}

function g9_cleanupBinary(string $path): void
{
    @unlink($path);
    @unlink($path.'.update');
}

function g9_assertSleptRetryDelays(int $times): void
{
    Sleep::assertSleptTimes($times);
    Sleep::assertSlept(
        fn (CarbonInterval $duration): bool => (int) $duration->totalMicroseconds === 100_000,
        $times,
    );
}

it('downloads the latest release with exact requests, output and permissions', function (): void {
    $binary = g9_tempBinary();
    $log = [];

    g9_fakeGithub($log, fn (string $url) => str_contains($url, 'api.github.com')
        ? Http::response(['tag_name' => 'v99.0.0'])
        : Http::response('fake-binary-content'));

    $this->app->instance(BinaryResolver::class, fakeBinaryResolver($binary, 'clonio-macos-aarch64'));

    try {
        $exit = Artisan::call('update');
        $display = Artisan::output();

        expect($exit)->toBe(0)
            ->and($display)->toBe(
                "Checking for updates...\n"
                .sprintf("Target version: 99.0.0 (current: %s)\n", ltrim(app()->version(), 'v'))
                ."Downloading clonio-macos-aarch64...\n"
                ."Updated successfully to 99.0.0.\n"
            )
            ->and($log)->toBe([
                [
                    'url' => 'https://api.github.com/repos/clonio-dev/clonio-cli/releases/latest',
                    'accept' => 'application/vnd.github.v3+json',
                    'agent' => 'clonio-cli',
                    'timeout' => 10,
                    'verify' => true,
                    'sink' => null,
                ],
                [
                    'url' => 'https://github.com/clonio-dev/clonio-cli/releases/download/v99.0.0/clonio-macos-aarch64',
                    'accept' => null,
                    'agent' => 'clonio-cli',
                    'timeout' => 120,
                    'verify' => true,
                    'sink' => $binary.'.update',
                ],
            ])
            ->and(file_get_contents($binary))->toBe('fake-binary-content')
            ->and(fileperms($binary) & 0777)->toBe(0755)
            ->and(file_exists($binary.'.update'))->toBeFalse();
    } finally {
        g9_cleanupBinary($binary);
    }
});

it('disables ssl verification for the latest lookup and the download', function (): void {
    $binary = g9_tempBinary();
    $log = [];

    g9_fakeGithub($log, fn (string $url) => str_contains($url, 'api.github.com')
        ? Http::response(['tag_name' => 'v99.0.0'])
        : Http::response('fake-binary-content'));

    $this->app->instance(BinaryResolver::class, fakeBinaryResolver($binary));

    try {
        $this->artisan('update', ['--no-verify-ssl' => true])->assertExitCode(0);

        expect(array_column($log, 'verify'))->toBe([false, false]);
    } finally {
        g9_cleanupBinary($binary);
    }
});

it('looks up the requested tag with the exact request options', function (string $version): void {
    $binary = g9_tempBinary();
    $log = [];

    g9_fakeGithub($log, fn (string $url) => str_contains($url, 'api.github.com')
        ? Http::response(['tag_name' => 'v2.1.0'])
        : Http::response('fake-binary-content'));

    $this->app->instance(BinaryResolver::class, fakeBinaryResolver($binary));

    try {
        $this->artisan('update', ['version' => $version])->assertExitCode(0);

        expect($log[0])->toBe([
            'url' => 'https://api.github.com/repos/clonio-dev/clonio-cli/releases/tags/v2.1.0',
            'accept' => 'application/vnd.github.v3+json',
            'agent' => 'clonio-cli',
            'timeout' => 10,
            'verify' => true,
            'sink' => null,
        ])->and($log)->toHaveCount(2);
    } finally {
        g9_cleanupBinary($binary);
    }
})->with(['2.1.0', 'v2.1.0']);

it('disables ssl verification for the requested tag lookup', function (): void {
    $binary = g9_tempBinary();
    $log = [];

    g9_fakeGithub($log, fn (string $url) => str_contains($url, 'api.github.com')
        ? Http::response(['tag_name' => 'v2.1.0'])
        : Http::response('fake-binary-content'));

    $this->app->instance(BinaryResolver::class, fakeBinaryResolver($binary));

    try {
        $this->artisan('update', ['version' => '2.1.0', '--no-verify-ssl' => true])->assertExitCode(0);

        expect(array_column($log, 'verify'))->toBe([false, false]);
    } finally {
        g9_cleanupBinary($binary);
    }
});

it('downloads the tag reported by GitHub for a requested version', function (): void {
    $binary = g9_tempBinary();
    $log = [];

    g9_fakeGithub($log, fn (string $url) => str_contains($url, 'api.github.com')
        ? Http::response(['tag_name' => 'v2.1.0'])
        : Http::response('fake-binary-content'));

    $this->app->instance(BinaryResolver::class, fakeBinaryResolver($binary));

    try {
        $this->artisan('update', ['version' => '2.1'])
            ->expectsOutput('Updated successfully to 2.1.0.')
            ->assertExitCode(0);

        expect(array_column($log, 'url'))->toBe([
            'https://api.github.com/repos/clonio-dev/clonio-cli/releases/tags/v2.1',
            'https://github.com/clonio-dev/clonio-cli/releases/download/v2.1.0/clonio.phar',
        ]);
    } finally {
        g9_cleanupBinary($binary);
    }
});

it('falls back to the requested tag when GitHub omits tag_name', function (): void {
    $binary = g9_tempBinary();
    $log = [];

    g9_fakeGithub($log, fn (string $url) => str_contains($url, 'api.github.com')
        ? Http::response(['name' => 'Release 2.2.0'])
        : Http::response('fake-binary-content'));

    $this->app->instance(BinaryResolver::class, fakeBinaryResolver($binary));

    try {
        $this->artisan('update', ['version' => '2.2.0'])
            ->expectsOutput('Updated successfully to 2.2.0.')
            ->assertExitCode(0);

        expect(array_column($log, 'url'))->toBe([
            'https://api.github.com/repos/clonio-dev/clonio-cli/releases/tags/v2.2.0',
            'https://github.com/clonio-dev/clonio-cli/releases/download/v2.2.0/clonio.phar',
        ]);
    } finally {
        g9_cleanupBinary($binary);
    }
});

it('treats an empty version argument as a request for the latest release', function (): void {
    $log = [];

    g9_fakeGithub($log, fn (string $url) => Http::response(['tag_name' => app()->version()]));

    $this->app->instance(BinaryResolver::class, fakeBinaryResolver('/tmp/clonio_fake'));

    $this->artisan('update', ['version' => ''])
        ->expectsOutputToContain('Already at version')
        ->assertExitCode(0);

    expect(array_column($log, 'url'))->toBe([
        'https://api.github.com/repos/clonio-dev/clonio-cli/releases/latest',
    ]);
});

it('retries the latest release lookup three times with a 100ms delay', function (): void {
    $log = [];

    g9_fakeGithub($log, fn (string $url) => Http::response(null, 503));

    $this->app->instance(BinaryResolver::class, fakeBinaryResolver('/tmp/clonio_fake'));

    $this->artisan('update')
        ->expectsOutput('Could not reach GitHub. Please check your internet connection.')
        ->assertExitCode(1);

    expect($log)->toHaveCount(3);
    g9_assertSleptRetryDelays(2);
});

it('retries the requested tag lookup three times and reports GitHub as unreachable', function (): void {
    $log = [];

    g9_fakeGithub($log, fn (string $url) => Http::response(null, 503));

    $this->app->instance(BinaryResolver::class, fakeBinaryResolver('/tmp/clonio_fake'));

    $this->artisan('update', ['version' => '2.1.0'])
        ->expectsOutput('Could not reach GitHub. Please check your internet connection.')
        ->assertExitCode(1);

    expect($log)->toHaveCount(3);
    g9_assertSleptRetryDelays(2);
});

it('retries the download three times with a 100ms delay', function (): void {
    $binary = g9_tempBinary();
    $log = [];

    g9_fakeGithub($log, fn (string $url) => str_contains($url, 'api.github.com')
        ? Http::response(['tag_name' => 'v99.0.0'])
        : Http::response(null, 503));

    $this->app->instance(BinaryResolver::class, fakeBinaryResolver($binary));

    try {
        $this->artisan('update')
            ->expectsOutput('Download failed.')
            ->assertExitCode(1);

        expect(array_column($log, 'url'))->toBe([
            'https://api.github.com/repos/clonio-dev/clonio-cli/releases/latest',
            'https://github.com/clonio-dev/clonio-cli/releases/download/v99.0.0/clonio.phar',
            'https://github.com/clonio-dev/clonio-cli/releases/download/v99.0.0/clonio.phar',
            'https://github.com/clonio-dev/clonio-cli/releases/download/v99.0.0/clonio.phar',
        ])->and(file_get_contents($binary))->toBe('old-binary');
        g9_assertSleptRetryDelays(2);
    } finally {
        g9_cleanupBinary($binary);
    }
});

it('rejects a non-string tag_name from the latest release lookup', function (): void {
    $log = [];

    g9_fakeGithub($log, fn (string $url) => Http::response(['tag_name' => 123]));

    $this->app->instance(BinaryResolver::class, fakeBinaryResolver('/tmp/clonio_fake'));

    $this->artisan('update')
        ->expectsOutput('Unexpected response from GitHub API.')
        ->assertExitCode(1);
});
