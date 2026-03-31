<?php

namespace App\Providers;

use Dotenv\Dotenv;
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

            $key = $_ENV['APP_KEY'] ?? null;

            if (is_string($key) && $key !== '') {
                config(['app.key' => $key]);
            }
        }
    }
}
