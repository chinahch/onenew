<?php
namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\Server;
use App\Onenew\AiOperations\OperatorActions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;

final class OperatorActionsTest extends TestCase
{
    use RefreshDatabase;
    private function user(): User { return User::create(['email'=>Str::random(12).'@example.test','password'=>'hashed','uuid'=>(string)Str::uuid(),'token'=>Str::random(32),'expired_at'=>time()+86400,'balance'=>123])->fresh(); }
    public function test_prepare_is_read_only_extend_preserves_account_and_executes_once(): void
    {
        $u=$this->user();$before=$u->getRawOriginal();$a=app(OperatorActions::class);
        $card=$a->prepare(1,'user.extend',['user_id'=>$u->id,'amount'=>3,'unit'=>'days']);
        self::assertSame($before,$u->fresh()->getRawOriginal());
        $request=(string)Str::uuid();$result=$a->execute(1,$card['id'],$request);
        self::assertSame($result,$a->execute(1,$card['id'],$request));
        $after=$u->fresh()->getRawOriginal();self::assertSame((int)$before['expired_at']+259200,(int)$after['expired_at']);
        foreach(['uuid','token','balance','plan_id','transfer_enable','u','d','password'] as $key) self::assertSame($before[$key]??null,$after[$key]??null);
        self::assertSame(1,DB::table('onenew_ai_action_audit')->count());
    }
    public function test_changed_target_and_other_admin_cannot_execute(): void
    {
        $u=$this->user();$a=app(OperatorActions::class);$card=$a->prepare(1,'user.extend',['user_id'=>$u->id,'amount'=>1,'unit'=>'hours']);
        try {$a->execute(2,$card['id'],(string)Str::uuid());self::fail();} catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {self::assertSame(404,$e->getStatusCode());}
        $u->expired_at+=60;$u->save();
        try {$a->execute(1,$card['id'],(string)Str::uuid());self::fail();} catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {self::assertSame(409,$e->getStatusCode());}
    }
    public function test_create_uses_secure_local_password_and_no_role_grant(): void
    {
        $a=app(OperatorActions::class);$email='new-admin-created@example.test';$card=$a->prepare(1,'user.create',['email'=>$email,'days'=>10]);
        self::assertFalse(User::where('email',$email)->exists());self::assertArrayNotHasKey('credentials',$card);self::assertArrayNotHasKey('password',$card['after']);
        $result=$a->execute(1,$card['id'],(string)Str::uuid());$u=User::where('email',$email)->firstOrFail();
        self::assertTrue(password_verify($result['credentials']['password'],$u->password));self::assertFalse((bool)$u->is_admin);
        self::assertStringNotContainsString($result['credentials']['password'],json_encode(DB::table('onenew_ai_action_audit')->first()));
        self::assertArrayNotHasKey('credentials',json_decode(\Illuminate\Support\Facades\Crypt::decryptString(DB::table('onenew_ai_action_audit')->value('encrypted_result')),true));
        self::assertStringNotContainsString($email,json_encode($a->read('users.lookup',['user_id'=>$u->id])));
    }
    public function test_unlimited_expiry_and_unknown_fields_are_rejected(): void
    {
        $u=$this->user();$u->expired_at=null;$u->save();
        $this->expectException(\Illuminate\Validation\ValidationException::class);
        app(OperatorActions::class)->prepare(1,'user.extend',['user_id'=>$u->id,'amount'=>1,'unit'=>'days']);
    }
    public function test_model_supplied_password_and_privilege_are_rejected(): void
    {
        $this->expectException(\Illuminate\Validation\ValidationException::class);
        app(OperatorActions::class)->prepare(1,'user.create',['email'=>'example@example.test','password'=>'injected-password','is_admin'=>true]);
    }
    public function test_live_traffic_and_login_changes_do_not_invalidate_duration_confirmation(): void
    {
        $u=$this->user();$a=app(OperatorActions::class);$card=$a->prepare(1,'user.extend',['user_id'=>$u->id,'amount'=>1,'unit'=>'days']);
        DB::table('v2_user')->where('id',$u->id)->update(['u'=>123,'d'=>456,'balance'=>999,'last_login_at'=>time(),'updated_at'=>time()+1]);
        $a->execute(1,$card['id'],(string)Str::uuid());$u->refresh();self::assertSame(123,(int)$u->u);self::assertSame(999,(int)$u->balance);
    }
    public function test_machine_heartbeat_and_node_traffic_do_not_invalidate_copy(): void
    {
        $machine=\App\Models\ServerMachine::create(['name'=>'Heartbeat machine','token'=>Str::random(32)]);
        $node=Server::create(['name'=>'Template live','type'=>'shadowsocks','host'=>'example.test','port'=>443,'server_port'=>443,'rate'=>1,'group_ids'=>[],'protocol_settings'=>['cipher'=>'aes-128-gcm']]);
        $a=app(OperatorActions::class);$card=$a->prepare(1,'node.create',['template_node_id'=>$node->id,'machine_id'=>$machine->id,'name'=>'Heartbeat clone','host'=>'clone.example.test','port'=>8443,'server_port'=>8443]);
        self::assertFalse($card['after']['enabled']);self::assertStringContainsString('disabled draft',$card['after']['effect']);
        DB::table('v2_server_machine')->where('id',$machine->id)->update(['last_seen_at'=>time(),'load_status'=>json_encode(['cpu'=>12]),'updated_at'=>time()+1]);
        DB::table('v2_server')->where('id',$node->id)->update(['u'=>100,'d'=>200,'updated_at'=>time()+1]);
        $r=$a->execute(1,$card['id'],(string)Str::uuid());self::assertNotNull(Server::find($r['target_id']));
    }
    public function test_default_trial_plan_and_expiry_are_frozen_in_confirmation(): void
    {
        $old=['try_out_plan_id'=>admin_setting('try_out_plan_id',0),'try_out_hour'=>admin_setting('try_out_hour',1)];
        try {
            $plan=\App\Models\Plan::create(['name'=>'Trial fixture','transfer_enable'=>10,'show'=>true,'sell'=>false,'renew'=>false,'prices'=>[]]);admin_setting(['try_out_plan_id'=>$plan->id,'try_out_hour'=>12]);
            $a=app(OperatorActions::class);$card=$a->prepare(1,'user.create',['email'=>'trial-confirm@example.test']);self::assertSame($plan->id,$card['after']['plan_id']);self::assertNotNull($card['after']['expiry']);
            $this->travel(60)->seconds();$r=$a->execute(1,$card['id'],(string)Str::uuid());$u=User::findOrFail($r['target_id']);
            self::assertSame($plan->id,(int)$u->plan_id);self::assertSame(10*1073741824,(int)$u->transfer_enable);
            self::assertSame($card['after']['expiry'],\Carbon\CarbonImmutable::createFromTimestamp((int)$u->expired_at,config('app.timezone','UTC'))->format('Y-m-d H:i:s T (P)'));
        } finally {$this->travelBack();admin_setting($old);}
    }
    public function test_failed_native_creation_rolls_back_audit_and_user(): void
    {
        $a=app(OperatorActions::class);$card=$a->prepare(1,'user.create',['email'=>'rollback@example.test']);
        $mock=\Mockery::mock(\App\Services\UserService::class);$mock->shouldReceive('createUser')->once()->andThrow(new \RuntimeException('Native validation failed'));$this->app->instance(\App\Services\UserService::class,$mock);
        try {$a->execute(1,$card['id'],(string)Str::uuid());self::fail();} catch(\RuntimeException $e) {self::assertSame('Native validation failed',$e->getMessage());}
        self::assertFalse(User::where('email','rollback@example.test')->exists());self::assertSame(0,DB::table('onenew_ai_action_audit')->count());
    }
    public function test_node_copy_clears_bindings_and_reused_identity_fails(): void
    {
        $node=Server::create(['name'=>'Template','type'=>'shadowsocks','host'=>'example.test','port'=>443,'server_port'=>443,'rate'=>1,'group_ids'=>[],'protocol_settings'=>['cipher'=>'aes-128-gcm']]);
        $a=app(OperatorActions::class);$card=$a->prepare(1,'node.create',['template_node_id'=>$node->id,'name'=>'Clone','host'=>'clone.example.test','port'=>8443,'server_port'=>8443]);
        $result=$a->execute(1,$card['id'],(string)Str::uuid());$copy=Server::findOrFail($result['target_id']);
        foreach(['route_ids','custom_routes','custom_outbounds','onenew_landing_bindings'] as $field) self::assertEmpty($copy->$field);
        $delete=$a->prepare(1,'node.delete',['node_id'=>$copy->id]);DB::table('v2_server')->where('id',$copy->id)->update(['onenew_identity'=>(string)Str::uuid()]);
        try {$a->execute(1,$delete['id'],(string)Str::uuid());self::fail();} catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {self::assertSame(409,$e->getStatusCode());}
        self::assertNotNull($copy->fresh());
    }
    private function realityTemplate(): Server
    {
        $machine=\App\Models\ServerMachine::create(['name'=>'Reality machine','token'=>Str::random(32)]);
        return Server::create(['name'=>'Node 45','custom_routes'=>[['outbound'=>'template-exit']],'custom_outbounds'=>[['tag'=>'template-exit','protocol'=>'direct']],'onenew_landing_bindings'=>[['landing_id'=>7,'global'=>true,'domains'=>[]]],'type'=>'vless','machine_id'=>$machine->id,'host'=>'example.test','port'=>25051,'server_port'=>25051,'rate'=>1,'group_ids'=>[],'protocol_settings'=>['tls'=>2,'network'=>'tcp','flow'=>'xtls-rprx-vision','reality_settings'=>['server_name'=>'example.com','server_port'=>443,'private_key'=>'template-private','public_key'=>'template-public','short_id'=>'1122334455667788']]]);
    }
    public function test_reality_copy_generates_independent_keys_preserves_template_and_enables_only_explicitly(): void
    {
        $template=$this->realityTemplate();$before=$template->getRawOriginal();$a=app(OperatorActions::class);$keys=[];
        foreach([25052,25053] as $port){
            $card=$a->prepare(1,'node.create',['template_node_id'=>$template->id,'name'=>'Copy '.$port,'host'=>'copy.test','port'=>$port,'server_port'=>$port,'enabled'=>true]);
            self::assertArrayNotHasKey('private_key',$card['after']['reality']);self::assertStringNotContainsString('template-private',json_encode($card));
            $r=$a->execute(1,$card['id'],(string)Str::uuid());$copy=Server::findOrFail($r['target_id']);$settings=$copy->protocol_settings;$reality=$settings['reality_settings'];
            self::assertTrue($copy->enabled);self::assertSame('saved_configuration_queued',$r['deployment_status']);
            self::assertNotSame('template-private',$reality['private_key']);self::assertSame($card['after']['reality']['public_key'],$reality['public_key']);
            $private=base64_decode(strtr($reality['private_key'],'-_','+/'));self::assertSame(32,strlen($private));
            self::assertSame(rtrim(strtr(base64_encode(sodium_crypto_scalarmult_base($private)),'+/','-_'),'='),$reality['public_key']);
            self::assertMatchesRegularExpression('/^[a-f0-9]{16}$/',$reality['short_id']);self::assertNotContains($reality['public_key'],$keys);$keys[]=$reality['public_key'];
            self::assertStringNotContainsString($reality['private_key'],json_encode($card));self::assertStringNotContainsString($reality['private_key'],\Illuminate\Support\Facades\Cache::get('onenew:ai:action:1:'.$card['id']));self::assertSame('example.com',$reality['server_name']);self::assertSame('xtls-rprx-vision',$settings['flow']);
            foreach(['route_ids','custom_routes','custom_outbounds','onenew_landing_bindings'] as $field)self::assertEmpty($copy->$field);
        }
        self::assertSame($before,$template->fresh()->getRawOriginal());
    }
    public function test_node_copy_rejects_conflicting_listen_port_on_machine_including_disabled_drafts(): void
    {
        $node=$this->realityTemplate();$this->expectException(\Illuminate\Validation\ValidationException::class);
        app(OperatorActions::class)->prepare(1,'node.create',['template_node_id'=>$node->id,'name'=>'Collision','host'=>'different.test','port'=>443,'server_port'=>25051]);
    }
    public function test_node_copy_rechecks_port_conflict_at_execution(): void
    {
        $node=$this->realityTemplate();$a=app(OperatorActions::class);$card=$a->prepare(1,'node.create',['template_node_id'=>$node->id,'name'=>'Copy','host'=>'copy.test','port'=>25052,'server_port'=>25052]);
        $other=$node->replicate();$other->name='Draft competitor';$other->server_port=25052;$other->enabled=false;$other->saveOrFail();
        try {$a->execute(1,$card['id'],(string)Str::uuid());self::fail('Port collision must fail');}catch(\Illuminate\Validation\ValidationException $e){self::assertArrayHasKey('server_port',$e->errors());}
        self::assertFalse(Server::where('name','Copy')->exists());self::assertSame(0,DB::table('onenew_ai_action_audit')->count());
    }

}
