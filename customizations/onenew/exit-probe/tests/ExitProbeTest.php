<?php
namespace Tests\Feature\Onenew;
use App\Models\ServerMachine;
use App\Models\Server;
use App\Models\User;
use App\Onenew\ExitProbeService;
use App\Onenew\ProbeStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ExitProbeTest extends TestCase
{
    use RefreshDatabase;
    protected function setUp(): void
    {
        parent::setUp(); config()->set('onenew.health_store', 'array');
        config()->set('cache.stores.redis.driver', 'array'); Cache::purge('redis');
        $this->app->forgetInstance(\App\Support\Setting::class);
        config()->set('app.key', 'base64:' . base64_encode(str_repeat('e', 32)));
        Cache::store('array')->flush();
    }
    public function test_runtime_endpoint_requires_admin_and_exposes_only_live_kernel_metadata(): void
    {
        [$machine, $admin] = $this->fixture();
        $node = Server::where('machine_id', $machine->id)->firstOrFail();
        $url = '/api/onenew/admin/node-runtime/' . $node->id;
        $this->getJson($url)->assertForbidden();
        Sanctum::actingAs($admin);
        $this->getJson($url)->assertOk()->assertJsonPath('kernel_type', 'singbox')
            ->assertJsonPath('onenew_runtime', true)->assertJsonPath('machine_id', $machine->id)
            ->assertJsonMissing(['token' => $machine->token]);
    }

    private function fixture(): array
    {
        $machine = ServerMachine::create(['name'=>'exit-machine','token'=>'private-machine-token','is_active'=>true]);
        $node = Server::create(['name'=>'runtime-reported','type'=>'vmess','host'=>'public.example','port'=>443,'rate'=>'1',
            'machine_id'=>$machine->id,'group_ids'=>['1'],'enabled'=>true,'show'=>true,
            ]);
        \App\Services\ServerService::updateMetrics($node, ['kernel_type'=>'singbox','onenew_runtime'=>true]);
        $admin = User::create(['email'=>'exit-admin@example.test','password'=>'unused','uuid'=>(string)\Illuminate\Support\Str::uuid(),
            'token'=>bin2hex(random_bytes(16)),'is_admin'=>true,'transfer_enable'=>1000,'u'=>0,'d'=>0,'banned'=>0]);
        return [$machine,$admin];
    }
    private function draft($machine): array
    {
        return ['machine_id'=>$machine->id,'core'=>'singbox','outbound'=>['tag'=>'landing','protocol'=>'socks',
            'settings'=>['server'=>'landing.example','server_port'=>1080,'username'=>'private-user','password'=>'private-proxy-password']]];
    }
    public function test_real_draft_queue_is_encrypted_scoped_and_serialized_with_automatic_probes(): void
    {
        [$machine,$admin]=$this->fixture();Sanctum::actingAs($admin);
        $created=$this->postJson('/api/onenew/admin/exit-probes',$this->draft($machine))->assertOk()->json();
        $service=app(ExitProbeService::class);$this->assertTrue($service->busy($machine->id));
        $this->postJson('/api/onenew/admin/exit-probes',$this->draft($machine))->assertConflict();
        $cached=app(ProbeStore::class)->cache()->get('onenew:exit-probe:task:'.$created['id']);
        $this->assertStringNotContainsString('private-proxy-password',json_encode($cached));
        $this->assertArrayHasKey('ciphertext',$cached);
        $this->getJson('/api/v3/server/probes?machine_id='.$machine->id)->assertForbidden();
        $this->withToken($machine->token)->getJson('/api/v3/server/probes?machine_id='.$machine->id)->assertExactJson(['tasks'=>[]]);
        $task=$this->withToken($machine->token)->getJson('/api/v3/server/exit-probes?machine_id='.$machine->id)->assertOk()->json('tasks.0');
        $this->assertSame(ExitProbeService::TARGET,$task['payload']['target']);
        $this->assertSame('private-proxy-password',$task['payload']['outbound']['settings']['password']);
        $this->withToken($machine->token)->getJson('/api/v3/server/exit-probes?machine_id='.$machine->id)->assertExactJson(['tasks'=>[]]);
        $url='/api/v3/server/exit-probes/'.$task['id'].'/result';
        $result=['machine_id'=>$machine->id,'lease'=>$task['lease'],'state'=>'succeeded','exit_ip'=>'203.0.113.9','country'=>'JP'];
        $this->postJson($url,array_replace($result,['lease'=>'wrong']))->assertConflict();
        $this->postJson($url,array_replace($result,['exit_ip'=>'invalid']))->assertUnprocessable();
        $this->postJson($url,array_replace($result,['country'=>'JPN']))->assertUnprocessable();
        $this->postJson($url,$result)->assertOk();$this->postJson($url,$result)->assertConflict();
        $this->assertFalse($service->busy($machine->id));
        $status=$this->getJson('/api/onenew/admin/exit-probes/'.$task['id'])->assertOk()->assertJsonPath('exit_ip','203.0.113.9')->assertJsonPath('country','JP');
        $this->assertStringNotContainsString('private-',$status->getContent());
        $cached=app(ProbeStore::class)->cache()->get('onenew:exit-probe:task:'.$created['id']);
        $this->assertArrayNotHasKey('ciphertext',$cached);$this->assertArrayNotHasKey('lease_hash',$cached);
    }
    public function test_authorization_target_proxy_bypass_and_expiration(): void
    {
        [$machine,$admin]=$this->fixture();
        $this->postJson('/api/onenew/admin/exit-probes',$this->draft($machine))->assertForbidden();
        Sanctum::actingAs($admin);
        $data=$this->draft($machine);$data['target']='http://localhost/';
        $this->postJson('/api/onenew/admin/exit-probes',$data)->assertUnprocessable();
        unset($data['target']);$data['outbound']['protocol']='direct';
        $this->postJson('/api/onenew/admin/exit-probes',$data)->assertUnprocessable();
        $data=$this->draft($machine);$data['outbound']['settings']['detour']='direct';
        $this->postJson('/api/onenew/admin/exit-probes',$data)->assertUnprocessable();
        $id=$this->postJson('/api/onenew/admin/exit-probes',$this->draft($machine))->assertOk()->json('id');
        $this->travel(61)->seconds();
        $this->assertFalse(app(ExitProbeService::class)->busy($machine->id));
        $this->getJson('/api/onenew/admin/exit-probes/'.$id)->assertOk()->assertJsonPath('state','expired');
        $this->assertNull(app(ProbeStore::class)->cache()->get('onenew:exit-probe:task:'.$id));
    }
    public function test_encrypted_queue_preserves_empty_transport_objects_and_alpn_lists_on_wire(): void
    {
        [$machine,$admin]=$this->fixture();Sanctum::actingAs($admin);
        $node=Server::where('machine_id',$machine->id)->firstOrFail();
        \App\Services\ServerService::updateMetrics($node,['kernel_type'=>'xray','onenew_runtime'=>true]);
        $draft=['machine_id'=>$machine->id,'core'=>'xray-caddy','outbound'=>['tag'=>'landing','protocol'=>'vless',
            'settings'=>['vnext'=>[['address'=>'edge.example','port'=>443,'users'=>[['id'=>'uuid','encryption'=>'none']]]]],
            'stream_settings'=>['network'=>'ws','security'=>'tls','tlsSettings'=>['serverName'=>'edge.example','alpn'=>[]],
                'wsSettings'=>['path'=>'/','headers'=>[]]]]];
        $this->postJson('/api/onenew/admin/exit-probes',$draft)->assertOk();
        $response=$this->withToken($machine->token)->getJson('/api/v3/server/exit-probes?machine_id='.$machine->id)->assertOk();
        $wire=json_decode($response->getContent());
        $this->assertInstanceOf(\stdClass::class,$wire->tasks[0]->payload->outbound->stream_settings->wsSettings->headers);
        $this->assertSame([],$wire->tasks[0]->payload->outbound->stream_settings->tlsSettings->alpn);
    }
}
