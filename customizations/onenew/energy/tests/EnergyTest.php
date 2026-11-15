<?php
namespace Tests\Feature;
use App\Models\User;
use App\Services\AuthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;
final class EnergyTest extends TestCase
{
    use RefreshDatabase;
    private function account(bool $admin=false): User {return User::create(['email'=>Str::random(16).'@example.invalid','password'=>password_hash('fixture-pass-1234',PASSWORD_DEFAULT),'uuid'=>(string)Str::uuid(),'token'=>Str::random(32),'is_admin'=>$admin,'balance'=>0,'transfer_enable'=>100000,'u'=>0,'d'=>0]);}
    private function auth(User $u): array {$this->app['auth']->forgetGuards();return ['Authorization'=>(new AuthService($u))->generateAuthData()['auth_data']];}
    private function submit(User $u,string $amount='12.34',string $key='aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa'): string {return $this->postJson('/api/onenew/user/energy',['amount'=>$amount,'request_id'=>$key],$this->auth($u))->assertOk()->json('id');}
    public function test_requests_do_not_credit_balance_and_retries_are_idempotent(): void {
        $u=$this->account();$id=$this->submit($u);self::assertSame($id,$this->submit($u));self::assertSame(0,(int)$u->fresh()->balance);self::assertSame(1,DB::table('onenew_energy_requests')->count());
        $this->postJson('/api/onenew/user/energy',['amount'=>'2','request_id'=>str_repeat('a',32)],$this->auth($u))->assertStatus(409);
        $this->getJson('/api/onenew/user/energy',$this->auth($u))->assertOk()->assertJsonPath('data.0.amount_cents',1234)->assertJsonMissingPath('data.0.request_hash');
    }
    public function test_approval_credits_native_balance_once_and_records_auditor(): void {
        $u=$this->account();$a=$this->account(true);$id=$this->submit($u);
        foreach([1,2] as $_)$this->postJson('/api/onenew/admin/energy/'.$id.'/review',['decision'=>'approved','note'=>'fixture'],$this->auth($a))->assertOk();
        self::assertSame(1234,(int)$u->fresh()->balance);$r=DB::table('onenew_energy_requests')->where('id',$id)->first();self::assertSame($a->id,(int)$r->reviewer_id);self::assertSame(0,(int)$r->balance_before);self::assertSame(1234,(int)$r->balance_after);
        $this->postJson('/api/onenew/admin/energy/'.$id.'/review',['decision'=>'rejected'],$this->auth($a))->assertStatus(409);
    }
    public function test_permissions_and_ownership_are_enforced(): void {
        $u=$this->account();$other=$this->account();$id=$this->submit($u);
        $this->getJson('/api/onenew/admin/energy',$this->auth($u))->assertStatus(403);
        $this->postJson('/api/onenew/admin/energy/'.$id.'/review',['decision'=>'approved'],$this->auth($u))->assertStatus(403);
        $this->postJson('/api/onenew/user/energy/'.$id.'/cancel',[],$this->auth($other))->assertStatus(404);
        $this->getJson('/api/onenew/user/energy',$this->auth($other))->assertOk()->assertJsonPath('total',0);
    }
    public function test_cancel_and_rejection_do_not_credit_and_cannot_later_approve(): void {
        $u=$this->account();$a=$this->account(true);$id=$this->submit($u);$this->postJson('/api/onenew/user/energy/'.$id.'/cancel',[],$this->auth($u))->assertOk();
        $this->postJson('/api/onenew/admin/energy/'.$id.'/review',['decision'=>'approved'],$this->auth($a))->assertStatus(409);
        $id=$this->submit($u,'3',str_repeat('b',32));$this->postJson('/api/onenew/admin/energy/'.$id.'/review',['decision'=>'rejected'],$this->auth($a))->assertOk();self::assertSame(0,(int)$u->fresh()->balance);
    }
    public function test_invalid_amounts_and_balance_overflow_do_not_credit(): void {
        $u=$this->account();foreach(['0','-1','1.001','1e3','1000000.01'] as $amount)$this->postJson('/api/onenew/user/energy',['amount'=>$amount,'request_id'=>str_repeat('a',32)],$this->auth($u))->assertStatus(422);
        $id=$this->submit($u);$u->balance=2147483647;$u->save();$this->postJson('/api/onenew/admin/energy/'.$id.'/review',['decision'=>'approved'],$this->auth($this->account(true)))->assertStatus(422);self::assertSame(2147483647,(int)$u->fresh()->balance);
    }
}
