<?php

declare(strict_types=1);

use App\Providers\AppServiceProvider;
use App\Services\Runtime\PackagedRuntimeDetector;

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
