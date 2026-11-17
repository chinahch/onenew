<?php
namespace Tests\Feature;

use App\Onenew\AiOperations\{Client,Config,Endpoint,OperatorTasks};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{Crypt,DB,Http};
use Tests\TestCase;

final class AiFailureReasonsTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_known_failure_tokens_are_exposed():void
    {
        foreach(['configuration_changed'=>'config_changed','time_budget'=>'time_budget','invalid_envelope'=>'invalid_response','read_budget'=>'read_budget'] as $token=>$code){
            self::assertSame($code,OperatorTasks::failure(new \RuntimeException($token))['code']);
        }
        $failure=OperatorTasks::failure(new \RuntimeException('https://secret.invalid Bearer secret-token prompt response'));
        self::assertSame('internal_error',$failure['code']);
        self::assertStringNotContainsString('secret',json_encode($failure));
    }

    public function test_client_http_status_and_transport_failures_have_safe_reasons():void
    {
        if(!extension_loaded('curl'))$this->markTestSkipped('Pinned transport requires curl.');
        $endpoint=new class extends Endpoint { public function addresses(string $host):array {return ['93.184.216.34'];} };
        $client=new Client($endpoint);$config=['endpoint'=>'https://api.example.com/v1','model'=>'model'];
        foreach([401=>'provider_auth',403=>'provider_auth',429=>'provider_rate_limit',400=>'provider_request',404=>'provider_request',503=>'provider_http'] as $status=>$expected){
            Http::swap(new \Illuminate\Http\Client\Factory);
            Http::fake(['*'=>Http::response(['error'=>'Bearer provider-secret prompt'],$status)]);
            try{$client->completeRaw($config,'api-secret',[]);self::fail('Expected provider rejection');}
            catch(\RuntimeException $e){self::assertSame($expected,OperatorTasks::failure($e)['code']);self::assertStringNotContainsString('secret',$e->getMessage());}
        }
        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::fake(fn()=>throw new \Illuminate\Http\Client\ConnectionException('cURL error 28 https://api-secret prompt'));
        try{$client->completeRaw($config,'api-secret',[]);self::fail('Expected connection failure');}
        catch(\RuntimeException $e){self::assertSame('provider_transport',OperatorTasks::failure($e)['code']);self::assertNull($e->getPrevious());}
        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::fake(fn()=>throw new \Illuminate\Http\Client\ConnectionException('secret timeout',0,new \GuzzleHttp\Exception\ConnectException('secret URL',new \GuzzleHttp\Psr7\Request('POST','https://api.example.com'),null,['errno'=>28])));
        try{$client->completeRaw($config,'api-secret',[]);self::fail('Expected timeout');}
        catch(\RuntimeException $e){self::assertSame('provider_timeout',OperatorTasks::failure($e)['code']);self::assertNull($e->getPrevious());}
        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::fake(['*'=>Http::response(['choices'=>[]],200)]);
        try{$client->completeRaw($config,'api-secret',[]);self::fail('Expected invalid response');}
        catch(\RuntimeException $e){self::assertSame('invalid_response',OperatorTasks::failure($e)['code']);}
    }

    public function test_config_change_is_persisted_and_viewed_without_payload():void
    {
        $tasks=app(OperatorTasks::class);$id='11111111-1111-4111-8111-111111111111';
        DB::table('onenew_ai_operator_tasks')->insert(['id'=>$id,'admin_id'=>1,'request_id'=>$id,'state'=>'queued','encrypted_payload'=>Crypt::encryptString(json_encode(['config_fingerprint'=>'outdated','message'=>'prompt-secret'])),'created_at'=>time(),'expires_at'=>time()+300,'progress'=>'queued']);
        $tasks->run($id);$view=$tasks->view(1,$id);
        self::assertSame('failed',$view['state']);self::assertSame('config_changed',$view['error_code']);self::assertSame('configuration',$view['error_stage']);
        self::assertNull(DB::table('onenew_ai_operator_tasks')->where('id',$id)->value('encrypted_payload'));
        self::assertStringNotContainsString('prompt-secret',json_encode($view));
    }
}
