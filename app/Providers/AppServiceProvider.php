<?php

namespace App\Providers;

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
            'logging.channels.single.path' =>
                Phar::running()
                    ? dirname(Phar::running(false)) . '/clonio.log'
                    : storage_path('logs/clonio.log')
        ]);
    }

    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }
}
