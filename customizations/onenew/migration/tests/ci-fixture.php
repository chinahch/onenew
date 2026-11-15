<?php
if(getenv('ONENEW_CI_FIXTURE')!=='isolated-only')throw new RuntimeException('Disposable CI fixture only');
require '/www/vendor/autoload.php';
$app=require '/www/bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if(($argv[1]??'')==='seed'){
    if(App\Models\ServerMachine::where('name','migration-ci-machine')->exists())throw new RuntimeException('Fixture must run once');
    App\Models\ServerMachine::create(['name'=>'migration-ci-machine','token'=>bin2hex(random_bytes(16)),'is_active'=>true]);
}
$user=App\Models\User::where('email','ci-onenew@example.test')->firstOrFail();
$machine=App\Models\ServerMachine::where('name','migration-ci-machine')->firstOrFail();
$plugins=App\Models\Plugin::orderBy('code')->get()->map(fn($plugin)=>[$plugin->code,(bool)$plugin->is_enabled,$plugin->getRawOriginal('config')])->all();
echo hash('sha256',json_encode([config('app.key'),$user->uuid,$user->token,$machine->id,$machine->token,$plugins],JSON_THROW_ON_ERROR))."\n";
