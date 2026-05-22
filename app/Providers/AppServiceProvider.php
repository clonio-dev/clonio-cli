<?php

namespace App\Providers;

use App\Logging\AuditBuffer;
use App\Services\Config\ConfigService;
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
