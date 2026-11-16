<?php
// Invoked only as www by the fixed coordinator; no password or package code in argv.
require __DIR__.'/core/Recovery.php';
require __DIR__.'/../migration/core/MigrationBundle.php';
use App\Onenew\WebRestore\Recovery;
use App\Onenew\Migration\MigrationBundle;
$mode=$argv[1]??'';$id=$argv[2]??'';$source='/www';
if(!in_array($mode,['verify','apply','finalize','rollback','prune','claim','clean-request','reject'],true))exit(2);
if($mode==='prune'){
    foreach(glob(Recovery::root($source).'/*/status.json')?:[] as $path){try{$s=Recovery::json($path);if($s['expires_at']<time()&&!is_file(dirname($path).'/switching'))Recovery::clear(dirname($path));}catch(Throwable){}}
    exit(0);
}
try{$dir=Recovery::dir($source,$id);$state=Recovery::json($dir.'/status.json');}catch(Throwable){exit(2);}
function clearCompiled():void{foreach(['/www/bootstrap/cache/config.php','/www/bootstrap/cache/routes-v7.php','/www/bootstrap/cache/events.php'] as $file){if(is_link($file))throw new RuntimeException('Linked cache');if(is_file($file)&&!unlink($file))throw new RuntimeException('Cannot clear compiled cache');}}
try{
    if($mode==='claim'){
        $f=fopen($dir.'/lock','c+');if(!flock($f,LOCK_EX))exit(1);
        try{$request=Recovery::json($dir.'/request.json');$key=Recovery::key(file_get_contents($source.'/.env'));
            if(!is_string($request['body']??null)||!hash_equals(hash_hmac('sha256',$request['body'],$key),$request['mac']??''))exit(1);
            $body=json_decode($request['body'],true,16,JSON_THROW_ON_ERROR);$action=$body['action']??'';
            if($body['id']!==$id||$body['expires_at']<time()||!in_array($action,['verify','apply'],true)||($action==='verify'&&$state['state']!=='verifying')||($action==='apply'&&$state['state']!=='queued'))exit(1);
            foreach(['package.onm'=>'package_sha256','password.enc'=>'credential_sha256'] as $file=>$field)if(is_link($dir.'/'.$file)||!is_file($dir.'/'.$file)||!hash_equals($body[$field]??'',hash_file('sha256',$dir.'/'.$file)))exit(1);
            exit(rename($dir.'/request.json',$dir.'/processing.json')?0:1);
        }finally{flock($f,LOCK_UN);fclose($f);}
    }elseif($mode==='clean-request'){
        foreach(['request.json','processing.json'] as $file)if(is_file($dir.'/'.$file)||is_link($dir.'/'.$file))unlink($dir.'/'.$file);exit(0);
    }elseif($mode==='reject'){
        Recovery::state($dir,['state'=>'failed','error'=>'恢复请求无法执行，现有数据未被替换。']);exit(0);
    }elseif($mode==='rollback'){
        if(is_dir($dir.'/stage'))Recovery::clear($dir.'/stage');
        if(is_file($dir.'/switching'))Recovery::rollback($source,$dir);clearCompiled();
        Recovery::state($dir,['state'=>'failed','error'=>'恢复未完成，已回滚到恢复前的数据。','rollback_available'=>is_file($dir.'/rollback.onm')]);
    }else{
        require '/www/vendor/autoload.php';$app=require '/www/bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
        $migration=app(App\Onenew\Migration\MigrationService::class);
        if($mode==='finalize'){
            foreach(['default','cache'] as $connection){if(config('database.redis.'.$connection)&&!in_array(config('database.redis.'.$connection.'.host'),['127.0.0.1','localhost'],true))throw new RuntimeException('External shared Redis is not supported');}
            if(Illuminate\Support\Facades\Artisan::call('migrate',['--force'=>true])!==0)throw new RuntimeException('Migration failed');
            if(Illuminate\Support\Facades\Artisan::call('onenew:migration-assets')!==0)throw new RuntimeException('Asset publishing failed');
            foreach(['default','cache'] as $connection)if(config('database.redis.'.$connection))Illuminate\Support\Facades\Redis::connection($connection)->flushdb();
            Recovery::state($dir,['state'=>'success','error'=>'','rollback_available'=>true]);@unlink($dir.'/switching');
        }else{
            $password=Illuminate\Support\Facades\Crypt::decryptString(file_get_contents($dir.'/password.enc'));
            $stage=$dir.'/stage';if(is_dir($stage))Recovery::clear($stage);
            $bundle=MigrationBundle::unpack($dir.'/package.onm',$password,$stage);
            $migrator=app('migrator');$known=array_keys($migrator->getMigrationFiles(array_merge($migrator->paths(),[database_path('migrations')])));
            $checked=Recovery::validateStage($stage,$known);
            $key=Recovery::key(file_get_contents($stage.'/.env'));$db=new PDO('sqlite:'.$checked['database']);
            $adminPath=null;foreach(['secure_path','frontend_admin_path'] as $name){$q=$db->prepare('SELECT value FROM v2_settings WHERE name=? LIMIT 1');$q->execute([$name]);$raw=$q->fetchColumn();if($raw!==false){$decoded=json_decode($raw,true);$candidate=is_string($decoded)?$decoded:$raw;if(preg_match('/^[A-Za-z0-9_-]{1,128}$/D',$candidate)){$adminPath=$candidate;break;}}}
            $db=null;$summary=['revision'=>$bundle['revision'],'counts'=>$checked['counts'],'files'=>$bundle['files'],'bytes'=>$bundle['bytes'],'admin_path'=>'/'.($adminPath??hash('crc32b',$key))];
            if($mode==='verify'){
                Recovery::state($dir,['state'=>'ready','summary'=>$summary,'package_sha256'=>hash_file('sha256',$dir.'/package.onm'),'error'=>'']);
            }else{
                if(!hash_equals($state['package_sha256'],hash_file('sha256',$dir.'/package.onm')))throw new RuntimeException('Package changed');
                [, $relative]=$migration->database();
                Recovery::state($dir,['state'=>'backing_up']);
                Recovery::snapshot($source,$relative,$dir,$password,$migration->revision());
                Recovery::state($dir,['state'=>'applying','rollback_available'=>true]);
                foreach(array_keys(config('database.connections')) as $connection)Illuminate\Support\Facades\DB::purge($connection);
                Recovery::apply($source,$relative,$dir,$stage,$checked['database']);clearCompiled();
            }
            unset($password);
        }
    }
    if(in_array($mode,['verify','finalize','rollback'],true)){
        if(is_dir($dir.'/stage'))Recovery::clear($dir.'/stage');
        if(in_array($mode,['finalize','rollback'],true))foreach(['password.enc','package.onm'] as $name)if(is_file($dir.'/'.$name))unlink($dir.'/'.$name);
        if(in_array($mode,['finalize','rollback'],true)&&is_dir($dir.'/rollback'))Recovery::clear($dir.'/rollback');
    }
    exit(0);
}catch(Throwable $e){
    if($mode==='verify'){
        try{if(is_dir($dir.'/stage'))Recovery::clear($dir.'/stage');@unlink($dir.'/password.enc');Recovery::state($dir,['state'=>'failed','error'=>'验证失败：密码错误、文件损坏或备份结构不兼容。']);}catch(Throwable){}
    }
    // Never include exception bodies, credentials or uploaded environment values in logs.
    fwrite(STDERR,'Web restore task failed: '.$mode."\n");exit(1);
}
