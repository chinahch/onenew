<?php

namespace App\Onenew;

use Illuminate\Support\ServiceProvider;

class OnenewServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(\App\Services\UpdateService::class, OnenewUpdateService::class);
    }

    public function boot(): void
    {
        $root = '/opt/onenew';
        $this->loadMigrationsFrom($root . '/user-portal/backend/database/migrations');
        $this->loadMigrationsFrom($root . '/traffic/database/migrations');
        $this->commands([\App\Onenew\Traffic\PruneTelemetry::class]);
        require $root . '/health/routes.php';
        require $root . '/user-portal/backend/routes.php';
        require $root . '/traffic/routes.php';
        $this->app->booted(function (): void {
            $this->app->make(\Illuminate\Console\Scheduling\Schedule::class)
                ->command('onenew:prune-telemetry')->dailyAt('03:10')->withoutOverlapping();
        });
    }
}
