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
        $this->loadMigrationsFrom($root . '/runtime/database/migrations');
        $this->loadMigrationsFrom($root . '/external/database/migrations');
        $this->loadMigrationsFrom($root . '/egress/database/migrations');
        $this->commands([\App\Onenew\Traffic\PruneTelemetry::class]);
        require $root . '/health/routes.php';
        require $root . '/user-portal/backend/routes.php';
        require $root . '/traffic/routes.php';
        require $root . '/exit-probe/routes.php';
        require $root . '/external/routes.php';
        require $root . '/egress/routes.php';
    }
}
