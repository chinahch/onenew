<?php
namespace App\Onenew\Migration;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;

final class ExportMigration implements ShouldQueue
{
    use Dispatchable,InteractsWithQueue,Queueable,SerializesModels;
    public $timeout=1800;
    public $tries=1;
    public function __construct(public string $id,protected string $encryptedPassword,protected string $lockOwner)
    { $this->onConnection('onenew_migration')->onQueue('onenew_migration'); }
    public function handle(MigrationService $service): void
    {
        $lock=Cache::store('redis')->lock('onenew:migration:worker:'.$this->id,1800);
        if(!$lock->get())return;
        try{
            $state=$service->status($this->id);
            if(!$state||$state['state']!=='queued'||$state['expires_at']<=time())return;
            require_once '/opt/onenew/migration/core/MigrationBundle.php';
            $stage=$service->snapshot($this->id);$service->phase($this->id,'encrypting');
            $password=Crypt::decryptString($this->encryptedPassword);
            $revision=$service->revision();
            try{MigrationBundle::pack($stage,$service->directory($this->id).'/package.onm',$password,$revision);}
            finally{$password='';}
            $service->removeTree($stage);$state=$service->status($this->id);
            $state['state']='ready';$state['revision']=$revision;$state['bytes']=filesize($service->directory($this->id).'/package.onm');$state['expires_at']=time()+MigrationService::TTL;$service->save($state);
        }catch(\Throwable $error){
            $this->failed($error);
            Log::warning('Migration export failed',['id'=>$this->id,'type'=>get_class($error)]);
        }finally{
            $this->encryptedPassword='';$lock->release();
            Cache::store('redis')->restoreLock(MigrationService::LOCK,$this->lockOwner)->release();
        }
    }
    public function failed(?\Throwable $exception): void
    {
        $service=app(MigrationService::class);$state=$service->status($this->id);
        if($state){$state['state']='failed';$state['error']='迁移包生成失败，请检查磁盘空间、资源文件和后台日志。';$service->save($state);}
        try{$service->removeTree($service->directory($this->id));}catch(\Throwable){}
        Cache::store('redis')->restoreLock(MigrationService::LOCK,$this->lockOwner)->release();
    }
}
