<?php
namespace App\Onenew\CloudBackup;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Illuminate\Http\Client\ConnectionException;
final class DriveClient
{
    private const API='https://www.googleapis.com/drive/v3';
    private const UPLOAD='https://www.googleapis.com/upload/drive/v3/files';
    private ?string $token=null;
    public function __construct(private array $config){}
    private function access():string
    {
        if($this->token!==null)return $this->token;
        try{$response=Http::asForm()->withoutRedirecting()->connectTimeout(10)->timeout(25)->post('https://oauth2.googleapis.com/token',['client_id'=>$this->config['client_id'],'client_secret'=>$this->config['client_secret'],'refresh_token'=>$this->config['refresh_token'],'grant_type'=>'refresh_token']);}
        catch(ConnectionException){throw new BackupFailure('network_error');}
        if(!$response->successful())throw new BackupFailure($response->status()>=500?'remote_error':'authorization_expired');
        $token=$response->json('access_token');if(!is_string($token)||$token===''||strlen($token)>16384)throw new BackupFailure('protocol_error');return $this->token=$token;
    }
    private function request(string $method,string $url,array $options=[],array $allowed=[200],bool $retry=true)
    {
        $refreshed=false;$tries=$retry?3:1;
        for($attempt=0;$attempt<$tries;$attempt++){
            try{$response=Http::withToken($this->access())->withoutRedirecting()->connectTimeout(10)->timeout(75)->send($method,$url,$options);}
            catch(ConnectionException){if($attempt+1===$tries)throw new BackupFailure('network_error');$this->wait($attempt);continue;}
            if(in_array($response->status(),$allowed,true))return $response;
            if($response->status()===401&&!$refreshed){$this->token=null;$refreshed=true;$attempt--;continue;}
            $reason=$response->json('error.errors.0.reason');
            if(in_array($reason,['storageQuotaExceeded','dailyLimitExceeded'],true))throw new BackupFailure('quota_exceeded');
            if($response->status()===429||$response->status()>=500||in_array($reason,['rateLimitExceeded','userRateLimitExceeded'],true)){
                if($attempt+1===$tries)throw new BackupFailure('remote_error');$this->wait($attempt);continue;
            }
            throw new BackupFailure($response->status()===401?'authorization_expired':($response->status()===403?'permission_denied':'protocol_error'));
        }
        throw new BackupFailure('remote_error');
    }
    private function wait(int $attempt):void{if(!app()->environment('testing'))usleep((1<<min($attempt,3))*1000000);}
    private function id(mixed $id):string{if(!is_string($id)||!preg_match('/^[A-Za-z0-9_-]{1,200}$/D',$id))throw new BackupFailure('protocol_error');return $id;}
    private function metadata(string $id):?array
    {
        $r=$this->request('GET',self::API.'/files/'.$this->id($id).'?'.http_build_query(['supportsAllDrives'=>'true','fields'=>'id,size,md5Checksum,appProperties']),[],[200,404]);
        return $r->status()===404?null:$r->json();
    }
    public function ensureFolder():string
    {
        $folder=$this->config['folder_id']??'';
        if($folder===''){
            $key=$this->config['folder_key']??'';if(!preg_match('/^[a-f0-9]{32}$/D',$key))throw new BackupFailure('not_configured');
            $lock=Cache::store('redis')->lock('onenew:cloud-folder:'.$key,600);
            if(!$lock->get())throw new BackupFailure('busy');
            try{
                $q="trashed = false and mimeType = 'application/vnd.google-apps.folder' and appProperties has { key='onenew_backup_root' and value='$key' }";
                $files=$this->request('GET',self::API.'/files?'.http_build_query(['q'=>$q,'fields'=>'files(id)','pageSize'=>1]))->json('files');
                if(!is_array($files))throw new BackupFailure('protocol_error');
                $folder=$files[0]['id']??$this->request('POST',self::API.'/files?fields=id',['json'=>['name'=>'暖秋自动备份','mimeType'=>'application/vnd.google-apps.folder','appProperties'=>['onenew_backup_root'=>$key]]],[200,201])->json('id');
            }finally{$lock->release();}
        }
        $folder=$this->id($folder);$r=$this->request('GET',self::API.'/files/'.$folder.'?'.http_build_query(['supportsAllDrives'=>'true','fields'=>'id,mimeType,trashed,capabilities(canAddChildren)']),[],[200,404]);
        if($r->status()===404||$r->json('trashed')||$r->json('mimeType')!=='application/vnd.google-apps.folder'||$r->json('capabilities.canAddChildren')!==true)throw new BackupFailure('permission_denied');return $folder;
    }
    public static function validSession(string $url):bool
    {
        if(strlen($url)>8192)return false;$p=parse_url($url);
        return $p&&($p['scheme']??'')==='https'&&in_array(strtolower($p['host']??''),['www.googleapis.com','upload.googleapis.com'],true)&&!isset($p['user'],$p['pass'])&&!isset($p['user'])&&!isset($p['fragment'])&&(!isset($p['port'])||$p['port']===443)&&str_starts_with($p['path']??'','/upload/drive/v3/files');
    }
    public function verify(array $run):array
    {
        $file=$this->metadata($run['file_id']);
        if(!$file||($file['id']??'')!==$run['file_id']||!isset($file['size'],$file['md5Checksum'])||(int)$file['size']!==(int)$run['bytes']||!hash_equals($run['md5'],strtolower((string)$file['md5Checksum']))||($file['appProperties']['onenew_backup_run']??'')!==$run['id'])throw new BackupFailure('integrity_failed');return $file;
    }
    private function offset($response,int $total):int
    {
        $range=$response->header('Range');if(!$range)return 0;
        if(!preg_match('/^bytes=0-([0-9]+)$/D',$range,$m))throw new BackupFailure('protocol_error');$offset=(int)$m[1]+1;
        if($offset>$total)throw new BackupFailure('protocol_error');return $offset;
    }
    private function session(array $run):string
    {
        $existing=$this->metadata($run['file_id']);
        if($existing&&($existing['appProperties']['onenew_backup_run']??'')!==$run['id'])throw new BackupFailure('protocol_error');
        $meta=['name'=>$run['filename'],'appProperties'=>['onenew_backup_run'=>$run['id'],'onenew_sha256'=>$run['sha256'],'onenew_revision'=>$run['revision']]];
        if(!$existing){$meta['id']=$run['file_id'];$meta['parents']=[$this->id($this->config['folder_id'])];}
        $url=self::UPLOAD.($existing?'/'.$run['file_id']:'').'?uploadType=resumable&supportsAllDrives=true';
        $r=$this->request($existing?'PATCH':'POST',$url,['json'=>$meta,'headers'=>['X-Upload-Content-Type'=>'application/octet-stream','X-Upload-Content-Length'=>(string)$run['bytes']]],[200,201]);$uri=$r->header('Location');
        if(!is_string($uri)||!self::validSession($uri))throw new BackupFailure('protocol_error');return $uri;
    }
    public function upload(string $path,array $run,callable $persist):array
    {
        if(empty($run['file_id'])){$run['file_id']=$this->id($this->request('GET',self::API.'/files/generateIds?count=1&space=drive')->json('ids.0'));$persist(['file_id'=>$run['file_id']]);}
        $existing=$this->metadata($run['file_id']);
        if($existing&&(int)($existing['size']??-1)===(int)$run['bytes']&&($existing['md5Checksum']??'')===$run['md5'])return $this->verify($run);
        $uri=$run['session_uri']??'';$offset=0;$total=(int)$run['bytes'];$failures=0;
        if($uri!==''&&!self::validSession($uri))throw new BackupFailure('protocol_error');
        $stream=fopen($path,'rb');if(!$stream)throw new BackupFailure('local_error');
        try{
            while(true){
                if($uri===''){$uri=$this->session($run);$persist(['session_uri'=>$uri,'uploaded_bytes'=>0]);}
                $probe=$this->request('PUT',$uri,['headers'=>['Content-Length'=>'0','Content-Range'=>'bytes */'.$total],'body'=>''],[200,201,308,404,410]);
                if(in_array($probe->status(),[200,201],true))return $this->verify($run);
                if(in_array($probe->status(),[404,410],true)){if(++$failures>3)throw new BackupFailure('remote_error');$uri='';continue;}
                $offset=$this->offset($probe,$total);$persist(['uploaded_bytes'=>$offset]);
                while($offset<$total){
                    if(fseek($stream,$offset)!==0)throw new BackupFailure('local_error');$chunk=fread($stream,min(8388608,$total-$offset));if($chunk===false||$chunk==='')throw new BackupFailure('local_error');
                    $persist([]);
                    try{$r=$this->request('PUT',$uri,['headers'=>['Content-Type'=>'application/octet-stream','Content-Length'=>(string)strlen($chunk),'Content-Range'=>'bytes '.$offset.'-'.($offset+strlen($chunk)-1).'/'.$total],'body'=>$chunk],[200,201,308,400,404,410],false);}
                    catch(BackupFailure $e){if(!in_array($e->reason,['network_error','remote_error'],true)||++$failures>3)throw $e;$this->wait($failures-1);continue 2;}
                    if(in_array($r->status(),[200,201],true)){$persist(['uploaded_bytes'=>$total,'state'=>'verifying']);return $this->verify($run);}
                    if(in_array($r->status(),[400,404,410],true)){if(++$failures>3)throw new BackupFailure('remote_error');if($r->status()!==400)$uri='';continue 2;}
                    $next=$this->offset($r,$total);if($next<=$offset&&++$failures>3)throw new BackupFailure('remote_error');$offset=$next;$persist(['uploaded_bytes'=>$offset]);
                }
                if(++$failures>3)throw new BackupFailure('integrity_failed');
            }
        }finally{fclose($stream);}
    }
}
