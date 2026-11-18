<?php
namespace Tests\Feature;
use Tests\TestCase;
use App\Onenew\AiOperations\{Client,Config,Endpoint,OperatorJob,OperatorTasks};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{Bus,DB,Http};
use Illuminate\Support\Str;
use GuzzleHttp\{TransferStats};
use GuzzleHttp\Psr7\Request;
final class ProviderWaitTest extends TestCase
{
    use RefreshDatabase;
    private function ready():void {app(Config::class)->save(['enabled'=>true,'endpoint'=>'https://api.example.com/v1','model'=>'fixture','api_key'=>'fixture-api-key']);$this->app->bind(Endpoint::class,fn()=>new class extends Endpoint {public function addresses(string $host):array{return ['93.184.216.34'];}});}
    public function test_operator_wait_is_120_seconds_legacy_is_35_and_stats_only_contain_numeric_allowlist():void
    {
        $this->ready();Http::fake(function($request,$options){
            self::assertSame($request['max_tokens']===6000?120:35,$options['timeout']);self::assertSame(5,$options['connect_timeout']);
            $options['on_stats'](new TransferStats(new Request('POST','https://private.example/fixture-api-key'),null,90.5,null,['connect_time'=>0.25,'appconnect_time'=>0.5,'starttransfer_time'=>89.9,'total_time'=>90.5,'url'=>'fixture-api-key','request_header'=>'private-prompt','primary_ip'=>'93.184.216.34']));
            return Http::response(['choices'=>[['message'=>['content'=>'safe']]]],200);
        });
        $client=app(Client::class);$row=app(Config::class)->row();
        foreach([$client->completeRaw($row,'fixture-api-key',[['role'=>'user','content'=>'private-prompt']]),$client->complete($row,'fixture-api-key',[['role'=>'user','content'=>'private-prompt']])] as $result)self::assertSame(['connect_ms'=>250,'starttransfer_ms'=>89900,'total_ms'=>90500],$result['timings']);
        Http::assertSentCount(2);
    }
    public function test_structured_timeout_distinguishes_connect_and_response_without_retry_or_secret():void
    {
        $this->ready();$phase='connect';Http::fake(function($request,$options)use(&$phase){
            $options['on_stats'](new TransferStats(new Request('POST','https://secret.example/fixture-api-key'),null,120,null,['connect_time'=>$phase==='connect'?0:0.2,'appconnect_time'=>$phase==='connect'?0:0.4,'starttransfer_time'=>0,'total_time'=>120]));
            throw new \GuzzleHttp\Exception\ConnectException('private-prompt fixture-api-key',new Request('POST','https://secret.example'),null,['errno'=>28]);
        });
        foreach(['connect','response'] as $phase){
            try{app(Client::class)->completeRaw(app(Config::class)->row(),'fixture-api-key',[]);self::fail('Timeout accepted');}
            catch(\RuntimeException $e){self::assertSame('provider_'.$phase.'_timeout',OperatorTasks::failure($e)['code']);self::assertNull($e->getPrevious());self::assertStringNotContainsString('fixture-api-key',$e->getMessage());self::assertSame(120000,$e->timings['total_ms']);}
        }Http::assertSentCount(2);
    }
    public function test_worker_and_stale_budgets_cover_all_four_provider_calls():void
    {
        $this->ready();Bus::fake([OperatorJob::class]);self::assertSame(600,(new OperatorJob((string)Str::uuid()))->timeout);self::assertSame(660,config('queue.connections.onenew_ai_operator.retry_after'));self::assertSame(600,config('horizon.environments.production.ai_operator.timeout'));
        $tasks=app(OperatorTasks::class);$task=$tasks->create(1,['message'=>'fixture','request_id'=>(string)Str::uuid()]);DB::table('onenew_ai_operator_tasks')->where('id',$task['id'])->update(['state'=>'running','started_at'=>time()-301]);
        $tasks->prune();self::assertSame('running',$tasks->view(1,$task['id'])['state']);
        DB::table('onenew_ai_operator_tasks')->where('id',$task['id'])->update(['started_at'=>time()-661]);self::assertSame('failed',$tasks->view(1,$task['id'])['state']);
    }
}
