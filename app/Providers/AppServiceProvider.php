<?php

namespace App\Providers;

use App\Services\Audit\AuditDeliveryService;
use App\Services\Audit\LocalDeliveryAdapter;
use App\Services\Cloning\CloningRunOrchestrator;
use App\Services\Cloning\DependencyResolver;
use App\Services\Cloning\RunLogWriter;
use App\Services\Cloning\SchemaReplicator;
use App\Services\Database\DatabaseConnectionService;
use App\Services\Schema\SchemaInspector;
use Dotenv\Dotenv;
use Illuminate\Support\Env;
use Illuminate\Support\ServiceProvider;
use Phar;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        config([
            'logging.channels.single.path' => Phar::running() !== '' && Phar::running() !== '0'
                    ? dirname(Phar::running(false)).'/clonio.log'
                    : storage_path('logs/clonio.log'),
        ]);
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
            localAdapter: new LocalDeliveryAdapter,
            runLog: $this->app->make(RunLogWriter::class),
        ));
    }
}
