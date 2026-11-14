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
        require $root . '/health/routes.php';
        require $root . '/user-portal/backend/routes.php';
    }
}
