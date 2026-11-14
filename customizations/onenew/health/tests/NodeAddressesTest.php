<?php
namespace Tests\Feature\Onenew;
use App\Models\Server;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class NodeAddressesTest extends TestCase
{
    use RefreshDatabase;
    private const URL='/api/onenew/admin/node-addresses';
    private function node(string $host='192.0.2.1'): Server
    {
        return Server::create(['name'=>'node','type'=>'vless','host'=>$host,'port'=>'443','server_port'=>25000,
            'rate'=>'1','protocol_settings'=>['network'=>'tcp','tls'=>0],'enabled'=>true]);
    }
    private function admin(): void
    {
        Sanctum::actingAs(User::create(['email'=>'address@example.test','password'=>'unused','is_admin'=>true,
            'uuid'=>(string)\Illuminate\Support\Str::uuid(),'token'=>bin2hex(random_bytes(16))]));
    }
    public function test_preview_is_admin_only_and_apply_changes_only_selected_public_endpoint(): void
    {
        $a=$this->node();$b=$this->node();$other=$this->node('192.0.2.2');
        $this->getJson(self::URL.'?ip=192.0.2.1')->assertForbidden();$this->admin();
        $rows=$this->getJson(self::URL.'?ip=192.0.2.1')->assertOk()->json('nodes');
        $this->assertCount(2,$rows);
        $this->postJson(self::URL,['source_ip'=>'192.0.2.1','target_ip'=>'2001:db8::2','target_port'=>8443,
            'nodes'=>[$rows[0]]])->assertOk()->assertJsonPath('updated',1);
        $this->assertSame('2001:db8::2',$a->fresh()->host);
        $this->assertSame('8443',(string)$a->fresh()->port);
        $this->assertSame(25000,(int)$a->fresh()->server_port);
        $this->assertSame('192.0.2.1',$b->fresh()->host);
        $this->assertSame('192.0.2.2',$other->fresh()->host);
    }
    public function test_stale_batch_is_rejected_atomically_and_port_only_keeps_ip(): void
    {
        $a=$this->node();$b=$this->node();$this->admin();
        $rows=$this->getJson(self::URL.'?ip=192.0.2.1')->json('nodes');
        $b->update(['port'=>'444']);
        $this->postJson(self::URL,['source_ip'=>'192.0.2.1','target_ip'=>'192.0.2.3','nodes'=>$rows])->assertStatus(409);
        $this->assertSame('192.0.2.1',$a->fresh()->host);
        $rows=$this->getJson(self::URL.'?ip=192.0.2.1')->json('nodes');
        $this->postJson(self::URL,['source_ip'=>'192.0.2.1','target_port'=>8443,'nodes'=>$rows])->assertOk();
        $this->assertSame('192.0.2.1',$a->fresh()->host);
        $this->assertSame('8443',(string)$b->fresh()->port);
    }
    public function test_reused_node_id_and_invalid_target_cannot_be_modified_from_old_preview(): void
    {
        $node=$this->node();$this->admin();$rows=$this->getJson(self::URL.'?ip=192.0.2.1')->json('nodes');
        $node->delete();$replacement=$this->node();$this->assertSame($node->id,$replacement->id);
        $this->postJson(self::URL,['source_ip'=>'192.0.2.1','target_port'=>80,'nodes'=>$rows])->assertStatus(409);
        $rows=$this->getJson(self::URL.'?ip=192.0.2.1')->json('nodes');
        $this->postJson(self::URL,['source_ip'=>'192.0.2.1','target_port'=>65536,'nodes'=>$rows])->assertUnprocessable();
        $this->assertSame('443',(string)$replacement->fresh()->port);
    }
    public function test_ipv6_search_recognizes_equivalent_addresses(): void
    {
        $this->node('2001:db8::1');$this->admin();
        $this->getJson(self::URL.'?ip=2001%3Adb8%3A0%3A0%3A0%3A0%3A0%3A1')->assertOk()->assertJsonCount(1,'nodes');
    }
}
