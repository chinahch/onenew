<?php
// Dependency-free behavioral tests for the real coordinator. Run in a separate PHP process.
namespace App\Onenew\AiOperations;
require __DIR__.'/../app/Onenew/AiOperations/Redactor.php';
require __DIR__.'/../app/Onenew/AiOperations/OperatorEngine.php';
require __DIR__.'/../app/Onenew/AiOperations/ProviderFailure.php';
if(!function_exists('mb_substr')){function mb_substr($s,$start,$length=null){return substr($s,$start,$length);}}
class Config {public function key($row=null){return 'fixture-api-key';}}
class RepoSettings {public function token(){return 'fixture-repo-key';}}
class OperatorTasks {public function current($fingerprint){if($fingerprint!=='fixture')throw new \RuntimeException('configuration_changed');return ['model'=>'fixture'];}}
class Context {public function snapshot($scope){return ['scope'=>$scope];}}
class Knowledge {public function entries($scope,$question){return [];}}
class NativeAdminTools {
    public static array $calls=[];
    public function catalog($args=[]){self::$calls[]=['catalog',$args];return ['items'=>[['capability'=>'fixture-plan-save','mode'=>'confirm']]];}
    public function read($admin,$args){self::$calls[]=['read',$admin,$args];return ['items'=>[['id'=>3,'name'=>'Plan']]];}
    public function prepare($admin,$args){self::$calls[]=['prepare',$admin,$args];return ['id'=>'native-card','kind'=>'native.admin','after'=>['capability'=>$args['capability']]];}
}
function app($class){if($class===NativeAdminTools::class)return new NativeAdminTools;throw new \RuntimeException('Unexpected dependency');}
class OperatorActions {
    public array $reads=[];public array $prepared=[];
    public function catalog(){return [];}
    public function read($tool,$args){$this->reads[]=[$tool,$args];return ['users'=>[['id'=>12]]];}
    public function prepare($admin,$kind,$args){$this->prepared[]=[$admin,$kind,$args];return ['id'=>'local-card-id','kind'=>$kind,'after'=>$args];}
}
class SourceTools {
    public array $proposed=[];
    public function catalog(){return [];}
    public function read($tool,$args){return ['path'=>$args['path']??'file.php','content'=>str_repeat('x',100001)];}
    public function propose($admin,$patches){$this->proposed[]=$patches;return ['id'=>'local-code-id','before'=>($patches[0]['path']??'')==='secret.php'?'fixture-repo-key':'safe source'];}
}
class Client {
    public array $messages=[];
    public function __construct(public array $responses){}
    public function completeRaw($config,$key,$messages){$this->messages[]=$messages;$next=array_shift($this->responses);if($next instanceof \Throwable)throw $next;return ['answer'=>is_string($next)?$next:json_encode($next),'usage'=>['total_tokens'=>2],'timings'=>['connect_ms'=>12,'starttransfer_ms'=>90000,'total_ms'=>91000,'url'=>'fixture-api-key','prompt'=>'private-prompt']];}
}
function check(bool $ok,string $message):void {if(!$ok)throw new \RuntimeException($message);}
function run(array $responses,string $message='Extend target@example.com'):array {
    $client=new Client($responses);$actions=new OperatorActions;$source=new SourceTools;
    $engine=new OperatorEngine($client,new Config,new RepoSettings,$actions,$source,new Context,new Knowledge,new OperatorTasks);
    $progress=[];$result=$engine->run(1,['message'=>$message,'history'=>[['role'=>'assistant','content'=>'password=fixture-password fixture-api-key unknown@example.com']],'config_fingerprint'=>'fixture'],function($message)use(&$progress){$progress[]=$message;});
    check(str_contains($progress[0],'等待模型响应'),'Model waiting progress missing');
    return [$result,$client,$actions,$source];
}
[$result,$client,$actions]=run([
    ['reads'=>[['tool'=>'users.lookup','args'=>['email'=>'email_ref_1']]]],
    ['answer'=>'Review target@example.com fixture-api-key fixture-repo-key','operations'=>[['kind'=>'user.extend','args'=>['user_id'=>12,'amount'=>2,'unit'=>'days']]]],
]);
check(count($client->messages)===2,'Expected read then final calls');
check($actions->reads===[['users.lookup',['email'=>'target@example.com']]],'Alias must resolve locally');
check(count($actions->prepared)===1&&count($result['actions'])===1,'Expected prepared card only');
check($result['trace'][0]['timings']===['connect_ms'=>12,'starttransfer_ms'=>90000,'total_ms'=>91000],'Model timing allowlist missing');
check(is_int($result['trace'][0]['duration_ms'])&&$result['trace'][1]['tool']==='users.lookup'&&is_int($result['trace'][1]['duration_ms']),'Provider and tool duration missing');
check(!str_contains(json_encode($result['trace']),'fixture-api-key')&&!str_contains(json_encode($result['trace']),'private-prompt'),'Timing trace leaked metadata');
foreach(['target@example.com','unknown@example.com','fixture-api-key','fixture-repo-key','fixture-password'] as $secret)check(!str_contains(json_encode($client->messages),$secret),'Outbound leaked '.$secret);
check(!str_contains($result['answer'],'fixture-api-key')&&!str_contains($result['answer'],'fixture-repo-key'),'Answer leaked key');
[$result,$client,$actions]=run([['reads'=>[['tool'=>'shell.exec','args'=>['command'=>'touch unsafe']]]],['operations'=>[['kind'=>'user.create','args'=>['email'=>'invented@example.com']],['kind'=>'user.create','args'=>['email'=>'email_ref_1','password'=>'injected']]]]]);
check($actions->reads===[]&&$actions->prepared===[]&&$result['actions']===[],'Unknown reads or invented emails/credentials reached local tools');
[$result,$client,$actions]=run(array_fill(0,4,['reads'=>[['tool'=>'inventory','args'=>['kind'=>'plans']]],'operations'=>[['kind'=>'node.delete','args'=>['node_id'=>12]]]]));
check(count($client->messages)===4&&count($actions->reads)===3&&$actions->prepared===[],'Read rounds must be bounded and intermediate operations ignored');
[$result,$client,$actions,$source]=run([['reads'=>[['tool'=>'source.read','args'=>['path'=>'customizations/onenew/test/app/file.php']]]],['answer'=>'bounded']]);
check(count(array_filter($result['trace'],fn($item)=>$item['status']==='rejected'))===1,'Read byte budget not enforced');
[$result]=run(['Plain text answer fixture-api-key']);check($result['actions']===[]&&$result['answer']==='Plain text answer [REDACTED]','Plain text fallback unsafe');
[$result,$client,$actions,$source]=run([['patches'=>[['path'=>'secret.php','old_text'=>'old','new_text'=>'new']]]]);check($result['code_changes']===[]&&count($source->proposed)===1,'Existing source credentials reached a proposal card');
[$result,$client,$actions,$source]=run([['patches'=>[['path'=>'safe.php','old_text'=>'old','new_text'=>'fixture-api-key']]]]);check($result['code_changes']===[]&&$source->proposed===[],'Model credential reached proposal tool');
[$result,$client]=run(['safe'],'Create target@example.com password natural-secret 密码是中文口令');check(!str_contains(json_encode($client->messages,JSON_UNESCAPED_UNICODE),'natural-secret')&&!str_contains(json_encode($client->messages,JSON_UNESCAPED_UNICODE),'中文口令'),'Natural-language password reached provider');
try{run([new \RuntimeException('fixture provider error')]);check(false,'Provider exception swallowed');}catch(\RuntimeException $e){check($e->getMessage()==='fixture provider error','Unexpected provider failure');}
NativeAdminTools::$calls=[];
[$result,$client]=run([
    ['reads'=>[['tool'=>'admin.catalog','args'=>['category'=>'plan']]]],
    ['reads'=>[['tool'=>'admin.read','args'=>['capability'=>'fixture-plan-list','params'=>[]]]]],
    ['answer'=>'Review native administrator action.','operations'=>[['kind'=>'native.admin','args'=>['capability'=>'fixture-plan-save','params'=>['email'=>'email_ref_1','name'=>'Updated']]]]],
]);
check(count(NativeAdminTools::$calls)===3&&count($result['actions'])===1,'Native discovery/read/confirmation proposal not supported');
check(NativeAdminTools::$calls[2][2]['params']['email']==='target@example.com','Nested native email alias not resolved locally');
check(!str_contains(json_encode($client->messages),'target@example.com'),'Native email alias leaked to provider');
NativeAdminTools::$calls=[];
[$result]=run([['operations'=>[['kind'=>'native.admin','args'=>['capability'=>'fixture','params'=>['email'=>'invented@example.com']]]]]]);
check(NativeAdminTools::$calls===[]&&$result['actions']===[],'Invented native email reached preparation');
echo "Operator engine alias isolation, proposal boundary, unknown tools, budgets, redaction and plain-text tests passed\n";
