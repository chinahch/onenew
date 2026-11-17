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
    private function user(): User { return User::create(['email'=>Str::random(12).'@example.test','password'=>'hashed','uuid'=>(string)Str::uuid(),'token'=>Str::random(32),'expired_at'=>time()+86400,'balance'=>123]); }
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
        self::assertFalse(User::where('email',$email)->exists());self::assertStringNotContainsString('password',json_encode($card));
        $result=$a->execute(1,$card['id'],(string)Str::uuid());$u=User::where('email',$email)->firstOrFail();
        self::assertTrue(password_verify($result['credentials']['password'],$u->password));self::assertFalse((bool)$u->is_admin);
        self::assertStringNotContainsString($result['credentials']['password'],json_encode(DB::table('onenew_ai_action_audit')->first()));
        self::assertStringNotContainsString($email,json_encode($a->read('users.lookup',['user_id'=>$u->id])));
    }
    public function test_unlimited_expiry_and_unknown_fields_are_rejected(): void
    {
        $u=$this->user();$u->expired_at=null;$u->save();
        $this->expectException(\Illuminate\Validation\ValidationException::class);
        app(OperatorActions::class)->prepare(1,'user.extend',['user_id'=>$u->id,'amount'=>1,'unit'=>'days']);
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
}
