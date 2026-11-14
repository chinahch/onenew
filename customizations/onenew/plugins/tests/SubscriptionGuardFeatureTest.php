<?php
namespace Tests\Feature\Onenew;

use App\Jobs\SendTelegramJob;
use App\Models\User;
use App\Models\Plugin as PluginRecord;
use App\Services\Plugin\PluginManager;
use App\Services\Plugin\InterceptResponseException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Plugin\SubscriptionGuard\Plugin;
use Tests\TestCase;

require_once __DIR__.'/../SubscriptionGuard/Plugin.php';
require_once __DIR__.'/../SubscriptionGuard/Controllers/SubscriptionGuardController.php';

final class SubscriptionGuardFeatureTest extends TestCase
{
    use RefreshDatabase;
    protected function setUp():void
    {
        parent::setUp();
        config()->set('app.key','base64:'.base64_encode(str_repeat('g',32)));
        config()->set('cache.default','array');config()->set('cache.stores.redis.driver','array');
        Cache::purge('redis');Cache::store('array')->flush();
        admin_setting(['secure_path'=>'guard-test']);
        $this->artisan('migrate',['--path'=>__DIR__.'/../SubscriptionGuard/database/migrations','--realpath'=>true,'--force'=>true]);
        require __DIR__.'/../SubscriptionGuard/routes/api.php';
        require __DIR__.'/../SubscriptionGuard/routes/web.php';
        View::addNamespace('SubscriptionGuard',__DIR__.'/../SubscriptionGuard/resources/views');
        Http::preventStrayRequests();Bus::fake([SendTelegramJob::class]);
    }
    private function account(array $extra=[]):User
    {
        return User::create($extra+['email'=>'guard-'.Str::random(12).'@example.test','password'=>'unused',
            'uuid'=>(string)Str::uuid(),'token'=>Str::random(32),'transfer_enable'=>1000000,'u'=>0,'d'=>0,'banned'=>false]);
    }
    private function guard(array $config=[]):Plugin
    {
        $p=new Plugin('subscription_guard');$p->setConfig($config+['rate_limit_seconds'=>0,'enable_telegram_notify'=>false]);return $p;
    }
    private function pull(Plugin $plugin,User $user,string $ip,string $agent='fake-client'):void
    {
        $request=Request::create('/s/not-exported','GET',[],[],[],['REMOTE_ADDR'=>$ip,'HTTP_USER_AGENT'=>$agent]);
        $request->setUserResolver(fn()=>$user->fresh());$this->app->instance('request',$request);$plugin->onSubscribeBefore();
    }
    public function test_native_install_is_disabled_and_defaults_are_non_punitive():void
    {
        $manager=new class extends PluginManager {
            public function __construct(){parent::__construct();$this->pluginPath=dirname(__DIR__);$this->corePluginPath=__DIR__.'/no-core-plugins';}
        };
        $manager->install('subscription_guard');
        $row=PluginRecord::where('code','subscription_guard')->firstOrFail();$config=json_decode($row->config,true);
        $this->assertFalse($row->is_enabled);$this->assertSame('alert_only',$config['action_mode']);$this->assertFalse($config['enable_telegram_notify']);
        Bus::assertNothingDispatched();
    }
    public function test_data_and_actions_require_admin_auth_and_never_export_subscription_tokens():void
    {
        $base='/api/v2/guard-test/subscription-guard';
        $this->getJson($base.'/logs')->assertForbidden();
        Sanctum::actingAs($this->account());$this->getJson($base.'/logs')->assertForbidden();
        $this->postJson($base.'/ban',['user_id'=>1,'confirmed'=>true])->assertForbidden();
        Sanctum::actingAs($this->account(['is_staff'=>true]));$this->getJson($base.'/logs')->assertForbidden();
        $user=$this->account();$this->pull($this->guard(),$user,'192.0.2.1','token='.$user->token);
        Sanctum::actingAs($this->account(['is_admin'=>true]));
        $result=$this->getJson($base.'/logs')->assertOk()->assertJsonCount(1,'data');
        $this->assertStringNotContainsString($user->token,$result->getContent());
        $this->assertFalse(Schema::hasColumn('v2_subscribe_log','token'));
        $this->getJson($base.'/logs?pageSize=-1')->assertUnprocessable();
        $this->postJson($base.'/clean-logs',['days'=>-1,'confirmed'=>true])->assertUnprocessable();
        $this->postJson($base.'/ban',['user_id'=>$user->id])->assertUnprocessable();
    }
    public function test_exact_maximum_is_allowed_and_next_ip_only_alerts_by_default():void
    {
        $user=$this->account();$token=$user->token;$guard=$this->guard(['max_unique_ips'=>2]);
        $this->pull($guard,$user,'192.0.2.1');$this->pull($guard,$user,'192.0.2.2');
        $this->assertSame(0,DB::table('v2_subscribe_abuse')->count());
        $this->pull($guard,$user,'192.0.2.3');
        $this->assertSame('alert_only',DB::table('v2_subscribe_abuse')->value('action_taken'));
        $this->assertFalse($user->fresh()->banned);$this->assertSame($token,$user->fresh()->token);
        Bus::assertNothingDispatched();
    }
    public function test_explicit_auto_ban_commits_reset_and_403_only_after_threshold():void
    {
        $user=$this->account();$token=$user->token;$guard=$this->guard(['max_unique_ips'=>1,'action_mode'=>'auto_ban']);
        $this->pull($guard,$user,'192.0.2.1');
        try{$this->pull($guard,$user,'192.0.2.2');$this->fail('Expected subscription interception');}
        catch(InterceptResponseException $exception){$this->assertSame(403,$exception->getResponse()->getStatusCode());}
        $this->assertTrue($user->fresh()->banned);$this->assertNotSame($token,$user->fresh()->token);
        $this->assertSame('auto_ban',DB::table('v2_subscribe_abuse')->value('action_taken'));
        Bus::assertNothingDispatched();
    }
    public function test_whitelist_then_subnet_grouping_deduplicates_before_deciding():void
    {
        $user=$this->account();$guard=$this->guard(['max_unique_ips'=>1,'enable_subnet_grouping'=>true,'ip_whitelist'=>"192.0.2.8\n2001:db8::/32"]);
        foreach(['192.0.2.8','192.0.2.9','192.0.2.10','2001:db8::1'] as $ip)$this->pull($guard,$user,$ip);
        $this->assertSame(2,DB::table('v2_subscribe_log')->count());$this->assertSame(0,DB::table('v2_subscribe_abuse')->count());
        $this->pull($guard,$user,'198.51.100.1');$this->assertSame(2,DB::table('v2_subscribe_abuse')->value('unique_ip_count'));
    }
    public function test_management_accounts_are_protected_and_rate_limit_is_user_scoped():void
    {
        $guard=$this->guard(['max_unique_ips'=>1,'action_mode'=>'auto_ban','exclude_admin'=>false,'rate_limit_seconds'=>60]);
        foreach([$this->account(['is_admin'=>true]),$this->account(['is_staff'=>true])] as $user){$this->pull($guard,$user,'192.0.2.1');$this->pull($guard,$user,'192.0.2.2');$this->assertFalse($user->fresh()->banned);}
        $one=$this->account();$two=$this->account();$this->pull($guard,$one,'192.0.2.1');$this->pull($guard,$one,'192.0.2.1');$this->pull($guard,$two,'192.0.2.1');
        $this->assertSame(2,DB::table('v2_subscribe_log')->count());
    }
    public function test_unban_reviews_history_without_rebanning_and_admin_targets_are_rejected():void
    {
        $user=$this->account(['banned'=>true]);$admin=$this->account(['is_admin'=>true]);Sanctum::actingAs($admin);
        DB::table('v2_subscribe_log')->insert([['user_id'=>$user->id,'ip'=>'192.0.2.1','created_at'=>now()->subMinutes(2)],['user_id'=>$user->id,'ip'=>'192.0.2.2','created_at'=>now()->subMinutes(1)]]);
        $base='/api/v2/guard-test/subscription-guard';
        $this->postJson($base.'/unban',['user_id'=>$user->id,'confirmed'=>true])->assertOk();
        $this->postJson($base.'/ban',['user_id'=>$admin->id,'confirmed'=>true])->assertForbidden();
        $this->travel(2)->seconds();$this->pull($this->guard(['action_mode'=>'auto_ban','max_unique_ips'=>1]),$user,'198.51.100.1');
        $this->assertFalse($user->fresh()->banned);$this->assertSame(3,DB::table('v2_subscribe_log')->count());
    }
    public function test_standalone_shell_has_no_external_scripts_or_credential_data():void
    {
        $result=$this->get('/guard-test/subscription-guard')->assertOk();
        $this->assertStringContainsString('no-store',$result->headers->get('Cache-Control'));
        $this->assertStringNotContainsString('cdn.',$result->getContent());
        $this->assertStringContainsString("default-src 'none'",$result->headers->get('Content-Security-Policy'));
    }
    public function test_explicit_reset_only_rotates_token_without_banning_and_ignores_expired_ips():void
    {
        $user=$this->account();$token=$user->token;$guard=$this->guard(['max_unique_ips'=>1,'action_mode'=>'reset_only','time_window_hours'=>1]);
        DB::table('v2_subscribe_log')->insert(['user_id'=>$user->id,'ip'=>'198.51.100.1','created_at'=>now()->subHours(2)]);
        $this->pull($guard,$user,'192.0.2.1');$this->assertSame(0,DB::table('v2_subscribe_abuse')->count());
        try{$this->pull($guard,$user,'192.0.2.2');$this->fail('Expected subscription interception');}
        catch(InterceptResponseException $exception){$this->assertSame(403,$exception->getResponse()->getStatusCode());}
        $this->assertFalse($user->fresh()->banned);$this->assertNotSame($token,$user->fresh()->token);
        Bus::assertNothingDispatched();
    }
    public function test_upgrade_drops_indexed_legacy_token_data_without_dropping_audit_rows():void
    {
        Schema::table('v2_subscribe_log',function($table){$table->string('token',32)->nullable()->index();});
        $user=$this->account();DB::table('v2_subscribe_log')->insert(['user_id'=>$user->id,'ip'=>'192.0.2.1','token'=>$user->token,'created_at'=>now()]);
        $migration=require __DIR__.'/../SubscriptionGuard/database/migrations/2026_11_14_000002_harden_subscribe_guard.php';
        $migration->up();$this->assertFalse(Schema::hasColumn('v2_subscribe_log','token'));
        $this->assertSame(1,DB::table('v2_subscribe_log')->count());
    }
}
