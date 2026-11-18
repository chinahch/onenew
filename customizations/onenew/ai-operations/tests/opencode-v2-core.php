<?php
namespace App\Onenew\AiOperations;
if(!function_exists('mb_substr')){function mb_substr($value,$start,$length=null){return implode('',array_slice(preg_split('//u',$value,-1,PREG_SPLIT_NO_EMPTY)?:[],$start,$length));}}
require __DIR__.'/../app/Onenew/AiOperations/Redactor.php';
require __DIR__.'/../app/Onenew/AiOperations/OpenCodeClient.php';
require __DIR__.'/../app/Onenew/AiOperations/OpenCodeEngine.php';
function check(bool $ok,string $why):void {if(!$ok)throw new \RuntimeException($why);}
$file=__DIR__.'/../app/Onenew/AiOperations/OpenCodeV2Engine.php';check(is_file($file),'OpenCode V2 session coordinator is not implemented');require $file;
class V2FixtureClient extends OpenCodeClient {
    public array $calls=[];public array $stages=[];public string $mode='success';public string $session='';public string $message='';
    public function __construct(){}
    public function request(array $config,string $password,string $method,string $path,?array $body=null):mixed {
        $this->calls[]=[$method,$path,$body];
        if($path==='/api/info')return ['version'=>'2.3.2','pid'=>1,'urls'=>[],'paths'=>['tmp'=>'/tmp']];
        if($path==='/api/session'&&$method==='POST'){
            check(end($this->stages)['stage']==='create_dispatching','V2 create must persist before POST');check($body['location']===['directory'=>'/srv/project'],'V2 needs location directory');check($body['model']===['providerID'=>'fake','id'=>'model'],'V2 uses Model.Ref id');check(!isset($body['permissions']),'V2 must preserve server permission policy');
            $this->session=$body['id'];return ['data'=>['id'=>$this->session,'location'=>['directory'=>'/srv/project']]];
        }
        if(str_ends_with($path,'/prompt')){
            check(end($this->stages)['stage']==='prompt_dispatching','V2 prompt must persist before POST');check(str_contains($body['text'],'CURRENT REQUEST')&&str_contains($body['text'],'QUOTED HISTORY'),'V2 prompt trust boundary missing');$this->message=$body['id'];
            if($this->mode==='lost')throw new \RuntimeException('opencode_uncertain');return ['data'=>['id'=>$this->message,'sessionID'=>$this->session,'type'=>'user']];
        }
        if(str_ends_with($path,'/interrupt'))return ['interrupted'=>true];
        if(str_ends_with($path,'/permission'))return ['data'=>$this->mode==='permission'?[['id'=>'per_fixture','sessionID'=>$this->session,'action'=>'bash','resources'=>[]]]:[]];
        if(str_ends_with($path,'/form'))return ['data'=>$this->mode==='question'?[['id'=>'form_fixture']]:[]];
        if($path==='/api/session/active')return ['data'=>[]];
        if(str_contains($path,'/message?'))return ['data'=>[
            ['type'=>'user','id'=>$this->message,'text'=>'requested edit'],
            ['type'=>'assistant','id'=>'msg_answer','time'=>['created'=>1,'completed'=>2],'finish'=>'stop','model'=>['providerID'=>'fake','id'=>'model'],'content'=>[['type'=>'tool','id'=>'tool1','name'=>$this->mode==='tool-secret'?'fixture-password':'bash','time'=>['created'=>1,'completed'=>2],'state'=>['status'=>$this->mode==='tool-error'?'error':'completed','input'=>['secret'=>'fixture-password'],'content'=>[['type'=>'text','text'=>'secret command output']],'metadata'=>['exit'=>$this->mode==='exit-error'?1:0]]],['type'=>'text','text'=>'Finished fixture-password']]],
            ['type'=>'idle','id'=>'msg_idle','time'=>['created'=>3],'outcome'=>$this->mode==='remote-error'?'failed':'succeeded'],
        ],'cursor'=>[]];
        if($path==='/api/session/'.$this->session)return ['data'=>['id'=>$this->session,'outcome'=>'succeeded','location'=>['directory'=>'/srv/project'],'time'=>['created'=>1,'idle'=>3]]];
        throw new \LogicException('Unexpected V2 route '.$path);
    }
}
function fixture(string $mode):array {
    $client=new V2FixtureClient;$client->mode=$mode;$engine=new OpenCodeEngine($client);
    try{$result=$engine->run(['opencode_protocol'=>'v2','opencode_directory'=>'/srv/project','opencode_agent'=>'build','opencode_model'=>'fake/model'],'fixture-password',['message'=>'edit','history'=>[]],fn($value)=>null,function($value)use($client){$client->stages[]=$value;},fn()=>true);return [$result,$client];}catch(\RuntimeException $e){return [$e,$client];}
}
[$result,$client]=fixture('success');check(is_array($result),'V2 successful session failed');
check($result['backend']==='opencode'&&$result['remote']['protocol']==='v2'&&$result['remote']['version']==='2.3.2','V2 result identity missing');
check($result['answer']==='Finished [REDACTED]'&&$result['actions']===[]&&$result['code_changes']===[],'V2 secret/action boundary failed');
check(!str_contains(json_encode($result),'secret command output')&&!str_contains(json_encode($result),'fixture-password'),'V2 result leaked remote content');
[$result,$client]=fixture('tool-secret');check(is_array($result)&&!str_contains(json_encode($result),'fixture-password'),'V2 remote tool name leaked saved password');
foreach(['lost'=>'opencode_uncertain','permission'=>'opencode_permission','question'=>'opencode_question','tool-error'=>'opencode_tool_failed','exit-error'=>'opencode_tool_failed','remote-error'=>'opencode_remote_failed'] as $mode=>$expected){
    [$result,$client]=fixture($mode);check($result instanceof \RuntimeException&&$result->getMessage()===$expected,'Wrong V2 failure for '.$mode);
    check(count(array_filter($client->calls,fn($call)=>str_ends_with($call[1],'/prompt')))===1,'V2 prompt retried');check(count(array_filter($client->calls,fn($call)=>str_ends_with($call[1],'/interrupt')))===1,'V2 did not interrupt best effort');
}
echo "OpenCode V2 durable prompt, location/model contract, pending forms, tools and uncertain outcomes passed\n";
