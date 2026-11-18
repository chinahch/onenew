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
        config()->set('queue.connections.onenew_ai_operator',['driver'=>'redis','connection'=>'default','queue'=>'onenew_ai_operator','retry_after'=>660,'block_for'=>null]);
        $operatorWorker=['connection'=>'onenew_ai_operator','queue'=>['onenew_ai_operator'],'balance'=>'simple','minProcesses'=>1,'maxProcesses'=>1,'memory'=>192,'tries'=>1,'timeout'=>600];
        config()->set('horizon.environments.production.ai_operator',$operatorWorker);
        config()->set('horizon.environments.local.ai_operator',$operatorWorker);
    }

    public function boot(): void
    {
        \Illuminate\Support\Facades\RateLimiter::for('onenew-ai-external',fn () =>
            \Illuminate\Cache\RateLimiting\Limit::perMinute(5)->by('ai-admin:'.(string)\Illuminate\Support\Facades\Auth::guard('sanctum')->id()));
        // Fleet agents commonly share an egress IP; isolate quotas by credential without exposing it.
        \Illuminate\Support\Facades\RateLimiter::for('onenew-upgrade-machine',fn (\Illuminate\Http\Request $request) =>
            \Illuminate\Cache\RateLimiting\Limit::perMinute(30)->by(hash('sha256',(string)$request->input('machine_id').'|'.(string)$request->bearerToken())));
        $root = '/opt/onenew';
        \Illuminate\Support\Facades\RateLimiter::for('onenew-runtime-logs-machine',fn(\Illuminate\Http\Request $r)=>\Illuminate\Cache\RateLimiting\Limit::perMinute(12)->by(hash('sha256',(string)$r->input('machine_id').'|'.(string)$r->bearerToken())));
        $this->loadMigrationsFrom($root . '/user-portal/backend/database/migrations');
        $this->loadMigrationsFrom($root . '/traffic/database/migrations');
        $this->loadMigrationsFrom($root . '/runtime/database/migrations');
        $this->loadMigrationsFrom($root . '/remote-upgrade/database/migrations');
        $this->loadMigrationsFrom($root . '/ai-operations/database/migrations');
        $this->loadMigrationsFrom($root . '/runtime-logs/database/migrations');
        $this->loadMigrationsFrom($root . '/external/database/migrations');
        $this->loadMigrationsFrom($root . '/egress/database/migrations');
        $this->loadMigrationsFrom($root . '/energy/database/migrations');
        $this->loadMigrationsFrom($root . '/bandwidth/database/migrations');
        $this->loadMigrationsFrom($root . '/landing-library/database/migrations');
        $this->commands([\App\Onenew\Traffic\PruneTelemetry::class,\App\Onenew\Migration\PruneMigrations::class,\App\Onenew\Migration\RestoreAssets::class,\App\Onenew\AiOperations\PruneOperatorTasks::class,\App\Onenew\RuntimeLogs\PruneLogs::class]);
        require $root . '/health/routes.php';
        require $root . '/user-portal/backend/routes.php';
        require $root . '/traffic/routes.php';
        require $root . '/exit-probe/routes.php';
        require $root . '/remote-upgrade/routes.php';
        require $root . '/ai-operations/routes.php';
        require $root . '/runtime-logs/routes.php';
        require $root . '/landing-library/routes.php';
        require $root . '/external/routes.php';
        require $root . '/egress/routes.php';
        require $root . '/migration/routes.php';
        require $root . '/web-restore/routes.php';
        require $root . '/energy/routes.php';
        require $root . '/runtime/routes.php';
    }
}
