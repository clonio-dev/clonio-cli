<?php

namespace App\Providers;

use App\Enums\AuditChannelType;
use App\Services\Audit\AuditDeliveryService;
use App\Services\Audit\EmailDeliveryAdapter;
use App\Services\Audit\LocalDeliveryAdapter;
use App\Services\Audit\NtfyDeliveryAdapter;
use App\Services\Audit\S3DeliveryAdapter;
use App\Services\Audit\StderrDeliveryAdapter;
use App\Services\Audit\StdoutDeliveryAdapter;
use App\Services\Audit\WebhookDeliveryAdapter;
use App\Services\Cloning\CloningRunOrchestrator;
use App\Services\Cloning\DependencyResolver;
use App\Services\Cloning\RunLogWriter;
use App\Services\Cloning\SchemaReplicator;
use App\Services\Database\DatabaseConnectionService;
use App\Services\Schema\SchemaInspector;
use Composer\InstalledVersions;
use Dotenv\Dotenv;
use Illuminate\Support\Env;
use Illuminate\Support\ServiceProvider;
use Symfony\Component\Process\Process;

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

        // Bind RunLogWriter as a singleton per-request so the same instance is shared
        // between CloningRunOrchestrator and AuditDeliveryService during a single run.
        $this->app->singleton(RunLogWriter::class, static fn (): RunLogWriter => new RunLogWriter);

        // Bind CloningRunOrchestrator with all dependencies
        $this->app->bind(CloningRunOrchestrator::class, fn (): CloningRunOrchestrator => new CloningRunOrchestrator(
            connector: $this->app->make(DatabaseConnectionService::class),
            replicator: new SchemaReplicator(
                $this->app->make(SchemaInspector::class),
                $this->app->make(DatabaseConnectionService::class),
            ),
            resolver: new DependencyResolver,
            runLog: $this->app->make(RunLogWriter::class),
        ));

        // Bind AuditDeliveryService
        $this->app->bind(AuditDeliveryService::class, fn (): AuditDeliveryService => new AuditDeliveryService(
            runLog: $this->app->make(RunLogWriter::class),
            localAdapter: new LocalDeliveryAdapter,
            stdoutAdapter: new StdoutDeliveryAdapter,
            stderrAdapter: new StderrDeliveryAdapter,
            s3Adapter: new S3DeliveryAdapter,
            emailAdapter: new EmailDeliveryAdapter,
            teamsAdapter: new WebhookDeliveryAdapter(AuditChannelType::MsTeams),
            slackAdapter: new WebhookDeliveryAdapter(AuditChannelType::Slack),
            ntfyAdapter: new NtfyDeliveryAdapter,
        ));
    }
}
