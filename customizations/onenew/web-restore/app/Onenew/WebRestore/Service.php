<?php
namespace App\Onenew\WebRestore;
use App\Onenew\Migration\MigrationService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
require_once '/opt/onenew/web-restore/core/Recovery.php';
final class Service
{
    public const CHUNK=524288;
    public function __construct(private MigrationService $migration){}
    public function source():string{return $this->migration->source();}
    public function directory(string $id):string{return Recovery::dir($this->source(),$id);}
    public function supported():bool{return $this->migration->info()['supported']&&(app()->environment('testing')||(@filemtime('/run/onenew-restore-agent.heartbeat')>time()-30&&in_array(config('database.redis.default.host'),['127.0.0.1','localhost'],true)));}
    public function owned(string $id):array
    {
        abort_unless(Recovery::validId($id),404);$dir=$this->directory($id);abort_unless(is_file($dir.'/status.json'),404);$s=Recovery::json($dir.'/status.json');
        abort_unless($s['owner']===(int)Auth::guard('sanctum')->id(),404);abort_unless($s['expires_at']>time(),410,'恢复任务已过期');return $s;
    }
    public function publicState(array $state):array
    {
        return array_intersect_key($state,array_flip(['id','state','filename','bytes','received','summary','error','created_at','updated_at','expires_at','rollback_available']));
    }
    public function latest():?array
    {
        $root=Recovery::root($this->source());if(!is_dir($root)||is_link($root))return null;$latest=null;
        foreach(glob($root.'/*/status.json')?:[] as $path){try{Recovery::dir($this->source(),basename(dirname($path)));$s=Recovery::json($path);if($s['owner']===(int)Auth::guard('sanctum')->id()&&$s['expires_at']>time()&&(!$latest||$s['created_at']>$latest['created_at']))$latest=$s;}catch(\Throwable){}}
        return $latest?$this->publicState($latest):null;
    }
    public function history():array
    {
        $rows=[];foreach(glob(Recovery::root($this->source()).'/*/status.json')?:[] as $path){try{Recovery::dir($this->source(),basename(dirname($path)));$s=Recovery::json($path);if($s['expires_at']>time()&&!empty($s['rollback_available'])&&in_array($s['state'],['success','failed'],true))$rows[]=$this->publicState($s);}catch(\Throwable){}}
        usort($rows,fn($a,$b)=>$b['created_at']<=>$a['created_at']);return array_slice($rows,0,5);
    }
    public function create(string $filename,int $bytes):array
    {
        abort_unless($this->supported(),422,'当前部署的网页恢复进程未就绪，请升级官方 Docker 部署');
        $root=Recovery::root($this->source());if(is_link($root))abort(422);if(!is_dir($root))mkdir($root,0700,true);
        $live=0;foreach(glob($root.'/*/status.json')?:[] as $path){try{Recovery::dir($this->source(),basename(dirname($path)));$s=Recovery::json($path);if($s['expires_at']>time()&&!in_array($s['state'],['success','failed'],true))$live++;}catch(\Throwable){}}
        abort_if($live>=5,409,'已有多个恢复任务，请先完成或取消');
        $id=(string)Str::uuid();$dir=$this->directory($id);mkdir($dir,0700);$state=['id'=>$id,'owner'=>(int)Auth::guard('sanctum')->id(),'filename'=>basename($filename),'bytes'=>$bytes,'received'=>0,'state'=>'uploading','created_at'=>time(),'updated_at'=>time(),'expires_at'=>time()+86400,'error'=>'','original_admin_path'=>'/'.admin_setting('secure_path',admin_setting('frontend_admin_path',hash('crc32b',config('app.key'))))];
        Recovery::write($dir.'/status.json',json_encode($state,JSON_THROW_ON_ERROR));return $this->publicState($state);
    }
    public function locked(string $id,callable $callback):mixed
    {
        $this->owned($id);$dir=$this->directory($id);$f=fopen($dir.'/lock','c+');chmod($dir.'/lock',0600);
        try{abort_unless(flock($f,LOCK_EX),409);return $callback($dir,$this->owned($id));}finally{flock($f,LOCK_UN);fclose($f);}
    }
    public function chunk(string $id,int $offset,string $data):array
    {
        return $this->locked($id,function($dir,$s)use($offset,$data){
            abort_unless($s['state']==='uploading',409);$length=strlen($data);abort_unless($length>0&&$length<=self::CHUNK&&$offset+$length<=$s['bytes'],422,'分片大小不正确');
            $path=$dir.'/package.onm';abort_if(is_link($path),422);$f=fopen($path,'c+b');chmod($path,0600);
            try{fseek($f,0,SEEK_END);$actual=ftell($f);
                if($offset<$actual){fseek($f,$offset);abort_unless($offset+$length<=$actual&&hash_equals(hash('sha256',$data),hash('sha256',fread($f,$length))),409,'分片冲突，请重新上传');}
                else{abort_unless($offset===$actual,409,'分片顺序不正确');abort_unless(disk_free_space($dir)>67108864+$length,422,'服务器磁盘空间不足');abort_unless(fwrite($f,$data)===$length&&fflush($f),500);$actual+=$length;}
            }finally{fclose($f);}
            return $this->publicState(Recovery::state($dir,['received'=>$actual]));
        });
    }
    public function request(string $dir,array $state,string $action):void
    {
        $body=json_encode(['id'=>$state['id'],'action'=>$action,'expires_at'=>time()+1800,'package_sha256'=>hash_file('sha256',$dir.'/package.onm'),'credential_sha256'=>hash_file('sha256',$dir.'/password.enc'),'nonce'=>bin2hex(random_bytes(16))],JSON_THROW_ON_ERROR);
        Recovery::write($dir.'/request.json',json_encode(['body'=>$body,'mac'=>hash_hmac('sha256',$body,(string)config('app.key'))],JSON_THROW_ON_ERROR));
    }
    public function verify(string $id,string $password):array
    {
        return $this->locked($id,function($dir,$s)use($password){abort_unless($s['state']==='uploading'&&$s['received']===$s['bytes'],409,'文件尚未上传完整');
            Recovery::write($dir.'/password.enc',Crypt::encryptString($password));$s=Recovery::state($dir,['state'=>'verifying','error'=>'']);$this->request($dir,$s,'verify');return $this->publicState($s);
        });
    }
    public function confirm(string $id):array
    {
        return $this->locked($id,function($dir,$s){abort_unless($s['state']==='ready',409,'请先完成验证');
            abort_unless(hash_equals($s['package_sha256'],hash_file('sha256',$dir.'/package.onm')),409,'备份文件已变更，请重新上传');
            $token=bin2hex(random_bytes(32));$s=Recovery::state($dir,['state'=>'queued','progress_token_hash'=>hash('sha256',$token)]);$this->request($dir,$s,'apply');return ['id'=>$s['id'],'progress_token'=>$token,'login_path'=>$s['summary']['admin_path']??'/'];
        });
    }
    public function cancel(string $id):void
    {
        $this->locked($id,function($dir,$s){abort_unless(in_array($s['state'],['uploading','ready','failed'],true),409,'正在执行的恢复不可取消');Recovery::clear($dir);});
    }
}
