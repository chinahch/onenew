<?php
namespace App\Onenew\CloudBackup;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Validation\ValidationException;
use App\Onenew\Migration\MigrationService;
final class Config
{
    public function identity():string
    {
        if(app()->environment('testing')&&is_string(config('onenew.backup_identity')))return config('onenew.backup_identity');
        $root=app(MigrationService::class)->source().'/.docker/.data';$path=$root.'/onenew-backup-deployment-id';
        if(is_link($root)||is_link($path)||!is_dir($root))throw new BackupFailure('identity_invalid');
        $f=@fopen($path,'c+');if(!$f)throw new BackupFailure('identity_invalid');
        try{
            if(!flock($f,LOCK_EX))throw new BackupFailure('identity_invalid');$id=stream_get_contents($f,128);
            if($id===''){$id=bin2hex(random_bytes(16));rewind($f);if(fwrite($f,$id)!==32||!fflush($f))throw new BackupFailure('identity_invalid');chmod($path,0600);}
            if(!preg_match('/^[a-f0-9]{32}$/D',$id))throw new BackupFailure('identity_invalid');return $id;
        }finally{flock($f,LOCK_UN);fclose($f);}
    }
    public function row():array
    {
        $row=DB::table('onenew_cloud_backup_config')->where('id',1)->first();
        if(!$row){DB::table('onenew_cloud_backup_config')->insertOrIgnore(['id'=>1]);$row=DB::table('onenew_cloud_backup_config')->where('id',1)->first();}
        return (array)$row;
    }
    public function secrets(?array $row=null):array
    {
        $row??=$this->row();return empty($row['encrypted_config'])?[]:json_decode(Crypt::decryptString($row['encrypted_config']),true,32,JSON_THROW_ON_ERROR);
    }
    public function ready(array $secret):bool{foreach(['endpoint','bucket','access_key_id','secret_access_key','password'] as $k)if(empty($secret[$k]))return false;return true;}
    public function view(?array $row=null):array
    {
        $row??=$this->row();$secret=$this->secrets($row);$paused=!empty($row['deployment_id'])&&!hash_equals($row['deployment_id'],$this->identity());
        return ['endpoint'=>$secret['endpoint']??'','bucket'=>$secret['bucket']??'','access_key_id'=>$secret['access_key_id']??'','prefix'=>$secret['prefix']??'','secret_access_key_configured'=>!empty($secret['secret_access_key']),'password_configured'=>!empty($secret['password']),'ready'=>$this->ready($secret),'enabled'=>(bool)$row['enabled']&&!$paused,'daily_time'=>$row['daily_time'],'timezone'=>'Asia/Shanghai','next_run_at'=>$paused?null:$row['next_run_at'],'restored_paused'=>$paused,'version'=>(int)$row['version']];
    }
    public function save(array $input):array
    {
        return DB::transaction(function()use($input){
            $this->row();$row=(array)DB::table('onenew_cloud_backup_config')->where('id',1)->lockForUpdate()->first();$secret=$this->secrets($row);
            foreach(['endpoint','bucket','access_key_id','secret_access_key','password'] as $k)if(isset($input[$k])&&$input[$k]!=='')$secret[$k]=trim((string)$input[$k]);
            if(array_key_exists('prefix',$input))$secret['prefix']=trim((string)($input['prefix']??'')," \t\n\r\0\x0B/");
            if(!empty($input['password'])&&($input['password_confirmation']??'')!==$input['password'])throw ValidationException::withMessages(['password'=>'两次备份密码不一致。']);
            $identity=$this->identity();$time=$input['daily_time']??$row['daily_time'];$paused=!empty($row['deployment_id'])&&$row['deployment_id']!==$identity;$enabled=(bool)($input['enabled']??($paused?false:$row['enabled']));
            if($enabled&&!$this->ready($secret))throw ValidationException::withMessages(['enabled'=>BackupFailure::message('not_configured')]);
            $next=$enabled?((($row['deployment_id']??'')===$identity&&$row['enabled']&&$time===$row['daily_time']&&$row['next_run_at'])?$row['next_run_at']:Schedule::next($time,now()->toDateTimeImmutable())):null;
            DB::table('onenew_cloud_backup_config')->where('id',1)->update(['encrypted_config'=>Crypt::encryptString(json_encode($secret,JSON_THROW_ON_ERROR)),'daily_time'=>$time,'enabled'=>$enabled,'deployment_id'=>$identity,'next_run_at'=>$next,'version'=>$row['version']+1]);return $this->view();
        });
    }
    public function rememberFolder(string $id,int $version):void
    {
        DB::transaction(function()use($id,$version){$row=(array)DB::table('onenew_cloud_backup_config')->where('id',1)->lockForUpdate()->first();if(!$row||(int)$row['version']!==$version)return;$secret=$this->secrets($row);$secret['folder_id']=$id;DB::table('onenew_cloud_backup_config')->where('id',1)->update(['encrypted_config'=>Crypt::encryptString(json_encode($secret,JSON_THROW_ON_ERROR))]);});
    }
}
