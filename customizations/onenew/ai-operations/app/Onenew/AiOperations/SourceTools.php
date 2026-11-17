<?php
namespace App\Onenew\AiOperations;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/** Source is untrusted data. No filesystem mutation or executable commands are available. */
final class SourceTools
{
    private const API='https://api.github.com/repos/chinahch/onenew';
    private array $roots;
    public function __construct(private RepoSettings $settings, ?array $roots=null) {$this->roots=$roots??['repository'=>'/opt/onenew','runtime'=>'/www'];}
    public function catalog():array {return array_map(fn($name)=>['tool'=>$name,'args'=>['source'=>'repository|runtime','path'=>'relative allowlisted source path','query'=>'literal search text (search only)','start_line'=>'1-based (read only)','limit'=>'max 100'] ,'mapping'=>'repository customizations/onenew overlays map to runtime /www/app; runtime is read-only'],['source.list','source.read','source.search']);}
    private function allowed(string $path,bool $edit=false):bool {
        if(strlen($path)>240||preg_match('~(^/|\\\\|[\x00-\x1f:]|(^|/)\.{1,2}(/|$)|//)~',$path))return false;
        if(preg_match('~(^|/)(\.[^/]*|vendor|node_modules|storage|database|bootstrap|cache|certs?|certificates|private|secrets?|fixtures|bin|install[^/]*|update[^/]*)(/|$)~i',$path))return false;
        if(!preg_match('~\.(php|mjs|json|css|vue|ts|md)$~i',$path)||preg_match('~(lock|composer\.json|package-lock\.json)$~i',$path))return false;
        return $edit ? (bool)preg_match('~^customizations/onenew/[^/]+/(app/|backend/app/|src/|tests/|backend/tests/)~',$path) : (bool)preg_match('~^(customizations/onenew/|app/|tests/)~',$path);
    }
    private function revision():string {$file=$this->roots['repository'].'/REVISION';$rev=is_file($file)&&!is_link($file)?trim(file_get_contents($file)):'';abort_unless(preg_match('/^[a-f0-9]{40}$/',$rev),422,'运行版本缺少固定源码 revision。');return $rev;}
    private function local(string $source,string $path):string {
        abort_unless(isset($this->roots[$source])&&$this->allowed($path),422,'源码路径不允许。');
        $root=realpath($this->roots[$source]);abort_unless($root!==false,422,'源码目录不可用。');$at=$root;
        foreach(explode('/',$path) as $part){$at.=DIRECTORY_SEPARATOR.$part;abort_if(is_link($at),422,'不允许符号链接。');}
        $real=realpath($at);abort_unless($real&&str_starts_with($real,$root.DIRECTORY_SEPARATOR)&&is_file($real)&&filesize($real)<=100000,422,'源码文件不可用或过大。');
        return $this->text(file_get_contents($real));
    }
    private function text(string $text):string {abort_if(strlen($text)>100000||str_contains($text,"\0")||!preg_match('//u',$text),422,'仅允许有界 UTF-8 文本。');return $text;}
    public function read(string $tool,array $args):array {
        abort_unless(in_array($tool,['source.list','source.read','source.search'],true),422,'未知源码工具。');$source=$args['source']??'repository';abort_unless(isset($this->roots[$source]),422,'未知源码来源。');$rev=$this->revision();
        if($tool==='source.read'){$path=$args['path']??'';abort_unless(is_string($path),422,'无效路径。');$text=$this->local($source,$path);$start=max(1,min(100000,(int)($args['start_line']??1)));$limit=max(1,min(100,(int)($args['limit']??100)));return ['source'=>$source,'path'=>$path,'revision'=>$rev,'sha'=>hash('sha256',$text),'start_line'=>$start,'content'=>implode("\n",array_slice(explode("\n",$text),$start-1,$limit)),'read_only'=>$source==='runtime'];}
        $query=$args['query']??'';abort_unless(is_string($query)&&strlen($query)<=200&&($tool!=='source.search'||$query!==''),422,'无效搜索词。');$prefix=$args['path']??'';abort_unless(is_string($prefix)&&!preg_match('~[\\\\\x00-\x1f:]|(^|/)\.~',$prefix),422,'无效目录。');$root=$this->roots[$source];abort_unless(is_dir($root),422,'源码目录不可用。');$items=[];$scanned=0;$bytes=0;
        $it=new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root,\FilesystemIterator::SKIP_DOTS));
        foreach($it as $file){if(++$scanned>10000)break;$path=str_replace('\\','/',substr($file->getPathname(),strlen(rtrim($root,'/\\'))+1));if(!$this->allowed($path)||!str_starts_with($path,$prefix))continue;try{$text=$this->local($source,$path);}catch(\Throwable $e){continue;}
            if($tool==='source.list')$items[]=['path'=>$path,'sha'=>hash('sha256',$text)];else foreach(explode("\n",$text) as $line=>$value){if(str_contains($value,$query)){$value=mb_substr($value,0,300);$items[]=['path'=>$path,'line'=>$line+1,'text'=>$value];$bytes+=strlen($value);if(count($items)>=100||$bytes>=30000)break;}}
            if(count($items)>=100||$bytes>=30000)break;
        }return ['source'=>$source,'revision'=>$rev,'items'=>$items,'bounded'=>true];
    }
    private function api(string $method,string $path,array $data=[],string $token='',bool $missing=false):?array {
        try {$http=Http::acceptJson()->withHeaders(['X-GitHub-Api-Version'=>'2022-11-28'])->connectTimeout(5)->timeout(20)->withoutRedirecting();if($token!=='')$http=$http->withToken($token);$r=$method==='GET'?$http->get(self::API.$path,$data):$http->send($method,self::API.$path,['json'=>$data]);if($missing&&$r->status()===404)return null;abort_unless($r->successful()&&strlen($r->body())<=1500000,502,'仓库请求失败，请稍后重试。');$json=$r->json();abort_unless(is_array($json),502,'仓库响应无效。');return $json;}catch(\Illuminate\Http\Client\ConnectionException $e){abort(502,'仓库连接失败，请稍后重试。');}
    }
    private function key(int $admin,string $id):string {return 'onenew:ai:patch:'.$admin.':'.$id;}
    public function propose(int $admin,array $patches):array {
        abort_unless(count($patches)>0&&count($patches)<=5,422,'一次允许 1 至 5 个文件。');$rev=$this->revision();$out=[];$seen=[];$size=0;
        foreach($patches as $patch){$path=$patch['path']??'';$old=$patch['old_text']??null;$new=$patch['new_text']??null;abort_unless(is_string($path)&&$this->allowed($path,true)&&!isset($seen[$path])&&is_string($old)&&is_string($new)&&$old!==''&&$old!==$new,422,'代码修改无效。');$seen[$path]=true;$this->text($old);$this->text($new);$size+=strlen($old)+strlen($new);abort_if($size>100000||preg_match('/(github_pat_|gh[pousr]_|sk-[A-Za-z0-9]{16}|BEGIN .*PRIVATE KEY|(?:password|api_key|secret|token)\s*[=:]\s*[\x22\x27][^\x22\x27]{8,})/i',$new),422,'修改过大或包含疑似密钥。');
            $base=$this->api('GET','/contents/'.$path,['ref'=>$rev]);abort_unless(($base['encoding']??'')==='base64'&&($base['size']??100001)<=100000,422,'基准源码不可用。');$before=base64_decode($base['content']??'',true);abort_unless(is_string($before),422,'基准源码不可用。');$this->text($before);abort_unless(substr_count($before,$old)===1,409,'原文必须在固定版本中唯一匹配。');$after=str_replace($old,$new,$before);$this->text($after);$out[]=['path'=>$path,'before'=>$before,'after'=>$after,'diff'=>'--- a/'.$path."\n+++ b/".$path."\n-".$old."\n+".$new];
        }
        abort_if(strlen(json_encode($out))>100000,422,'修改预览过大。');$id=(string)Str::uuid();$p=['id'=>$id,'base_revision'=>$rev,'patches'=>$out,'diff_sha'=>hash('sha256',json_encode($out)),'expires_at'=>now()->addMinutes(10)->toIso8601String()];Cache::put($this->key($admin,$id),Crypt::encryptString(json_encode($p)),600);return $p;
    }
    public function publish(int $admin,string $id,string $requestId):array {
        abort_unless(Str::isUuid($id)&&Str::isUuid($requestId),422,'无效确认请求。');$key=$this->key($admin,$id);$lock=Cache::lock($key.':lock',180);abort_unless($lock->get(),409,'此代码修改正在提交。');
        try {$value=Cache::get($key);abort_unless(is_string($value),404,'代码预览已过期。');$p=json_decode(Crypt::decryptString($value),true);abort_unless(is_array($p),404,'代码预览不可用。');if(isset($p['result']))return $p['result'];abort_if(isset($p['request_id'])&&$p['request_id']!==$requestId,409,'请使用原确认请求重试。');$token=$this->settings->token();abort_unless($token!=='',422,'请先配置独立仓库密钥。');$p['request_id']=$requestId;Cache::put($key,Crypt::encryptString(json_encode($p)),86400);$branch='ai-admin/'.substr(hash('sha256',$admin.':'.$id.':'.$p['diff_sha']),0,32);
            // Each stage can be inspected and safely resumed after a timeout.
            $ref=$this->api('GET','/git/ref/heads/'.$branch,[],$token,true);
            if($ref!==null){abort_unless(isset($p['commit_sha'])&&hash_equals($p['commit_sha'],$ref['object']['sha']??''),409,'仓库分支内容已变化，请重新预览。');}
            else {if(!isset($p['commit_sha'])){$base=$this->api('GET','/git/commits/'.$p['base_revision'],[],$token);$tree=[];foreach($p['patches'] as $patch)$tree[]=['path'=>$patch['path'],'mode'=>'100644','type'=>'blob','content'=>$patch['after']];$created=$this->api('POST','/git/trees',['base_tree'=>$base['tree']['sha'],'tree'=>$tree],$token);$commit=$this->api('POST','/git/commits',['message'=>'AI administrator proposed change '.$id,'tree'=>$created['sha'],'parents'=>[$p['base_revision']]],$token);abort_unless(preg_match('/^[a-f0-9]{40}$/',$commit['sha']??''),502,'仓库响应无效。');$p['commit_sha']=$commit['sha'];Cache::put($key,Crypt::encryptString(json_encode($p)),86400);}$this->api('POST','/git/refs',['ref'=>'refs/heads/'.$branch,'sha'=>$p['commit_sha']],$token);}
            $prs=$this->api('GET','/pulls',['head'=>'chinahch:'.$branch,'base'=>'master','state'=>'all'],$token);$pr=$prs[0]??null;if(!$pr)$pr=$this->api('POST','/pulls',['title'=>'AI administrator proposed change','head'=>$branch,'base'=>'master','body'=>'Administrator confirmed immutable patch '.$p['diff_sha'].'. CI and human review required. No deployment or merge was performed.'],$token);
            $number=(int)($pr['number']??0);abort_unless($number>0,502,'仓库响应无效。');$p['result']=['pull_request_url'=>'https://github.com/chinahch/onenew/pull/'.$number,'checks_url'=>'https://github.com/chinahch/onenew/pull/'.$number.'/checks','branch'=>$branch,'status'=>'review_required'];Cache::put($key,Crypt::encryptString(json_encode($p)),86400);return $p['result'];
        }finally{$lock->release();}
    }
}
