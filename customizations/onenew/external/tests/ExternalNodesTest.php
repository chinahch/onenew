<?php
namespace Tests\Feature\Onenew;

use App\Http\Controllers\V1\Client\ClientController;
use App\Models\Server;
use App\Models\User;
use App\Onenew\External\ExternalNodes;
use App\Onenew\External\ShareLink;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

class ExternalNodesTest extends TestCase
{
    use RefreshDatabase;
    private const ROOT = '/api/onenew/admin/external-nodes';
    private const UUID = '11111111-2222-4333-8444-555555555555';

    private function user(bool $admin=false,int $group=1): User
    {
        return User::create(['email'=>bin2hex(random_bytes(6)).'@example.test','password'=>'unused',
            'uuid'=>(string)\Illuminate\Support\Str::uuid(),'token'=>bin2hex(random_bytes(16)),
            'is_admin'=>$admin,'group_id'=>$group,'transfer_enable'=>1000000,'u'=>0,'d'=>0,'banned'=>0,'expired_at'=>null]);
    }
    private function link(string $name='External'): string { return 'vless://'.self::UUID.'@external.example:443?encryption=none&security=none&type=tcp&headerType=none#'.$name; }
    private function save(User $user,string $link,string $name='External'): int
    {
        return $this->postJson(self::ROOT.'/save',['link'=>$link,'name'=>$name,'enabled'=>true,
            'user_ids'=>[$user->id],'group_ids'=>[]])->assertOk()->json('id');
    }
    private function subscribe(User $user,string $flag): mixed
    {
        $request=Request::create('/api/v1/client/subscribe','GET',['flag'=>$flag]);
        $request->setUserResolver(fn()=>$user);
        return app(ClientController::class)->subscribe($request);
    }

    public function test_administration_requires_admin_and_batch_import_is_atomic_and_encrypted(): void
    {
        $this->getJson(self::ROOT)->assertForbidden();
        $user=$this->user(); Sanctum::actingAs($user); $this->getJson(self::ROOT)->assertForbidden();
        Sanctum::actingAs($this->user(true));
        $this->postJson(self::ROOT.'/import',['links'=>$this->link()."\ninvalid",'user_ids'=>[$user->id],'group_ids'=>[]])->assertUnprocessable();
        $this->assertDatabaseCount(ExternalNodes::TABLE,0);
        $id=$this->save($user,$this->link());
        $stored=DB::table(ExternalNodes::TABLE)->find($id);
        $this->assertStringNotContainsString(self::UUID,$stored->link_ciphertext);
        $this->assertStringNotContainsString(self::UUID,$this->getJson(self::ROOT)->assertOk()->getContent());
        $this->getJson(self::ROOT.'/'.$id)->assertOk()->assertJsonPath('link',$this->link());
    }

    public function test_users_or_groups_match_but_empty_assignments_and_unrelated_users_cannot_subscribe(): void
    {
        $group=DB::table('v2_server_group')->insertGetId(['name'=>'External group','created_at'=>time(),'updated_at'=>time()]);
        $direct=$this->user(false,99);$member=$this->user(false,$group);$other=$this->user(false,100);
        Sanctum::actingAs($this->user(true));
        $this->postJson(self::ROOT.'/save',['link'=>$this->link(),'name'=>'Shared','enabled'=>true,'user_ids'=>[$direct->id],'group_ids'=>[$group]])->assertOk();
        $this->postJson(self::ROOT.'/save',['link'=>$this->link('Nobody'),'name'=>'Nobody','enabled'=>true,'user_ids'=>[],'group_ids'=>[]])->assertOk();
        $this->assertCount(1,ExternalNodes::append([],$direct));
        $this->assertCount(1,ExternalNodes::append([],$member));
        $this->assertCount(0,ExternalNodes::append([],$other));
        $this->assertDatabaseCount('v2_server',0);
    }

    public function test_native_general_mihomo_and_singbox_preserve_external_credentials(): void
    {
        $user=$this->user();Sanctum::actingAs($this->user(true));
        $this->save($user,$this->link());
        $this->save($user,'socks://'.rtrim(base64_encode('alice:independent-password'),'=').'@socks.example:1080#SOCKS','SOCKS');
        Server::create(['name'=>'Managed','type'=>'vless','host'=>'managed.example','port'=>443,'server_port'=>443,
            'rate'=>'1','group_ids'=>[(string)$user->group_id],'enabled'=>true,'show'=>true,'protocol_settings'=>['network'=>'tcp','tls'=>0]]);
        $general=$this->subscribe($user,'v2ray');$this->assertSame(200,$general->getStatusCode());
        $text=base64_decode($general->getContent());
        $this->assertStringContainsString(self::UUID,$text);$this->assertStringContainsString($user->uuid,$text);
        $clash=Yaml::parse($this->subscribe($user,'meta')->getContent());
        $vless=collect($clash['proxies'])->firstWhere('server','external.example');
        $socks=collect($clash['proxies'])->firstWhere('server','socks.example');
        $this->assertSame(self::UUID,$vless['uuid']);$this->assertSame('alice',$socks['username']);
        $this->assertSame('independent-password',$socks['password']);
        $sing=json_decode($this->subscribe($user,'sing-box')->getContent(),true);
        $vless=collect($sing['outbounds'])->firstWhere('server','external.example');
        $socks=collect($sing['outbounds'])->firstWhere('server','socks.example');
        $this->assertSame(self::UUID,$vless['uuid']);$this->assertSame('alice',$socks['username']);
        $this->assertSame('independent-password',$socks['password']);
        $user->update(['expired_at'=>time()-60]);
        $expired=$this->subscribe($user,'v2ray');
        $this->assertSame(200,$expired->getStatusCode());
        $this->assertSame(\App\Onenew\External\ExpiredSubscription::LINK."\r\n",base64_decode($expired->getContent()));
        $expiredSing=json_decode($this->subscribe($user,'sing-box')->getContent(),true);
        $proxies=collect($expiredSing['outbounds'])->filter(fn($row)=>isset($row['server']))->values();
        $this->assertCount(1,$proxies);
        $this->assertSame('127.0.0.1',$proxies[0]['server']);
        $this->assertSame(65535,$proxies[0]['server_port']);
    }

    public function test_edit_disable_delete_and_sort_affect_next_subscription(): void
    {
        $user=$this->user();Sanctum::actingAs($this->user(true));
        $a=$this->save($user,$this->link('A'),'A');$b=$this->save($user,$this->link('B'),'B');
        $this->postJson(self::ROOT.'/order',['ids'=>[$b,$a]])->assertOk();
        $this->assertSame(['B','A'],array_column(ExternalNodes::append([],$user),'name'));
        $this->postJson(self::ROOT.'/save',['id'=>$b,'link'=>$this->link('Edited'),'name'=>'Edited','enabled'=>false,'user_ids'=>[$user->id],'group_ids'=>[]])->assertOk();
        $this->assertSame(['A'],array_column(ExternalNodes::append([],$user),'name'));
        $this->postJson(self::ROOT.'/order',['ids'=>[$a]])->assertStatus(409);
        $this->postJson(self::ROOT.'/delete',['id'=>$a])->assertOk();
        $this->assertSame([],ExternalNodes::append([],$user));
    }

    public function test_unknown_parameters_survive_general_but_conversion_fails_explicitly(): void
    {
        $link=str_replace('#External','&unknown=retain#External',$this->link());
        $this->assertStringContainsString('unknown=retain',ShareLink::renamed($link,'New name'));
        $this->expectException(\InvalidArgumentException::class);
        ShareLink::convert($link,'singbox','New name');
    }
}
