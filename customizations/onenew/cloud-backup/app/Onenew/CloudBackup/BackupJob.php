<?php
namespace App\Onenew\CloudBackup;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use App\Onenew\Migration\MigrationService;
final class BackupJob implements ShouldQueue
{
    use Dispatchable,InteractsWithQueue,Queueable,SerializesModels;
    public $timeout=7200;public $tries=1;
    public function __construct(public string $id){$this->onConnection('onenew_cloud_backup')->onQueue('onenew_cloud_backup');}
    public function handle(Service $service,MigrationService $migration):void
    {
        $run=$service->claim($this->id);if(!$run)return;$lease=$run['lease_token'];$exportLock=null;
        try{
            $secret=json_decode(Crypt::decryptString($run['encrypted_config']),true,32,JSON_THROW_ON_ERROR);$path=$migration->directory($this->id).'/package.onm';
            $ready=$run['sha256']&&is_file($path)&&!is_link($path)&&hash_equals($run['sha256'],hash_file('sha256',$path));
            if(!$ready){
                if(!$migration->info()['supported'])throw new BackupFailure('local_error');
                $exportLock=Cache::store('redis')->lock(MigrationService::LOCK,1800);if(!$exportLock->get())throw new BackupFailure('busy');
                if(file_exists($migration->directory($this->id)))$migration->removeTree($migration->directory($this->id));$migration->prepare($this->id);
                $migration->save(['id'=>$this->id,'state'=>'generating','expires_at'=>time()+86400]);
                $stage=$migration->snapshot($this->id);$service->patch($this->id,$lease,[]);
                require_once '/opt/onenew/migration/core/MigrationBundle.php';$revision=$migration->revision();
                \App\Onenew\Migration\MigrationBundle::pack($stage,$path,$secret['password'],$revision);$migration->removeTree($stage);
                $run=array_merge($run,['bytes'=>filesize($path),'sha256'=>hash_file('sha256',$path),'md5'=>hash_file('md5',$path),'revision'=>$revision,'encrypted_session'=>null]);
                $service->patch($this->id,$lease,['bytes'=>$run['bytes'],'sha256'=>$run['sha256'],'md5'=>$run['md5'],'revision'=>$revision,'encrypted_session'=>null,'uploaded_bytes'=>0]);$exportLock->release();$exportLock=null;
            }
            $secret['password']='';$service->patch($this->id,$lease,['state'=>'uploading']);
            $s3=new S3Client($secret);
            $s3->upload($path,$run,fn($data)=>$service->patch($this->id,$lease,$data));$service->finish($this->id,$lease);
        }catch(\Throwable $e){$reason=$e instanceof BackupFailure?$e->reason:'local_error';$service->finish($this->id,$lease,$reason);Log::warning('Cloud backup attempt failed',['id'=>$this->id,'reason'=>$reason]);}
        finally{if($exportLock)$exportLock->release();unset($secret);}
    }
}
