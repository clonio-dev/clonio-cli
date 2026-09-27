<?php

namespace App\Providers;

use App\Logging\AuditBuffer;
use App\Services\Config\ConfigService;
use App\Services\Runtime\PackagedRuntimeDetector;
use Composer\InstalledVersions;
use Dotenv\Dotenv;
use Illuminate\Support\Env;
use Illuminate\Support\ServiceProvider;
use Symfony\Component\Process\Process;
use Throwable;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }

    /**
     * Register any application services.
     */
    public function register(): void
    {
        $cwd = getcwd();

        if (is_string($cwd)) {
            Dotenv::createImmutable($cwd)->safeLoad();

            $key = Env::get('APP_KEY');

            if (is_string($key) && $key !== '') {
                config(['app.key' => $key]);
            }

            $this->redirectStoragePathWhenPackaged($cwd);
        }

        // Override git.version: prefer the VERSION file baked into the PHAR,
        // fall back to git describe in dev, then Composer InstalledVersions.
        $this->app->bind('git.version', function () {
            $versionFile = base_path('VERSION');

            if (is_file($versionFile)) {
                $pinned = trim((string) file_get_contents($versionFile));

                if ($pinned !== '' && $pinned !== 'unreleased') {
                    return $pinned;
                }
            }

            $process = Process::fromShellCommandline(
                'git describe --tags --abbrev=0',
                base_path()
            );
            $process->run();

            $version = trim($process->getOutput());

            if ($version !== '') {
                return $version;
            }

            return InstalledVersions::getPrettyVersion('clonio-dev/clonio-cli') ?? 'unreleased';
        });

        // Shared singleton: the audit_buffer log channel records into this instance,
        // and the cloning:run command reads `flush()` to embed JSONL in the audit artefact.
        $this->app->singleton(AuditBuffer::class, static fn (): AuditBuffer => new AuditBuffer);

        $this->mergeClonioJsonLogging();
    }

    /**
     * Redirect `storage_path()` to a writable directory next to the binary
     * when running as a PHAR or SPC-compiled standalone executable.
     *
     * `storage_path()` defaults to `base_path('storage')`, which resolves to
     * a `phar://` stream inside a packaged runtime — readable, but never
     * writable. Nothing in this app calls `storage_path()` directly, but
     * Laravel's own `LogManager::createEmergencyLogger()` falls back to it
     * whenever resolving/writing the configured log channel throws, and a
     * failed emergency write then crashes with an unhandled
     * "open mode append not supported" phar error (see issue #156). Pointing
     * `storage_path()` at the current working directory keeps that fallback
     * writable, mirroring how `clonio.json` is written to `cwd`, not the
     * read-only archive.
     *
     * This deliberately differs from the sample in the Laravel Zero logging
     * docs (https://laravel-zero.com/docs/logging), which only overrides
     * `logging.channels.single.path` when `Phar::running()` is truthy:
     *  - `config/logging.php` has no `single` channel by default, and the
     *    crash actually comes from the vendor `emergency` fallback above, not
     *    from any channel this app defines — patching `single`'s path
     *    wouldn't touch it.
     *  - `Phar::running()` alone misses the SPC micro-SAPI standalone
     *    binaries this project also ships (see `BinaryResolver`, which checks
     *    `PHP_SAPI === 'micro'` for exactly this reason) — one of the two bug
     *    reports on #156 was against that standalone binary.
     * Redirecting the global `storage_path()` in `register()` — before any
     * provider's `boot()` runs and before any `Log::` call is possible —
     * fixes the fallback regardless of which channel is active or which of
     * the two packaged runtimes is in use.
     */
    private function redirectStoragePathWhenPackaged(string $cwd): void
    {
        if (! $this->app->make(PackagedRuntimeDetector::class)->isPackaged()) {
            return;
        }

        $this->app->useStoragePath($cwd.DIRECTORY_SEPARATOR.'storage');
    }

    /**
     * Merge the `logging` section of clonio.json over the defaults in config/logging.php.
     *
     * Lets users override the default stderr level, swap channels, or add their own
     * channels (e.g. a file handler) without forking the package config. Runs in
     * register() before any Log call, so Laravel's LogManager sees the merged config
     * on first channel resolution.
     */
    private function mergeClonioJsonLogging(): void
    {
        try {
            $config = $this->app->make(ConfigService::class)->load();
        } catch (Throwable) {
            return;
        }

        $override = $config['logging'] ?? null;

        if (! is_array($override)) {
            return;
        }

        /** @var array<string, mixed> $current */
        $current = (array) config('logging');
        config(['logging' => array_replace_recursive($current, $override)]);
    }
}
