<?php
namespace Tests\Feature;

use App\Models\User;
use App\Services\AuthService;
use App\Onenew\AiOperations\{Config,Endpoint,OpenCodeClient,OperatorJob,OperatorTasks,Service};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{Bus,DB,Http};
use Illuminate\Support\Str;
use Tests\TestCase;

final class OpenCodeIntegrationTest extends TestCase
{
    use RefreshDatabase;
    private const ROOT='/api/onenew/admin/ai';
    private function auth():array {$user=User::create(['email'=>Str::random(12).'@example.test','password'=>password_hash('fixture',PASSWORD_DEFAULT),'uuid'=>(string)Str::uuid(),'token'=>Str::random(32),'is_admin'=>true]);$this->app['auth']->forgetGuards();return ['id'=>$user->id,'headers'=>['Authorization'=>(new AuthService($user))->generateAuthData()['auth_data']]];}
    private function ready(string $protocol='v2'):void {
        app(Config::class)->save(['enabled'=>true,'backend'=>'opencode','opencode_protocol'=>$protocol,'opencode_endpoint'=>'https://code.example.com:8443/base','opencode_directory'=>'/srv/project','opencode_username'=>'opencode','opencode_password'=>'fixture-opencode-password','opencode_model'=>'fake/model']);
        $this->app->bind(Endpoint::class,fn()=>new class extends Endpoint {public function addresses(string $host):array{return ['93.184.216.34'];}});Bus::fake([OperatorJob::class]);
    }
    public function test_fresh_opencode_config_accepts_inactive_empty_chat_fields_and_secrets_stay_independent():void {
        $a=$this->auth();$input=['enabled'=>true,'backend'=>'opencode','opencode_protocol'=>'v2','endpoint'=>'','model'=>'','opencode_endpoint'=>'https://code.example.com','opencode_directory'=>'/srv/project','opencode_username'=>'opencode','opencode_password'=>'fixture-opencode-password'];
        $response=$this->postJson(self::ROOT.'/config',$input,$a['headers'])->assertOk()->assertJsonPath('config.backend','opencode')->assertJsonPath('config.opencode_protocol','v2')->assertJsonPath('config.opencode_password_configured',true);
        self::assertStringNotContainsString('fixture-opencode-password',$response->getContent());self::assertStringNotContainsString('fixture-opencode-password',DB::table('onenew_ai_config')->value('encrypted_opencode_password'));self::assertTrue(app(Config::class)->ready(app(Config::class)->row()));
        $this->postJson(self::ROOT.'/config',['api_key'=>'chat-key','opencode_password'=>''],$a['headers'])->assertOk();self::assertSame('fixture-opencode-password',app(Config::class)->opencodePassword());self::assertSame('chat-key',app(Config::class)->key());
        $this->postJson(self::ROOT.'/config',['backend'=>'chat_completions'],$a['headers'])->assertOk();self::assertSame('fixture-opencode-password',app(Config::class)->opencodePassword());
        $this->postJson(self::ROOT.'/config',['clear_opencode_password'=>true],$a['headers'])->assertOk()->assertJsonPath('config.opencode_password_configured',false);self::assertSame('chat-key',app(Config::class)->key());
    }
    public function test_connection_test_uses_basic_auth_pinned_https_health_only_and_rejects_wrong_protocol():void {
        if(!extension_loaded('curl'))$this->markTestSkipped('Pinned transport requires curl.');$this->ready();
        Http::fake(function($request,$options){self::assertSame('GET',$request->method());self::assertSame('https://code.example.com:8443/base/api/info',$request->url());self::assertTrue($request->hasHeader('Authorization','Basic '.base64_encode('opencode:fixture-opencode-password')));self::assertSame(['code.example.com:8443:93.184.216.34'],$options['curl'][CURLOPT_RESOLVE]);self::assertFalse($options['allow_redirects']);self::assertTrue($options['verify']);self::assertSame('',$options['proxy']);return Http::response(['version'=>'2.3.2','pid'=>1,'urls'=>[],'paths'=>['tmp'=>'/tmp']],200);});
        self::assertTrue(app(Service::class)->test());Http::assertSentCount(1);
        Http::swap(new \Illuminate\Http\Client\Factory);Http::fake(['*'=>Http::response(['healthy'=>true,'version'=>'1.19.6'],200)]);self::assertFalse(app(Service::class)->test());Http::assertSentCount(1);
    }
    public function test_opencode_refuses_legacy_preview_and_scopes_persistent_history_by_admin():void {
        $this->ready();Http::preventStrayRequests();$a=$this->auth();
        $this->postJson(self::ROOT.'/preview',['question'=>'help','scope'=>'panel'],$a['headers'])->assertStatus(422);Http::assertNothingSent();
        $scope=$this->getJson(self::ROOT.'/config',$a['headers'])->assertOk()->json('storage_scope');self::assertIsString($scope);self::assertSame($scope,$this->getJson(self::ROOT.'/config',$a['headers'])->assertOk()->json('storage_scope'));
        self::assertSame((int)$a['id'],(int)$this->app['auth']->guard('sanctum')->id());
        // A Laravel test application retains resolved guards across requests. Create B
        // after A's requests so auth() clears that guard before B is authenticated.
        $b=$this->auth();$otherScope=$this->getJson(self::ROOT.'/config',$b['headers'])->assertOk()->json('storage_scope');
        self::assertSame((int)$b['id'],(int)$this->app['auth']->guard('sanctum')->id());
        self::assertSame(hash_hmac('sha256','ai-chat:'.$b['id'],(string)config('app.key')),$otherScope);self::assertNotSame($scope,$otherScope);
    }
    public function test_real_task_persists_dispatch_before_network_and_deduplicates_lost_acknowledgement():void {
        if(!extension_loaded('curl'))$this->markTestSkipped('Pinned transport requires curl.');$this->ready();$a=$this->auth();$input=['message'=>'edit file','request_id'=>(string)Str::uuid()];$tasks=app(OperatorTasks::class);$task=$tasks->create($a['id'],$input);$id=$task['id'];$session=null;
        Http::fake(function($request)use($id,&$session){$path=parse_url($request->url(),PHP_URL_PATH);$stored=DB::table('onenew_ai_operator_tasks')->where('id',$id)->first();
            if($path==='/base/api/info')return Http::response(['version'=>'2.3.2','pid'=>1,'urls'=>[],'paths'=>['tmp'=>'/tmp']],200);
            if($path==='/base/api/session'){self::assertSame('create_dispatching',$stored->remote_stage);self::assertSame($request['id'],$stored->remote_session_id);$session=$request['id'];return Http::response(['data'=>['id'=>$session,'location'=>['directory'=>'/srv/project']]],200);}
            if(str_ends_with($path,'/prompt')){self::assertSame('prompt_dispatching',$stored->remote_stage);self::assertSame($request['id'],$stored->remote_message_id);throw new \Illuminate\Http\Client\ConnectionException('raw fixture-opencode-password uncertain');}
            if(str_ends_with($path,'/interrupt'))return Http::response(['interrupted'=>true],200);
            self::fail('Unexpected request '.$path);
        });
        $tasks->run($id);$view=$tasks->view($a['id'],$id);self::assertSame('failed',$view['state']);self::assertSame('opencode_uncertain',$view['error_code']);self::assertSame($id,$tasks->create($a['id'],$input)['id']);$tasks->run($id);Http::assertSentCount(3);self::assertStringNotContainsString('fixture-opencode-password',json_encode($view));self::assertNull(DB::table('onenew_ai_operator_tasks')->where('id',$id)->value('encrypted_payload'));
    }
    public function test_hard_worker_timeout_reports_unknown_remote_effects_without_retry():void {
        $this->ready();$a=$this->auth();$tasks=app(OperatorTasks::class);$task=$tasks->create($a['id'],['message'=>'edit','request_id'=>(string)Str::uuid()]);
        DB::table('onenew_ai_operator_tasks')->where('id',$task['id'])->update(['state'=>'running','started_at'=>time()-661,'remote_stage'=>'prompt_dispatching','remote_session_id'=>'ses_fixture','remote_message_id'=>'msg_fixture']);
        Http::preventStrayRequests();$tasks->run($task['id']);self::assertSame('opencode_interrupted',$tasks->view($a['id'],$task['id'])['error_code']);Http::assertNothingSent();
    }
}
