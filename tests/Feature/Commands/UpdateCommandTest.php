<?php

declare(strict_types=1);

use App\Services\Update\BinaryResolver;
use Illuminate\Support\Facades\Http;

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
