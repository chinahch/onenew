<?php
// Destructive fixture ONLY for the disposable CI SQLite deployment.
declare(strict_types=1);
if(getenv('ONENEW_CI_FIXTURE')!=='isolated-only'||!in_array($argv[1]??'', ['prepare','check'],true))exit(2);
require '/www/vendor/autoload.php';
$app=require '/www/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$connection=Illuminate\Support\Facades\DB::connection();
$database=realpath($connection->getDatabaseName());
$base=realpath('/www/.docker/.data');
if($connection->getDriverName()!=='sqlite'||!$database||!$base||!str_starts_with($database,$base.DIRECTORY_SEPARATOR))throw new RuntimeException('Disposable persisted SQLite fixture required');
$migration='2026_11_18_000001_create_onenew_ai_config';
$dependent='2026_11_18_000002_add_ai_repository_key';
if($argv[1]==='prepare'){
    Illuminate\Support\Facades\Schema::dropIfExists('onenew_ai_config');
    Illuminate\Support\Facades\DB::table('migrations')->whereIn('migration',[$migration,$dependent])->delete();
}else{
    if(!Illuminate\Support\Facades\Schema::hasTable('onenew_ai_config')||!Illuminate\Support\Facades\Schema::hasColumn('onenew_ai_config','encrypted_repo_key')||!Illuminate\Support\Facades\DB::table('migrations')->where('migration',$migration)->exists())throw new RuntimeException('Startup did not install pending AI migration');
}
echo 'users='.Illuminate\Support\Facades\DB::table('v2_user')->count()."\n";
