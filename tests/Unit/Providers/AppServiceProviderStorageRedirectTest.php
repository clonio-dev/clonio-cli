<?php

declare(strict_types=1);

use App\Providers\AppServiceProvider;
use App\Services\Runtime\PackagedRuntimeDetector;
use Illuminate\Support\Facades\Storage;

/**
 * Regression coverage for issue #156: `cloning:run` crashed under the PHAR /
 * standalone binary because Laravel's `LogManager::createEmergencyLogger()`
 * fallback hardcodes `storage_path()`, which resolves inside the read-only
 * `phar://` archive there. `AppServiceProvider` now redirects `storage_path()`
 * to the current working directory whenever `PackagedRuntimeDetector` reports
 * a packaged runtime, so that fallback stays writable.
 */
it('leaves storage_path untouched for a normal, non-packaged invocation', function (): void {
    $this->app->bind(
        PackagedRuntimeDetector::class,
        fn (): PackagedRuntimeDetector => new PackagedRuntimeDetector(sapi: 'cli', pharPath: '')
    );

    $before = $this->app->storagePath();

    (new AppServiceProvider($this->app))->register();

    expect($this->app->storagePath())->toBe($before);
});

it('redirects storage_path to the working directory when running as a packaged binary', function (): void {
    $this->app->bind(
        PackagedRuntimeDetector::class,
        fn (): PackagedRuntimeDetector => new PackagedRuntimeDetector(sapi: 'micro', pharPath: '')
    );

    (new AppServiceProvider($this->app))->register();

    expect($this->app->storagePath())->toBe(getcwd().DIRECTORY_SEPARATOR.'storage');
});

it('redirects storage_path to the working directory when running from a PHAR', function (): void {
    $this->app->bind(
        PackagedRuntimeDetector::class,
        fn (): PackagedRuntimeDetector => new PackagedRuntimeDetector(sapi: 'cli', pharPath: '/opt/clonio/clonio.phar')
    );

    (new AppServiceProvider($this->app))->register();

    expect($this->app->storagePath())->toBe(getcwd().DIRECTORY_SEPARATOR.'storage');
});

it('redirects storage_path to a storage directory inside an arbitrary working directory', function (): void {
    $this->app->bind(
        PackagedRuntimeDetector::class,
        fn (): PackagedRuntimeDetector => new PackagedRuntimeDetector(sapi: 'micro', pharPath: '')
    );

    $original = (string) getcwd();
    $cwd = (string) realpath(sys_get_temp_dir()).'/clonio_g9_cwd_'.uniqid();
    mkdir($cwd);
    chdir($cwd);

    try {
        (new AppServiceProvider($this->app))->register();
        $storagePath = $this->app->storagePath();
    } finally {
        chdir($original);
        rmdir($cwd);
    }

    expect($storagePath)->toBe($cwd.DIRECTORY_SEPARATOR.'storage');
});

it('merges the logging section of clonio.json over the logging config', function (): void {
    Storage::fake('local');
    Storage::put('clonio.json', json_encode([
        'logging' => ['default' => 'g9-custom', 'channels' => ['g9-custom' => ['driver' => 'errorlog']]],
    ]));

    (new AppServiceProvider($this->app))->register();

    expect(config('logging.default'))->toBe('g9-custom')
        ->and(config('logging.channels.g9-custom'))->toBe(['driver' => 'errorlog']);
});

it('uses the clonio.json logging section as the whole logging config when none is configured', function (): void {
    Storage::fake('local');
    Storage::put('clonio.json', json_encode([
        'logging' => ['default' => 'g9-only', 'channels' => ['g9-only' => ['driver' => 'errorlog']]],
    ]));
    config(['logging' => null]);

    (new AppServiceProvider($this->app))->register();

    expect(config('logging'))->toBe([
        'default' => 'g9-only',
        'channels' => ['g9-only' => ['driver' => 'errorlog']],
    ]);
});
