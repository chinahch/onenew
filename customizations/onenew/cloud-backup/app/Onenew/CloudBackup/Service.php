<?php
namespace App\Onenew\CloudBackup;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use App\Onenew\Migration\MigrationService;
final class Service
{
    public const ACTIVE=['queued','generating','uploading','verifying'];
    public function __construct(private Config $config){}
    public function enqueue(string $trigger,?int $slot=null):string
    {
        return DB::transaction(function()use($trigger,$slot){
        $this->config->row();
        $row=(array)DB::table('onenew_cloud_backup_config')->where('id',1)->lockForUpdate()->first();$secret=$this->config->secrets($row);if(!$this->config->ready($secret))throw new BackupFailure('not_configured');
        $identity=$this->config->identity();if($row['deployment_id']!==$identity)throw new BackupFailure('restored_paused');
        if($trigger==='manual'){$existing=DB::table('onenew_cloud_backup_runs')->where('deployment_id',$identity)->whereIn('state',self::ACTIVE)->first();if($existing)return $existing->id;}
        $secret['config_version']=(int)$row['version'];$id=(string)Str::uuid();$record=['id'=>$id,'deployment_id'=>$identity,'trigger'=>$trigger,'scheduled_for'=>$slot,'state'=>'queued','lease_until'=>now()->timestamp+900,'encrypted_config'=>Crypt::encryptString(json_encode($secret,JSON_THROW_ON_ERROR)),'filename'=>'onenew-migration-'.$id.'.onm','created_at'=>now()->timestamp];
        if(!DB::table('onenew_cloud_backup_runs')->insertOrIgnore($record))return DB::table('onenew_cloud_backup_runs')->where('scheduled_for',$slot)->value('id');
        DB::afterCommit(fn()=>BackupJob::dispatch($id));return $id;
        });
    }
    public function tick():void
    {
        if(!Schema::hasTable('onenew_cloud_backup_config'))return;$identity=$this->config->identity();$now=now()->timestamp;
        $stale=DB::table('onenew_cloud_backup_runs')->where('deployment_id',$identity)->whereIn('state',self::ACTIVE)->where(function($q)use($now){$q->where('lease_until','<=',$now)->orWhere('created_at','<=',$now-86400);})->get();
        foreach($stale as $run){
            $query=DB::table('onenew_cloud_backup_runs')->where('id',$run->id)->where('lease_token',$run->lease_token)->where('state',$run->state)->where('lease_until',$run->lease_until);
            if($run->attempts<3&&$run->created_at>$now-86400){if($query->update(['state'=>'queued','lease_token'=>null,'lease_until'=>$now+900,'error_code'=>'timeout']))BackupJob::dispatch($run->id);}
            else if($query->update(['state'=>'failed','lease_token'=>null,'lease_until'=>null,'finished_at'=>$now,'error_code'=>'timeout','encrypted_config'=>null,'encrypted_session'=>null]))$this->cleanup($run->id);
        }
        DB::transaction(function()use($identity,$now){
            $this->config->row();$row=(array)DB::table('onenew_cloud_backup_config')->where('id',1)->lockForUpdate()->first();
            if(!$row['enabled']||$row['deployment_id']!==$identity||!$row['next_run_at']||$row['next_run_at']>$now)return;
            $this->enqueue('scheduled',(int)$row['next_run_at']);
            DB::table('onenew_cloud_backup_config')->where('id',1)->update(['next_run_at'=>Schedule::next($row['daily_time'],now()->toDateTimeImmutable())]);
        });
    }
    public function claim(string $id):?array
    {
        $token=bin2hex(random_bytes(16));$now=now()->timestamp;
        $changed=DB::table('onenew_cloud_backup_runs')->where('id',$id)->where('deployment_id',$this->config->identity())->where('state','queued')->where('attempts','<',3)->where('created_at','>',$now-86400)->update(['state'=>'generating','lease_token'=>$token,'lease_until'=>$now+1800,'attempts'=>DB::raw('attempts + 1'),'error_code'=>null,'started_at'=>DB::raw('COALESCE(started_at, '.$now.')')]);
        return $changed?(array)DB::table('onenew_cloud_backup_runs')->where('id',$id)->first():null;
    }
    public function patch(string $id,string $lease,array $data):void
    {
        if(isset($data['session_uri'])){$data['encrypted_session']=Crypt::encryptString($data['session_uri']);unset($data['session_uri']);}
        $data['lease_until']=now()->timestamp+1800;
        if(!DB::table('onenew_cloud_backup_runs')->where('id',$id)->where('lease_token',$lease)->whereIn('state',self::ACTIVE)->update($data))throw new BackupFailure('timeout');
    }
    public function finish(string $id,string $lease,?string $error=null):void
    {
        $row=DB::table('onenew_cloud_backup_runs')->where('id',$id)->where('lease_token',$lease)->first();if(!$row)return;
        if(in_array($error,['network_error','remote_error','busy'],true)&&$row->attempts<3&&$row->created_at>now()->timestamp-86400){
            if(DB::table('onenew_cloud_backup_runs')->where('id',$id)->where('lease_token',$lease)->update(['state'=>'queued','error_code'=>$error,'lease_token'=>null,'lease_until'=>now()->timestamp+900]))BackupJob::dispatch($id)->delay(now()->addSeconds(60*$row->attempts));return;
        }
        if(DB::table('onenew_cloud_backup_runs')->where('id',$id)->where('lease_token',$lease)->update(['state'=>$error?'failed':'success','error_code'=>$error,'finished_at'=>now()->timestamp,'lease_token'=>null,'lease_until'=>null,'encrypted_config'=>null,'encrypted_session'=>null]))$this->cleanup($id);
    }
    public function cleanup(string $id):void
    {
        try{$migration=app(MigrationService::class);$migration->removeTree($migration->directory($id));Cache::store('redis')->forget('onenew:migration:job:'.$id);}catch(\Throwable){}
    }
    public static function protectsArtifact(string $id):bool
    {
        return Schema::hasTable('onenew_cloud_backup_runs')&&DB::table('onenew_cloud_backup_runs')->where('id',$id)->whereIn('state',self::ACTIVE)->where('created_at','>',now()->timestamp-86400)->exists();
    }
    private function display(?object $run):?array
    {
        if(!$run)return null;$result=[];foreach(['id','trigger','state','created_at','started_at','finished_at','filename','bytes','uploaded_bytes','sha256','revision','error_code','attempts'] as $field)$result[$field]=$run->$field;
        if($run->deployment_id!==$this->config->identity()&&in_array($run->state,self::ACTIVE,true)){$result['state']='paused';$result['error_code']='restored_paused';}
        $result['error']=$result['error_code']?BackupFailure::message($result['error_code']):'';
        $result['object_key']=$run->state==='success'&&is_string($run->file_id)&&$run->file_id!==''?$run->file_id:null;return $result;
    }
    public function view():array
    {
        return ['config'=>$this->config->view(),'latest'=>$this->display(DB::table('onenew_cloud_backup_runs')->orderByDesc('sequence')->first()),'latest_success'=>$this->display(DB::table('onenew_cloud_backup_runs')->where('state','success')->orderByDesc('sequence')->first())];
    }
}
