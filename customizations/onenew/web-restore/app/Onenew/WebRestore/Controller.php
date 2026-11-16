<?php
namespace App\Onenew\WebRestore;
use Illuminate\Http\Request;
final class Controller
{
    public function info(Service $s){return response()->json(['supported'=>$s->supported(),'latest'=>$s->latest(),'history'=>$s->history(),'chunk_bytes'=>Service::CHUNK,'max_bytes'=>2147483648])->header('Cache-Control','no-store');}
    public function create(Request $r,Service $s){$v=$r->validate(['filename'=>'required|string|max:200','bytes'=>'required|integer|min:16|max:2147483648']);abort_unless(str_ends_with(strtolower($v['filename']),'.onm'),422,'请选择 .onm 备份文件');return response()->json($s->create($v['filename'],$v['bytes']));}
    public function status(Service $s,string $id){return response()->json($s->publicState($s->owned($id)))->header('Cache-Control','no-store');}
    public function chunk(Request $r,Service $s,string $id){$v=$r->validate(['offset'=>'required|integer|min:0','chunk'=>'required|file|max:512']);return response()->json($s->chunk($id,$v['offset'],file_get_contents($r->file('chunk')->getRealPath())));}
    public function verify(Request $r,Service $s,string $id){$v=$r->validate(['password'=>'required|string']);abort_unless(strlen($v['password'])>=12&&strlen($v['password'])<=128&&!preg_match('/[\x00-\x1f\x7f]/',$v['password']),422,'备份密码需12–128字节');return response()->json($s->verify($id,$v['password']));}
    public function confirm(Request $r,Service $s,string $id){$r->validate(['confirmed'=>'required|accepted']);return response()->json($s->confirm($id));}
    public function cancel(Service $s,string $id){$s->cancel($id);return response()->json(['ok'=>true]);}
    public function rollbackTicket(Service $s,string $id){abort_unless(Recovery::validId($id),404);
        $dir=$s->directory($id);$state=Recovery::json($dir.'/status.json');abort_unless($state['expires_at']>time()&&in_array($state['state'],['success','failed'],true)&&!empty($state['rollback_available'])&&is_file($dir.'/rollback.onm')&&!is_link($dir.'/rollback.onm'),404);
        $token=bin2hex(random_bytes(32));\Illuminate\Support\Facades\Cache::store('redis')->put('onenew:restore:download:'.hash('sha256',$token),$id,120);return response()->json(['ticket'=>$token])->header('Cache-Control','no-store');
    }
    public function download(Request $r,Service $s){
        $v=$r->validate(['ticket'=>'required|string|size:64']);$key='onenew:restore:download:'.hash('sha256',$v['ticket']);$cache=\Illuminate\Support\Facades\Cache::store('redis');$lock=$cache->lock($key.':lock',10);abort_unless($lock->get(),409);try{$id=$cache->pull($key);}finally{$lock->release();}abort_unless($id,404);
        $dir=$s->directory($id);$state=Recovery::json($dir.'/status.json');abort_unless($state['expires_at']>time()&&is_file($dir.'/rollback.onm')&&!is_link($dir.'/rollback.onm'),404);return response()->download($dir.'/rollback.onm','onenew-rollback-'.$id.'.onm',['Cache-Control'=>'no-store','X-Content-Type-Options'=>'nosniff']);
    }
    public function progress(Request $r,Service $s){$v=$r->validate(['id'=>'required|uuid','token'=>'required|string|size:64']);$dir=$s->directory($v['id']);abort_unless(is_file($dir.'/status.json'),404);$state=Recovery::json($dir.'/status.json');abort_unless($state['expires_at']>time()&&isset($state['progress_token_hash'])&&hash_equals($state['progress_token_hash'],hash('sha256',$v['token'])),404);return response()->json(['state'=>$state['state'],'error'=>$state['error']??'','login_path'=>$state['summary']['admin_path']??'/'])->header('Cache-Control','no-store');}
}
