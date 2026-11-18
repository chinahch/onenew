<?php
// Dependency-free tests of the production session coordinator, with only HTTP replaced.
namespace App\Onenew\AiOperations;
if(!function_exists('mb_substr')){function mb_substr($value,$start,$length=null){return implode('',array_slice(preg_split('//u',$value,-1,PREG_SPLIT_NO_EMPTY)?:[],$start,$length));}}
require __DIR__.'/../app/Onenew/AiOperations/Redactor.php';
function check(bool $ok,string $why):void {if(!$ok)throw new \RuntimeException($why);}
$file=__DIR__.'/../app/Onenew/AiOperations/OpenCodeEngine.php';
check(is_file($file),'OpenCode session coordinator is not implemented');
require __DIR__.'/../app/Onenew/AiOperations/OpenCodeClient.php';
require $file;
class FixtureClient extends OpenCodeClient {
    public array $calls=[];public array $stages=[];public string $mode='success';public string $message='';
    public function __construct(){}
    public function request(array $config,string $password,string $method,string $path,?array $body=null):mixed {
        $this->calls[]=[$method,$path,$body];
        if($path==='/global/health')return ['healthy'=>true,'version'=>'1.19.6'];
        if($method==='POST'&&$path==='/session'){check(end($this->stages)['stage']==='create_dispatching','Session creation must be durable before POST');return ['id'=>'ses_fixture','directory'=>'/srv/project'];}
        if(str_ends_with($path,'/prompt_async')){
            check(end($this->stages)['stage']==='prompt_dispatching','Prompt dispatch must be durable before POST');
            check(!isset($body['tools'])&&!isset($body['permission']),'Server policy must not be overridden');
            check(str_contains($body['parts'][0]['text'],'CURRENT REQUEST')&&str_contains($body['parts'][0]['text'],'QUOTED HISTORY'),'History and current request need separate trust boundaries');
            $this->message=$body['messageID'];if($this->mode==='lost')throw new \RuntimeException('opencode_uncertain');return null;
        }
        if(str_ends_with($path,'/abort'))return true;
        if($path==='/permission')return $this->mode==='permission'?[['id'=>'per_fixture','sessionID'=>'ses_fixture','permission'=>'bash','metadata'=>['secret'=>'fixture-password']]]:[];
        if($path==='/question')return $this->mode==='question'?[['id'=>'que_fixture','sessionID'=>'ses_fixture','questions'=>[]]]:[];
        if($path==='/session/status')return ['ses_fixture'=>['type'=>'idle']];
        if(str_ends_with($path,'/message'))return [['info'=>['id'=>'msg_answer','sessionID'=>'ses_fixture','role'=>'assistant','parentID'=>$this->message,'time'=>['created'=>1,'completed'=>2],'finish'=>'stop','modelID'=>'fixture','providerID'=>'fake'],'parts'=>[['id'=>'prt_fixture','type'=>'tool','tool'=>$this->mode==='tool-secret'?'fixture-password':'bash','state'=>['status'=>$this->mode==='tool-error'?'error':'completed','metadata'=>['exit'=>$this->mode==='exit-error'?1:0],'input'=>['secret'=>'fixture-password'],'output'=>'secret output','error'=>'fixture-password raw failure']],['type'=>'text','text'=>'Finished fixture-password']]]];
        throw new \LogicException('Unexpected route '.$path);
    }
}
function fixture(string $mode):array {
    $client=new FixtureClient;$client->mode=$mode;$engine=new OpenCodeEngine($client);$progress=[];
    try{$result=$engine->run(['opencode_protocol'=>'v1','opencode_endpoint'=>'https://code.example.com','opencode_directory'=>'/srv/project','opencode_agent'=>'','opencode_model'=>''], 'fixture-password',['message'=>'Do the requested edit','history'=>[['role'=>'assistant','content'=>'old instruction']]],function($value)use(&$progress){$progress[]=$value;},function($value)use($client){$client->stages[]=$value;},fn()=>true);return [$result,$client,$progress];}
    catch(\RuntimeException $e){return [$e,$client,$progress];}
}
[$result,$client]=fixture('success');check(is_array($result),'Successful session failed');
check($result['backend']==='opencode'&&$result['actions']===[]&&$result['code_changes']===[],'Remote output became panel actions');
check($result['answer']==='Finished [REDACTED]','Answer did not redact configured secret');
check(!str_contains(json_encode($result),'secret output')&&!str_contains(json_encode($result),'fixture-password'),'Remote metadata leaked secret/tool bodies');
check($result['remote']['session_id']==='ses_fixture'&&$result['trace'][0]['status']==='completed','Remote result metadata/progress missing');
[$result,$client,$progress]=fixture('tool-secret');check(is_array($result)&&!str_contains(json_encode([$result,$progress]),'fixture-password'),'Remote tool name leaked the saved password');
foreach(['lost'=>'opencode_uncertain','permission'=>'opencode_permission','question'=>'opencode_question','tool-error'=>'opencode_tool_failed','exit-error'=>'opencode_tool_failed'] as $mode=>$expected){
    [$result,$client]=fixture($mode);check($result instanceof \RuntimeException&&$result->getMessage()===$expected,'Wrong outcome for '.$mode);
    check(count(array_filter($client->calls,fn($call)=>str_ends_with($call[1],'/prompt_async')))===1,'Mutation retried for '.$mode);
    check(count(array_filter($client->calls,fn($call)=>str_ends_with($call[1],'/abort')))===1,'Failed remote run was not aborted best effort');
}
echo "OpenCode session dispatch durability, isolation, permissions, tool errors and lost-ack tests passed\n";
