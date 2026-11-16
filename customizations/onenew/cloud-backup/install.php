<?php
function applyOnenewCloudBackup(string $root):void
{
 $provider=$root.'/app/Onenew/OnenewServiceProvider.php';
 replaceOnce($provider,"        \$worker=['connection'=>'onenew_migration'", "        config()->set('queue.connections.onenew_cloud_backup',['driver'=>'redis','connection'=>'default','queue'=>'onenew_cloud_backup','retry_after'=>7500,'block_for'=>null]);\n        \$cloudWorker=['connection'=>'onenew_cloud_backup','queue'=>['onenew_cloud_backup'],'balance'=>'simple','minProcesses'=>1,'maxProcesses'=>1,'memory'=>256,'tries'=>1,'timeout'=>7200];\n        config()->set('horizon.environments.production.cloud_backup',\$cloudWorker);\n        config()->set('horizon.environments.local.cloud_backup',\$cloudWorker);\n        \$worker=['connection'=>'onenew_migration'");
 replaceOnce($provider,"        \$this->loadMigrationsFrom(\$root . '/energy/database/migrations');","        \$this->loadMigrationsFrom(\$root . '/energy/database/migrations');\n        \$this->loadMigrationsFrom(\$root . '/cloud-backup/database/migrations');");
 replaceOnce($provider,'$this->commands([','$this->commands([\\App\\Onenew\\CloudBackup\\Tick::class,');
 replaceOnce($provider,"        require \$root . '/migration/routes.php';","        require \$root . '/migration/routes.php';\n        require \$root . '/cloud-backup/routes.php';");
 replaceOnce($root.'/app/Console/Kernel.php','app(PluginManager::class)->registerPluginSchedules($schedule);','app(PluginManager::class)->registerPluginSchedules($schedule);'."\n        \$schedule->command('onenew:cloud-backup-tick')->everyMinute()->withoutOverlapping();");
 replaceOnce($root.'/app/Onenew/Migration/PruneMigrations.php','            $state=$service->status($entry->getFilename());','            if(\\App\\Onenew\\CloudBackup\\Service::protectsArtifact($entry->getFilename()))continue;'."\n".'            $state=$service->status($entry->getFilename());');
}
