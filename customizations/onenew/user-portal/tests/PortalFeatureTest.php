<?php
namespace Tests\Feature;

use App\Models\InviteCode;
use App\Models\Order;
use App\Models\Plan;
use App\Models\StatUser;
use App\Models\User;
use App\Services\AuthService;
use App\Services\OrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Tests\TestCase;

final class PortalFeatureTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('app.key','base64:'.base64_encode(str_repeat('q',32)));
        config()->set('app.timezone','UTC');
        config()->set('cache.default','array');
        config()->set('cache.stores.redis.driver','array');
        Cache::purge('redis');
        // Setting captures its named repository when constructed during app boot.
        // Re-resolve the real service after the test repository configuration.
        $this->app->forgetInstance(\App\Support\Setting::class);
        admin_setting(['captcha_enable'=>0,'email_verify'=>0,'stop_register'=>0,'try_out_plan_id'=>0,'invite_force'=>0,'invite_never_expire'=>0]);
    }

    private function account(bool $admin=false): User
    {
        return User::create(['email'=>'qa-'.Str::random(12).'@example.invalid','password'=>password_hash(Str::random(32),PASSWORD_DEFAULT),
            'uuid'=>(string)Str::uuid(),'token'=>Str::random(32),'is_admin'=>$admin,'balance'=>0,'transfer_enable'=>107374182400,'u'=>100,'d'=>200,'expired_at'=>time()+86400*30]);
    }

    private function headersFor(User $user): array
    {
        $this->app['auth']->forgetGuards();
        return ['Authorization'=>(new AuthService($user))->generateAuthData()['auth_data']];
    }

    public function test_account_apis_require_native_authentication(): void
    {
        foreach(['me','subscription','orders','renewal-plan','renewals','invite','usage'] as $path){
            $this->app['auth']->forgetGuards();
            $this->getJson('/api/onenew/user/'.$path)->assertStatus(403);
        }
    }

    public function test_native_identity_stable_absolute_subscribe_and_current_only_logout(): void
    {
        admin_setting(['subscribe_url'=>'https://qa-a.example.invalid,https://qa-b.example.invalid']);
        $user=$this->account();$headers=$this->headersFor($user);
        $otherToken=$user->createToken('qa-other')->plainTextToken;
        $this->getJson('/api/onenew/user/me',$headers)->assertOk()->assertJsonPath('user.email',$user->email)->assertJsonPath('user.used_bytes',300)->assertJsonMissingPath('user.admin_path');
        $first=$this->getJson('/api/onenew/user/subscription',$headers)->assertOk()->json('path');
        $second=$this->getJson('/api/onenew/user/subscription',$headers)->assertOk()->json('path');
        self::assertSame($first,$second);self::assertStringContainsString($user->token,$first);
        self::assertStringNotContainsString($user->token,DB::table('onenew_user_subscription_links')->where('user_id',$user->id)->value('encrypted_url'));
        $rotated=$this->postJson('/api/onenew/user/subscription-token',[],$headers)->assertOk()->json('path');
        self::assertNotSame($first,$rotated);self::assertStringContainsString($user->fresh()->token,$rotated);
        $this->getJson('/api/onenew/user/subscription',$headers)->assertOk()->assertJsonPath('path',$rotated);
        $this->postJson('/api/onenew/user/logout',[],$headers)->assertOk();
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/onenew/user/me',$headers)->assertStatus(403);
        self::assertNotNull(\Laravel\Sanctum\PersonalAccessToken::findToken($otherToken));
    }

    public function test_admin_profile_links_to_existing_secure_path(): void
    {
        admin_setting(['secure_path'=>'qa-secure-admin']);$admin=$this->account(true);
        $this->getJson('/api/onenew/user/me',$this->headersFor($admin))->assertOk()->assertJsonPath('user.admin_path','/qa-secure-admin')->assertJsonPath('user.role','user');
    }

    public function test_renewal_uses_native_pending_order_and_durable_idempotency(): void
    {
        $plan=Plan::create(['name'=>'QA renewal','transfer_enable'=>100,'show'=>true,'sell'=>true,'renew'=>true,'prices'=>['monthly'=>19.99,'quarterly'=>55]]);
        $nativeUser=$this->account();$nativeUser->plan_id=$plan->id;$nativeUser->save();
        $nativeOrder=OrderService::createFromRequest($nativeUser,$plan,'monthly');
        $this->assertSame($nativeOrder->total_amount,\App\Onenew\PortalData::plan($plan)['periods'][0]['price_cents']);
        $user=$this->account();$user->plan_id=$plan->id;$user->save();$expiry=$user->expired_at;
        $headers=$this->headersFor($user);$body=['request_id'=>str_repeat('a',32),'period'=>'monthly'];
        $first=$this->postJson('/api/onenew/user/renewals',$body,$headers)->assertOk()->assertJsonPath('status','pending')->assertJsonPath('price_cents',$nativeOrder->total_amount)->json('id');
        $this->postJson('/api/onenew/user/renewals',$body,$headers)->assertOk()->assertJsonPath('id',$first);
        self::assertSame(1,Order::where('user_id',$user->id)->count());self::assertSame($expiry,$user->fresh()->expired_at);
        Order::where('trade_no',$first)->update(['status'=>3]);
        $this->postJson('/api/onenew/user/renewals',$body,$headers)->assertOk()->assertJsonPath('id',$first)->assertJsonPath('status','approved');
        self::assertSame(1,Order::where('user_id',$user->id)->count());
        $this->postJson('/api/onenew/user/renewals',[...$body,'period'=>'quarterly'],$headers)->assertStatus(409);
    }

    public function test_sufficient_energy_activates_renewal_once_and_insufficient_energy_stays_pending(): void
    {
        $plan=Plan::create(['name'=>'Energy renewal','transfer_enable'=>100,'show'=>true,'sell'=>true,'renew'=>true,'prices'=>['monthly'=>20]]);
        foreach ([2500,2000,500] as $balance) {
            $user=$this->account();$user->plan_id=$plan->id;$user->balance=$balance;$user->save();$expiry=$user->expired_at;
            $headers=$this->headersFor($user);$body=['request_id'=>bin2hex(random_bytes(16)),'period'=>'monthly'];
            $result=$this->postJson('/api/onenew/user/renewals',$body,$headers)->assertOk()
                ->assertJsonPath('status',$balance>=2000?'approved':'pending');
            $trade=$result->json('id');$remaining=$user->fresh()->balance;$newExpiry=$user->fresh()->expired_at;
            $this->assertEquals(max(0,$balance-2000),$remaining);
            if ($balance>=2000) $this->assertGreaterThan($expiry,$newExpiry); else $this->assertEquals($expiry,$newExpiry);
            $this->postJson('/api/onenew/user/renewals',$body,$headers)->assertOk()->assertJsonPath('id',$trade);
            $this->assertEquals($remaining,$user->fresh()->balance);$this->assertEquals($newExpiry,$user->fresh()->expired_at);
            $this->assertEquals(1,Order::where('user_id',$user->id)->count());
        }
    }

    public function test_imported_alphanumeric_subscription_survives_paid_renewal_without_rotating_credentials(): void
    {
        admin_setting(['subscribe_url'=>'https://subscription.example.test']);
        $plan=Plan::create(['name'=>'Legacy renewal','transfer_enable'=>100,'show'=>true,'sell'=>true,'renew'=>true,'prices'=>['monthly'=>20]]);
        $user=$this->account();$token=str_repeat('AbG9',8);$user->token=$token;$user->plan_id=$plan->id;$user->balance=2000;$user->expired_at=time()-60;$user->save();
        $uuid=$user->uuid;$headers=$this->headersFor($user);
        $link=$this->getJson('/api/onenew/user/subscription',$headers)->assertOk()->json('path');
        $path=parse_url($link,PHP_URL_PATH);$this->assertStringEndsWith('/'.$token,$path);
        $this->postJson('/api/onenew/user/renewals',['request_id'=>str_repeat('c',32),'period'=>'monthly'],$headers)->assertOk()->assertJsonPath('status','approved');
        $fresh=$user->fresh();$this->assertSame($token,$fresh->token);$this->assertSame($uuid,$fresh->uuid);$this->assertGreaterThan(time(),$fresh->expired_at);
        $this->getJson('/api/onenew/user/subscription',$headers)->assertOk()->assertJsonPath('path',$link);
        $this->app['auth']->forgetGuards();$this->get($path)->assertOk();
        // An explicit security reset still revokes the old URL; no compatibility alias is created.
        $this->postJson('/api/onenew/user/subscription-token',[],$headers)->assertOk();$this->app['auth']->forgetGuards();$this->get($path)->assertStatus(403);
    }

    public function test_subscription_tokens_remain_strictly_bounded_and_case_sensitive(): void
    {
        $legacy=str_repeat('AbG9',8);$this->assertSame($legacy,\App\Utils\Helper::validateSubscriptionToken($legacy));
        $hex=str_repeat('a9',16);$this->assertSame($hex,\App\Utils\Helper::validateSubscriptionToken($hex));
        foreach([null,[],42,'',str_repeat('a',31),str_repeat('a',33),str_repeat('a',31)."\n",str_repeat('a',31).'-',str_repeat('a',31)."'",str_repeat('a',31).'中'] as $bad)$this->assertNull(\App\Utils\Helper::validateSubscriptionToken($bad));
        $user=$this->account();$user->token=$legacy;$user->save();
        $path='/'.admin_setting('subscribe_path','s').'/'.$legacy;
        $this->app['auth']->forgetGuards();$this->get(strtolower($path))->assertStatus(403);
    }

    public function test_other_account_cannot_cancel_order_or_replay_its_request(): void
    {
        $a=$this->account();$b=$this->account();
        $order=Order::create(['user_id'=>$a->id,'plan_id'=>1,'type'=>2,'trade_no'=>'qa-order-'.Str::random(16),'period'=>'monthly','status'=>0,'total_amount'=>100]);
        $this->postJson('/api/onenew/user/renewals/'.$order->trade_no.'/cancel',[],$this->headersFor($b))->assertStatus(400);
        self::assertSame(0,(int)$order->fresh()->status);
        $this->getJson('/api/onenew/user/orders',$this->headersFor($b))->assertOk()->assertExactJson([]);
    }

    public function test_daily_billed_traffic_does_not_double_rate_or_include_monthly_and_other_users(): void
    {
        $user=$this->account();$other=$this->account();$day=strtotime(gmdate('Y-m-d').' UTC');
        foreach([[$user->id,'d',2,100,200],[$user->id,'m',2,10000,20000],[$other->id,'d',2,999,999]] as [$uid,$type,$rate,$u,$d]){
            StatUser::create(['user_id'=>$uid,'record_type'=>$type,'server_rate'=>$rate,'record_at'=>$day,'u'=>$u,'d'=>$d]);
        }
        $query=http_build_query(['from'=>gmdate('Y-m-d'),'to'=>gmdate('Y-m-d'),'timezone'=>'UTC','grain'=>'day']);
        $rows=$this->getJson('/api/onenew/user/usage?'.$query,$this->headersFor($user))->assertOk()->json('rows');
        self::assertCount(1,$rows);self::assertSame(300,$rows[0]['billed_bytes']);
        self::assertSame(100,$rows[0]['up_bytes']);
    }

    public function test_portal_registration_requires_a_real_unused_invite_even_when_native_force_is_off(): void
    {
        $email='qa-register-'.Str::random(12).'@example.invalid';
        $this->postJson('/api/onenew/register',['email'=>$email,'password'=>Str::random(24)])->assertStatus(422);
        $this->postJson('/api/onenew/register',['email'=>$email,'password'=>Str::random(24),'invite_code'=>'not-a-real-code'])->assertStatus(422);
        self::assertFalse(User::byEmail($email)->exists());
        $this->getJson('/api/onenew/invites/not-a-real-code')->assertOk()->assertJsonPath('valid',false);
    }

    public function test_usage_calendar_filters_always_use_panel_archive_dates_across_zones_and_dst(): void
    {
        foreach(['UTC','Asia/Shanghai','America/Los_Angeles'] as $index=>$zone){
            config()->set('app.timezone',$zone);
            $user=$this->account();
            $day=\Carbon\CarbonImmutable::parse('2026-03-08',$zone)->startOfDay();
            foreach([-1,0,1] as $offset){
                StatUser::create(['user_id'=>$user->id,'record_type'=>'d','server_rate'=>1,'record_at'=>$day->addDays($offset)->timestamp,'u'=>100,'d'=>200]);
            }
            // An old client's timezone parameter cannot shift a native archive day.
            $query=http_build_query(['from'=>'2026-03-08','to'=>'2026-03-08','timezone'=>$zone==='UTC'?'America/Los_Angeles':'UTC','grain'=>'day']);
            $response=$this->getJson('/api/onenew/user/usage?'.$query,$this->headersFor($user))->assertOk()->assertJsonPath('source_timezone',$zone);
            $rows=$response->json('rows');self::assertCount(1,$rows);self::assertSame('2026-03-08',$rows[0]['bucket']);self::assertSame(300,$rows[0]['billed_bytes']);
        }
    }

    public function test_portal_registration_uses_native_inviter_and_consumes_one_time_code(): void
    {
        $owner=$this->account();$code=new InviteCode();$code->user_id=$owner->id;$code->code=Str::random(8);$code->save();
        $email='qa-registered-'.Str::random(10).'@example.invalid';
        $this->postJson('/api/onenew/register',['email'=>$email,'password'=>Str::random(24),'invite_code'=>$code->code])->assertOk()->assertJsonPath('success',true)->assertJsonMissingPath('auth_data');
        $registered=User::byEmail($email)->firstOrFail();
        self::assertSame(User::normalizeEmail($email),$registered->email);
        self::assertSame($owner->id,(int)$registered->invite_user_id);
        self::assertTrue($code->fresh()->status);
        $this->getJson('/api/onenew/invites/'.$code->code)->assertOk()->assertJsonPath('valid',false);
    }
}
