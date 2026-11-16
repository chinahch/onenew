<?php
// Root coordinator has no network interface and never loads Laravel or package code.
require __DIR__.'/core/Recovery.php';
use App\Onenew\WebRestore\Recovery;
$source='/www';$heartbeat='/run/onenew-restore-agent.heartbeat';
function runFixed(array $command,int $timeout=1800,?string &$output=null):int{
    $p=proc_open($command,[0=>['file','/dev/null','r'],1=>['pipe','w'],2=>['file','/dev/null','w']],$pipes);if(!is_resource($p))return 1;$start=time();$exit=1;$output='';stream_set_blocking($pipes[1],false);
    while(true){@touch('/run/onenew-restore-agent.heartbeat');$output.=stream_get_contents($pipes[1],max(1,16384-strlen($output)));$s=proc_get_status($p);if(!$s['running']){$exit=$s['exitcode'];break;}if(time()-$start>$timeout){proc_terminate($p,9);break;}usleep(250000);}
    $output.=stream_get_contents($pipes[1],max(1,16384-strlen($output)));fclose($pipes[1]);proc_close($p);return $exit;
}
function task(string $mode,string $id=''):int{return runFixed(['/sbin/su-exec','www','php',__DIR__.'/task.php',$mode,$id]);}
function supervisor(string $verb,string $group,?string &$output=null):int{return runFixed(['supervisorctl','-c','/etc/supervisor/conf.d/supervisord.conf',$verb,$group.':*'],120,$output);}
function clearRequest(string $dir):void{task('clean-request',basename($dir));}
function stopWriters(array $groups):void{
 foreach($groups as $group){supervisor('stop',$group);supervisor('status',$group,$status);if(!preg_match('/\b(STOPPED|EXITED|FATAL)\b/',$status)||preg_match('/\b(RUNNING|STARTING|STOPPING|BACKOFF)\b/',$status))throw new RuntimeException('Cannot stop writers');}
}
function healthy():bool{
 $context=stream_context_create(['http'=>['timeout'=>2,'ignore_errors'=>true,'header'=>"Accept: application/json\r\n"]]);
 for($i=0;$i<30;$i++){@touch('/run/onenew-restore-agent.heartbeat');$http_response_header=[];@file_get_contents('http://127.0.0.1:7001/api/onenew/admin/restore',false,$context);if(preg_match('~^HTTP/\S+ 403\b~',$http_response_header[0]??''))return true;sleep(2);}return false;
}

function resetOfflineRedis():void{
    // Only the bundled container's fixed cache files. No uploaded paths are used.
    foreach(['/data/dump.rdb','/data/appendonly.aof'] as $file){if(is_link($file))throw new RuntimeException('Linked Redis persistence');if(is_file($file)&&!unlink($file))throw new RuntimeException('Cannot reset Redis persistence');}
    $dir='/data/appendonlydir';if(is_link($dir))throw new RuntimeException('Linked Redis persistence');
    if(is_dir($dir)){foreach(new DirectoryIterator($dir) as $entry){if($entry->isDot())continue;if(!$entry->isFile()||$entry->isLink()||!str_starts_with($entry->getFilename(),'appendonly.aof'))throw new RuntimeException('Unexpected Redis persistence');if(!unlink($entry->getPathname()))throw new RuntimeException('Cannot reset Redis persistence');}rmdir($dir);}
}
$recoverOnly=($argv[1]??'')==='--recover';
if(!$recoverOnly){$lock=fopen('/run/onenew-restore-agent.lock','c+');if(!$lock||!flock($lock,LOCK_EX|LOCK_NB))exit(1);}
$groups=['onenew-web','onenew-queue','onenew-ws','onenew-scheduler'];
$resumeFile='/run/onenew-restore-resume.json';$storedResume=[];if(is_file($resumeFile)&&!is_link($resumeFile)&&filesize($resumeFile)<4096){$saved=json_decode(file_get_contents($resumeFile),true);if(is_array($saved))$storedResume=array_values(array_intersect($groups,$saved));}
// A container interruption during a switch always restores the pre-change snapshot before web startup.
$pending=array_unique(array_merge(glob(Recovery::root($source).'/*/switching')?:[],glob(Recovery::root($source).'/*/cache-reset-required')?:[]));
$recovered=[];foreach($pending as $flag){$id=basename(dirname($flag));if(!Recovery::validId($id)||isset($recovered[$id]))continue;$recovered[$id]=true;
    $resume=$storedResume;
    if(!$recoverOnly){foreach($groups as $group){supervisor('status',$group,$before);if(preg_match('/\b(RUNNING|STARTING|BACKOFF)\b/',$before))$resume[]=$group;}$resume=array_values(array_unique($resume));try{stopWriters($groups);}catch(Throwable){fwrite(STDERR,"Cannot stop writers for startup rollback.\n");exit(1);}}
    if(task($recoverOnly?'rollback-offline':'rollback',$id)!==0){fwrite(STDERR,"Web restore startup rollback failed; startup stopped.\n");exit(1);}if($recoverOnly){try{resetOfflineRedis();task('cache-reset-complete',$id);}catch(Throwable){fwrite(STDERR,"Cannot reset bundled Redis after rollback.\n");exit(1);}}clearRequest(dirname($flag));if(!$recoverOnly)foreach(array_reverse($resume) as $group)supervisor('start',$group);
}
foreach(glob(Recovery::root($source).'/*/processing.json')?:[] as $pendingFile){$id=basename(dirname($pendingFile));if(Recovery::validId($id)&&!isset($recovered[$id])){task('reject',$id);clearRequest(dirname($pendingFile));}}
if(!$recoverOnly)foreach(array_reverse($storedResume) as $group)supervisor('start',$group);@unlink($resumeFile);
if($recoverOnly)exit(0);
$lastPrune=0;
while(true){
    @touch($heartbeat);
    foreach(glob(Recovery::root($source).'/*/request.json')?:[] as $path){
        $dir=dirname($path);$id=basename($dir);$stopped=false;$restart=[];
        try{
            if(!Recovery::validId($id)||Recovery::dir($source,$id)!==$dir)continue;
            $request=Recovery::json($path);$key=Recovery::key(file_get_contents($source.'/.env'));
            if(!is_string($request['body']??null)||!hash_equals(hash_hmac('sha256',$request['body'],$key),$request['mac']??''))throw new RuntimeException('Invalid signature');
            $body=json_decode($request['body'],true,16,JSON_THROW_ON_ERROR);
            if(($body['id']??'')!==$id||!in_array($body['action']??'',['verify','apply'],true)||($body['expires_at']??0)<time())throw new RuntimeException('Expired request');
            if(task('claim',$id)!==0)throw new RuntimeException('Invalid input');
            if($body['action']==='verify'){if(task('verify',$id)!==0)task('reject',$id);clearRequest($dir);continue;}
            // Stop writers through a root-only local Supervisor socket. No Docker socket or arbitrary commands.
            $stopped=true;foreach($groups as $group){supervisor('status',$group,$before);if(preg_match('/\b(RUNNING|STARTING|BACKOFF)\b/',$before))$restart[]=$group;}
            file_put_contents($resumeFile,json_encode($restart),LOCK_EX);chmod($resumeFile,0600);
            stopWriters($groups);
            if(task('apply',$id)!==0||task('finalize',$id)!==0)throw new RuntimeException('Apply failed');
            foreach(array_reverse($restart) as $group)supervisor('start',$group);$stopped=false;
            if(!healthy()){stopWriters($groups);$stopped=true;throw new RuntimeException('Restored application unhealthy');}
            if(task('complete',$id)!==0){stopWriters($groups);$stopped=true;throw new RuntimeException('Cannot commit restore');}
        }catch(Throwable){
            if(is_file($dir.'/switching')){
                if(task('rollback',$id)!==0){fwrite(STDERR,"Web restore rollback failed; coordinator will retry with writers stopped.\n");exit(1);}
            }else{task('reject',$id);}
        }finally{
            if($stopped)foreach(array_reverse($restart) as $group)supervisor('start',$group);
            if(Recovery::validId($id))clearRequest($dir);@unlink($resumeFile);
        }
    }
    if(time()-$lastPrune>300){task('prune');$lastPrune=time();}sleep(2);
}
