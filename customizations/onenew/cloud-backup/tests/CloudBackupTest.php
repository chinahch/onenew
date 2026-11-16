<?php
namespace Tests\Feature;
use Tests\TestCase;
use App\Models\User;
use App\Services\AuthService;
use App\Onenew\CloudBackup\Config;
use App\Onenew\CloudBackup\Service;
use App\Onenew\CloudBackup\BackupJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
final class CloudBackupTest extends TestCase
{
    use RefreshDatabase;
    private const ROOT='/api/onenew/admin/cloud-backup';
    protected function setUp():void{parent::setUp();config(['onenew.backup_identity'=>str_repeat('a',32)]);Bus::fake([BackupJob::class]);Http::preventStrayRequests();}
    private function auth(bool $admin=true):array{$u=User::create(['email'=>Str::random(12).'@example.test','password'=>password_hash('fixture-password',PASSWORD_DEFAULT),'uuid'=>(string)Str::uuid(),'token'=>Str::random(32),'is_admin'=>$admin]);$this->app['auth']->forgetGuards();return ['Authorization'=>(new AuthService($u))->generateAuthData()['auth_data']];}
    private function payload():array{return ['client_id'=>'fixture.apps.googleusercontent.com','client_secret'=>'fixture-client-secret','refresh_token'=>'fixture-refresh-token','password'=>'fixture-backup-password','password_confirmation'=>'fixture-backup-password','enabled'=>true,'daily_time'=>'03:00','folder_id'=>''];}
    public function test_admin_only_and_masked_settings_preserve_secrets_on_blank_update():void{
        $this->getJson(self::ROOT,$this->auth(false))->assertStatus(403);$auth=$this->auth();
        $r=$this->postJson(self::ROOT.'/settings',$this->payload(),$auth)->assertOk();self::assertStringNotContainsString('fixture-client-secret',$r->getContent());self::assertStringNotContainsString('fixture-backup-password',$r->getContent());
        $this->postJson(self::ROOT.'/settings',['enabled'=>true,'daily_time'=>'04:25','client_secret'=>'','refresh_token'=>'','password'=>''],$auth)->assertOk()->assertJsonPath('config.daily_time','04:25');
        self::assertSame('fixture-refresh-token',app(Config::class)->secrets()['refresh_token']);self::assertStringNotContainsString('fixture-refresh-token',DB::table('onenew_cloud_backup_config')->value('encrypted_config'));
        $this->postJson(self::ROOT.'/settings',['daily_time'=>'24:00'],$auth)->assertStatus(422);
    }
    public function test_schedule_deduplicates_daily_slot_and_restored_identity_pauses():void{
        $this->postJson(self::ROOT.'/settings',$this->payload(),$this->auth())->assertOk();
        DB::table('onenew_cloud_backup_config')->update(['next_run_at'=>time()-60]);$s=app(Service::class);$s->tick();$s->tick();
        self::assertSame(1,DB::table('onenew_cloud_backup_runs')->count());Bus::assertDispatchedTimes(BackupJob::class,1);
        config(['onenew.backup_identity'=>str_repeat('b',32)]);$state=app(Config::class)->view();self::assertFalse($state['enabled']);self::assertTrue($state['restored_paused']);
        DB::table('onenew_cloud_backup_config')->update(['next_run_at'=>time()-60]);$s->tick();self::assertSame(1,DB::table('onenew_cloud_backup_runs')->count());
    }
    public function test_latest_attempt_and_success_survive_failures_without_returning_secrets():void{
        $this->postJson(self::ROOT.'/settings',$this->payload(),$this->auth())->assertOk();$s=app(Service::class);$one=$s->enqueue('manual');
        DB::table('onenew_cloud_backup_runs')->where('id',$one)->update(['state'=>'success','finished_at'=>time(),'file_id'=>'fixture-drive-id','bytes'=>123]);
        $two=$s->enqueue('manual');DB::table('onenew_cloud_backup_runs')->where('id',$two)->update(['state'=>'failed','error_code'=>'network_error','finished_at'=>time()]);
        $r=$this->getJson(self::ROOT,$this->auth())->assertOk()->assertJsonPath('latest.id',$two)->assertJsonPath('latest_success.id',$one);
        self::assertStringNotContainsString('encrypted_config',$r->getContent());self::assertStringNotContainsString('fixture-refresh-token',$r->getContent());
        self::assertStringNotContainsString('fixture-backup-password',serialize(new BackupJob($two)));
    }
}
