<?php
namespace App\Onenew\Migration;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

final class MigrationController
{
    public function info(MigrationService $service): mixed
    {
        $info=$service->info();$id=Cache::store('redis')->get('onenew:migration:latest:'.Auth::guard('sanctum')->id());
        if($id){$state=$service->status($id);if($state&&$state['owner']===(int)Auth::guard('sanctum')->id()&&$state['expires_at']>time()){unset($state['owner']);$info['latest']=$state;}}
        return response()->json($info)->header('Cache-Control','no-store');
    }
    public function create(Request $request,MigrationService $service): mixed
    {
        $p=$request->validate(['password'=>'required|string|confirmed']);
        abort_unless(strlen($p['password'])>=12&&strlen($p['password'])<=128&&!preg_match('/[\x00-\x1f\x7f]/',$p['password']),422,'迁移密码需要 12–128 字节且不含控制字符');
        abort_unless($service->info()['supported'],422,'当前部署不支持迁移导出');
        $lock=Cache::store('redis')->lock(MigrationService::LOCK,1800);abort_unless($lock->get(),409,'已有迁移包正在生成');
        $id=(string)Str::uuid();
        try{
            $service->prepare($id);
            $state=['id'=>$id,'owner'=>(int)Auth::guard('sanctum')->id(),'state'=>'queued','created_at'=>time(),'expires_at'=>time()+3600,'error'=>'','bytes'=>0,'revision'=>$service->revision()];$service->save($state);
            Cache::store('redis')->put('onenew:migration:latest:'.$state['owner'],$id,MigrationService::TTL);
            ExportMigration::dispatch($id,Crypt::encryptString($p['password']),$lock->owner());
        }catch(\Throwable $error){$lock->release();$service->removeTree($service->directory($id));throw $error;}
        unset($state['owner']);return response()->json($state)->header('Cache-Control','no-store');
    }
    private function owned(MigrationService $service,string $id): array
    {
        abort_unless(Str::isUuid($id),404);$state=$service->status($id);
        abort_unless($state&&$state['owner']===(int)Auth::guard('sanctum')->id(),404);
        if($state['expires_at']<=time()||(!in_array($state['state'],['ready','failed'],true)&&$state['created_at']+1800<=time())){$state['state']='expired';}
        return $state;
    }
    public function status(MigrationService $service,string $id): mixed
    {
        $state=$this->owned($service,$id);unset($state['owner']);
        return response()->json($state)->header('Cache-Control','no-store');
    }
    public function ticket(MigrationService $service,string $id): mixed
    {
        $state=$this->owned($service,$id);abort_unless($state['state']==='ready',409,'迁移包未就绪或已过期');
        $token=bin2hex(random_bytes(32));Cache::store('redis')->put('onenew:migration:ticket:'.hash('sha256',$token),['id'=>$id,'owner'=>$state['owner']],120);
        return response()->json(['ticket'=>$token])->header('Cache-Control','no-store');
    }
    public function download(Request $request,MigrationService $service): mixed
    {
        $token=$request->post('ticket');abort_unless(is_string($token)&&preg_match('/^[a-f0-9]{64}$/D',$token),404);
        $key='onenew:migration:ticket:'.hash('sha256',$token);$cache=Cache::store('redis');$lock=$cache->lock($key.':lock',10);abort_unless($lock->get(),409);
        try{$ticket=$cache->get($key);$cache->forget($key);}finally{$lock->release();}
        abort_unless($ticket,404);$state=$service->status($ticket['id']);
        abort_unless($state&&$state['state']==='ready'&&$state['owner']===$ticket['owner']&&$state['expires_at']>time(),404);
        $path=$service->directory($ticket['id']).'/package.onm';abort_unless(is_file($path)&&!is_link($path),404);
        return response()->download($path,'onenew-migration-'.$ticket['id'].'.onm',['Cache-Control'=>'no-store','X-Content-Type-Options'=>'nosniff']);
    }
}
