<?php

namespace App\Onenew;

use Illuminate\Support\ServiceProvider;

class OnenewServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(\App\Services\UpdateService::class, OnenewUpdateService::class);
        config()->set('queue.connections.onenew_migration',['driver'=>'redis','connection'=>'default','queue'=>'onenew_migration','retry_after'=>1900,'block_for'=>null]);
        $worker=['connection'=>'onenew_migration','queue'=>['onenew_migration'],'balance'=>'simple','minProcesses'=>1,'maxProcesses'=>1,'memory'=>128,'tries'=>1,'timeout'=>1800];
        config()->set('horizon.environments.production.migration',$worker);
        config()->set('horizon.environments.local.migration',$worker);
    }

    public function boot(): void
    {
        $root = '/opt/onenew';
        $this->loadMigrationsFrom($root . '/user-portal/backend/database/migrations');
        $this->loadMigrationsFrom($root . '/traffic/database/migrations');
        $this->loadMigrationsFrom($root . '/runtime/database/migrations');
        $this->loadMigrationsFrom($root . '/remote-upgrade/database/migrations');
        $this->loadMigrationsFrom($root . '/external/database/migrations');
        $this->loadMigrationsFrom($root . '/egress/database/migrations');
        $this->loadMigrationsFrom($root . '/energy/database/migrations');
        $this->loadMigrationsFrom($root . '/bandwidth/database/migrations');
        $this->loadMigrationsFrom($root . '/landing-library/database/migrations');
        $this->commands([\App\Onenew\Traffic\PruneTelemetry::class,\App\Onenew\Migration\PruneMigrations::class,\App\Onenew\Migration\RestoreAssets::class]);
        require $root . '/health/routes.php';
        require $root . '/user-portal/backend/routes.php';
        require $root . '/traffic/routes.php';
        require $root . '/exit-probe/routes.php';
        require $root . '/remote-upgrade/routes.php';
        require $root . '/landing-library/routes.php';
        require $root . '/external/routes.php';
        require $root . '/egress/routes.php';
        require $root . '/migration/routes.php';
        require $root . '/web-restore/routes.php';
        require $root . '/energy/routes.php';
        require $root . '/runtime/routes.php';
    }
}
