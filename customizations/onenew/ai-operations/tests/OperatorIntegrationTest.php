<?php
namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Services\AuthService;
use App\Onenew\AiOperations\{Client,Config,OperatorEngine,OperatorTasks,OperatorJob};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{Bus,Crypt,DB};
use Illuminate\Support\Str;

final class OperatorIntegrationTest extends TestCase
{
    use RefreshDatabase;
    private const ROOT='/api/onenew/admin/ai';
    private function auth(bool $admin=true):array {$u=User::create(['email'=>Str::random(12).'@example.test','password'=>password_hash('fixture-password',PASSWORD_DEFAULT),'uuid'=>(string)Str::uuid(),'token'=>Str::random(32),'is_admin'=>$admin]);$this->app['auth']->forgetGuards();return ['headers'=>['Authorization'=>(new AuthService($u))->generateAuthData()['auth_data']],'id'=>$u->id];}
    protected function setUp():void {parent::setUp();Bus::fake([OperatorJob::class]);app(Config::class)->save(['enabled'=>true,'endpoint'=>'https://api.example.com/v1','model'=>'fixture','api_key'=>'fixture-api-secret']);}
    public function test_async_auth_ownership_deduplication_and_encrypted_payload():void
    {
        $u=$this->auth(false);$this->postJson(self::ROOT.'/operator/tasks',['message'=>'hi','request_id'=>(string)Str::uuid()],$u['headers'])->assertStatus(403);
        $a=$this->auth();$input=['message'=>'Create explicit.person@example.com','request_id'=>(string)Str::uuid()];
        $one=$this->postJson(self::ROOT.'/operator/tasks',$input,$a['headers'])->assertOk()->assertJsonPath('state','queued')->json();
        self::assertSame($one,$this->postJson(self::ROOT.'/operator/tasks',$input,$a['headers'])->assertOk()->json());Bus::assertDispatchedTimes(OperatorJob::class,1);
        $row=(array)DB::table('onenew_ai_operator_tasks')->where('id',$one['id'])->first();self::assertStringNotContainsString('explicit.person@example.com',$row['encrypted_payload']);self::assertStringNotContainsString('fixture-api-secret',Crypt::decryptString($row['encrypted_payload']));
        $b=$this->auth();$this->getJson(self::ROOT.'/operator/tasks/'.$one['id'],$b['headers'])->assertStatus(404);
    }
    public function test_model_read_then_prepare_never_writes_until_confirmed_and_executes_once():void
    {
        $a=$this->auth();$target=User::create(['email'=>'target@example.com','password'=>password_hash('fixture',PASSWORD_DEFAULT),'uuid'=>(string)Str::uuid(),'token'=>Str::random(32),'expired_at'=>time()+3600]);$before=$target->expired_at;
        $calls=[];$responses=[['answer'=>'Looking up target','reads'=>[['tool'=>'users.lookup','args'=>['email'=>'email_ref_1']]]],['answer'=>'Confirm this adjustment.','operations'=>[['kind'=>'user.extend','args'=>['user_id'=>$target->id,'amount'=>2,'unit'=>'days']]]]];
        $this->app->bind(Client::class,function()use(&$calls,$responses){return new class($calls,$responses) extends Client {private $calls;private array $responses;public function __construct(&$calls,$responses){$this->calls=&$calls;$this->responses=$responses;}public function completeRaw(array $config,string $key,array $messages):array {$this->calls[]=$messages;return ['answer'=>json_encode(array_shift($this->responses)),'model'=>'fixture','usage'=>[]];}};});
        $task=$this->postJson(self::ROOT.'/operator/tasks',['message'=>'Extend target@example.com by two days','request_id'=>(string)Str::uuid()],$a['headers'])->assertOk()->json();
        app(OperatorTasks::class)->run($task['id']);$result=$this->getJson(self::ROOT.'/operator/tasks/'.$task['id'],$a['headers'])->assertOk()->assertJsonPath('state','done')->json('result');
        self::assertSame($before,$target->fresh()->expired_at);self::assertCount(1,$result['actions']);self::assertCount(2,$calls);self::assertStringNotContainsString('target@example.com',json_encode($calls));self::assertStringNotContainsString('fixture-api-secret',json_encode($calls));
        $confirm=['confirmed'=>true,'request_id'=>(string)Str::uuid()];$id=$result['actions'][0]['id'];$this->postJson(self::ROOT.'/actions/'.$id.'/execute',$confirm,$a['headers'])->assertOk();$expiry=$target->fresh()->expired_at;
        $this->postJson(self::ROOT.'/actions/'.$id.'/execute',$confirm,$a['headers'])->assertOk();self::assertSame($expiry,$target->fresh()->expired_at);self::assertGreaterThan($before,$expiry);
        self::assertNull(DB::table('onenew_ai_operator_tasks')->where('id',$task['id'])->value('encrypted_payload'));
        app(OperatorTasks::class)->run($task['id']);self::assertCount(2,$calls);
    }
    public function test_unknown_tools_invented_email_and_credentials_never_prepare_actions():void
    {
        $a=$this->auth();$this->app->bind(Client::class,fn()=>new class extends Client {public function __construct(){}public function completeRaw(array $config,string $key,array $messages):array{return ['answer'=>json_encode(['answer'=>'proposal','operations'=>[['kind'=>'user.create','args'=>['email'=>'invented@example.com','password'=>'model-password','is_admin'=>true]]]]),'model'=>'fixture','usage'=>[]];}});
        $task=app(OperatorTasks::class)->create($a['id'],['message'=>'hello','request_id'=>(string)Str::uuid()]);app(OperatorTasks::class)->run($task['id']);$result=app(OperatorTasks::class)->view($a['id'],$task['id']);self::assertSame([],$result['result']['actions']);self::assertFalse(User::where('email','invented@example.com')->exists());
    }
    public function test_changed_config_and_expired_running_task_fail_without_provider_retry():void
    {
        $a=$this->auth();$task=app(OperatorTasks::class)->create($a['id'],['message'=>'hello','request_id'=>(string)Str::uuid()]);app(Config::class)->save(['model'=>'changed']);
        $this->app->bind(Client::class,fn()=>new class extends Client {public function __construct(){}public function completeRaw(array $config,string $key,array $messages):array{throw new \LogicException('Must not call provider');}});
        app(OperatorTasks::class)->run($task['id']);self::assertSame('failed',app(OperatorTasks::class)->view($a['id'],$task['id'])['state']);
        $task=app(OperatorTasks::class)->create($a['id'],['message'=>'hello','request_id'=>(string)Str::uuid()]);DB::table('onenew_ai_operator_tasks')->where('id',$task['id'])->update(['state'=>'running','started_at'=>time()-301]);
        app(OperatorTasks::class)->run($task['id']);self::assertSame('failed',app(OperatorTasks::class)->view($a['id'],$task['id'])['state']);
    }
}
