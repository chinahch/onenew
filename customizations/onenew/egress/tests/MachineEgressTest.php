<?php
namespace Tests\Feature\Onenew;

use App\Models\Server;
use App\Models\ServerMachine;
use App\Models\User;
use App\Onenew\MachineEgress;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MachineEgressTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        config()->set('cache.stores.redis.driver', 'array'); Cache::purge('redis');
        $machine = ServerMachine::create(['name'=>'egress-machine','token'=>'egress-private-token']);
        $node = Server::create(['name'=>'egress-node','type'=>'vmess','host'=>'example.test','port'=>443,'server_port'=>443,
            'rate'=>'1','machine_id'=>$machine->id,'enabled'=>true,'show'=>true]);
        $admin = User::create(['email'=>'egress@example.test','password'=>'unused','uuid'=>(string)\Illuminate\Support\Str::uuid(),
            'token'=>bin2hex(random_bytes(16)),'is_admin'=>true,'transfer_enable'=>1000,'u'=>0,'d'=>0,'banned'=>0]);
        return [$machine, $node, $admin];
    }

    public function test_admin_save_scopes_overrides_and_preserves_default_inheritance(): void
    {
        [$machine,$node,$admin]=$this->fixture();
        $url='/api/onenew/admin/machine-egress';
        $this->getJson($url.'?machine_id='.$machine->id)->assertForbidden();
        Sanctum::actingAs($admin);
        $base=['machine_identity'=>$machine->onenew_identity,'node_identities'=>[(string)$node->id=>$node->onenew_identity]];
        $this->assertNull(MachineEgress::forNode($node));
        $this->postJson($url,$base+['machine_id'=>$machine->id,'mode'=>'prefer_ipv6','node_overrides'=>[]])->assertOk();
        $this->assertSame('prefer_ipv6',MachineEgress::forNode($node));
        $this->postJson($url,$base+['machine_id'=>$machine->id,'mode'=>'prefer_ipv6','node_overrides'=>[(string)$node->id=>'default']])->assertOk();
        $this->assertSame('default',MachineEgress::forNode($node));
        $this->postJson($url,$base+['machine_id'=>$machine->id,'mode'=>'prefer_ipv6','node_overrides'=>['999999'=>'prefer_ipv4']])->assertUnprocessable();
        $this->assertSame('default',MachineEgress::forNode($node));
        $this->postJson($url,$base+['machine_id'=>$machine->id,'mode'=>'ipv4_only','node_overrides'=>[]])->assertUnprocessable();
        $this->postJson($url,$base+['machine_id'=>$machine->id,'mode'=>'inherit','node_overrides'=>[]])->assertOk();
        $this->assertNull(MachineEgress::forNode($node));
    }

    public function test_reused_node_id_and_deleted_machine_do_not_inherit_old_preferences(): void
    {
        [$machine,$node]=$this->fixture();
        MachineEgress::save($machine,'prefer_ipv6',[(string)$node->id=>'prefer_ipv4']);
        $this->assertSame('prefer_ipv4',MachineEgress::forNode($node));
        $oldId=$node->id;
        $node->delete();
        $node=Server::create(['name'=>'replacement','type'=>'vmess','host'=>'example.test','port'=>443,'server_port'=>443,
            'rate'=>'1','machine_id'=>$machine->id,'enabled'=>true,'show'=>true]);
        $this->assertSame($oldId,$node->id);
        $this->assertSame('prefer_ipv6',MachineEgress::forNode($node));
        $this->assertSame('inherit',MachineEgress::settings($machine)['nodes'][0]['mode']);
        try {
            MachineEgress::save($machine,'prefer_ipv4',[(string)$node->id=>'prefer_ipv4'],(string)$machine->onenew_identity,[(string)$oldId=>'stale-generation']);
            $this->fail('Stale edit window wrote a replacement node policy');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $expected) { $this->assertSame(409,$expected->getStatusCode()); }
        $machine->delete();
        $this->assertFalse(DB::table('v2_server_machine_egress')->where('machine_id',$machine->id)->exists());
    }
}
