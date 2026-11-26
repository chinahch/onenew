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
    public function test_manual_requests_reuse_active_run_and_old_worker_cannot_finish_reclaimed_run():void{
        $this->postJson(self::ROOT.'/settings',$this->payload(),$this->auth())->assertOk();
        $s=app(Service::class);$id=$s->enqueue('manual');self::assertSame($id,$s->enqueue('manual'));
        $run=$s->claim($id);DB::table('onenew_cloud_backup_runs')->where('id',$id)->update(['lease_until'=>time()-1]);
        $s->tick();$replacement=$s->claim($id);self::assertNotSame($run['lease_token'],$replacement['lease_token']);
        $s->finish($id,$run['lease_token']);self::assertSame('generating',DB::table('onenew_cloud_backup_runs')->where('id',$id)->value('state'));
        $s->patch($id,$replacement['lease_token'],['state'=>'uploading']);$s->tick();
        self::assertSame('uploading',DB::table('onenew_cloud_backup_runs')->where('id',$id)->value('state'));
    }
    public function test_restored_settings_save_does_not_implicitly_reenable_schedule():void{
        $c=app(Config::class);$c->save($this->payload());config(['onenew.backup_identity'=>str_repeat('b',32)]);
        $c->save(['daily_time'=>'05:10']);self::assertFalse($c->view()['enabled']);
    }

    public function test_storage_usage_requires_admin_without_starting_a_backup():void{
        $this->getJson(self::ROOT.'/storage-usage')->assertForbidden();
        $this->getJson(self::ROOT.'/storage-usage',$this->auth(false))->assertForbidden();
        Bus::assertNotDispatched(BackupJob::class);
    }

    public function test_storage_cleanup_is_confirmed_allowlisted_and_only_deletes_expired_rows():void{
        $root=sys_get_temp_dir().'/onenew-cleanup-'.Str::uuid();
        mkdir($root.'/.docker/.data',0700,true);
        $database=$root.'/.docker/.data/database.sqlite';touch($database);
        config(['onenew.migration_source'=>$root,'onenew.migration_database'=>$database]);
        $table='onenew_runtime_log_batches';$old=time()-90000;$recent=time()-82800;
        DB::table($table)->insert([['stream_id'=>1,'batch_id'=>'old','received_at'=>$old],['stream_id'=>1,'batch_id'=>'recent','received_at'=>$recent]]);
        DB::table('v2_onenew_traffic_receipts')->insert(['batch_id'=>'receipt','created_at'=>$old]);
        $route=self::ROOT.'/storage-cleanup';$payload=['table'=>$table,'retention_days'=>1,'confirm'=>true];
        try{
            $this->postJson($route,$payload,$this->auth(false))->assertForbidden();
            $auth=$this->auth();
            $this->postJson($route,array_replace($payload,['confirm'=>false]),$auth)->assertUnprocessable();
            $this->postJson($route,array_replace($payload,['retention_days'=>7]),$auth)->assertUnprocessable();
            $this->postJson($route,array_replace($payload,['table'=>'v2_onenew_traffic_receipts']),$auth)->assertUnprocessable();
            $this->assertDatabaseCount($table,2);
            $this->postJson($route,$payload,$auth)->assertOk()->assertJsonPath('deleted',1)->assertJsonPath('remaining',false);
            $this->assertDatabaseMissing($table,['batch_id'=>'old']);
            $this->assertDatabaseHas($table,['batch_id'=>'recent']);
            $this->assertDatabaseHas('v2_onenew_traffic_receipts',['batch_id'=>'receipt']);
            DB::table('onenew_runtime_log_entries')->insert([
                ['machine_id'=>1,'machine_identity'=>str_repeat('1',64),'stream_id'=>1,'seq'=>1,'level'=>'info','at'=>$recent,'message'=>'old-received','received_at'=>$old],
                ['machine_id'=>1,'machine_identity'=>str_repeat('1',64),'stream_id'=>1,'seq'=>2,'level'=>'info','at'=>$old,'message'=>'old-at','received_at'=>$recent],
                ['machine_id'=>1,'machine_identity'=>str_repeat('1',64),'stream_id'=>1,'seq'=>3,'level'=>'info','at'=>$recent,'message'=>'fresh','received_at'=>$recent],
            ]);
            $this->postJson($route,['table'=>'onenew_runtime_log_entries','retention_days'=>1,'confirm'=>true],$auth)->assertOk()->assertJsonPath('deleted',2)->assertJsonPath('remaining',false);
            $this->assertDatabaseCount('onenew_runtime_log_entries',1);
            $this->assertDatabaseHas('onenew_runtime_log_entries',['message'=>'fresh']);
            Bus::assertNotDispatched(BackupJob::class);
        }finally{
            unlink($database);rmdir(dirname($database));rmdir($root.'/.docker');rmdir($root);
        }
    }

    public function test_storage_usage_is_fresh_read_only_and_independent_of_saved_oauth_settings():void{
        $root=sys_get_temp_dir().'/onenew-usage-'.Str::uuid();
        mkdir($root.'/.docker/.data',0700,true);mkdir($root.'/plugins',0700);mkdir($root.'/storage/theme',0700,true);
        $database=$root.'/.docker/.data/database.sqlite';
        $pdo=new \PDO('sqlite:'.$database);$pdo->exec('CREATE TABLE fixture (id INTEGER PRIMARY KEY)');$pdo=null;
        file_put_contents($root.'/plugins/fixture.php','12345');file_put_contents($root.'/storage/theme/fixture.css','1234567');
        config(['onenew.migration_source'=>$root,'onenew.migration_database'=>$database]);$auth=$this->auth();$before=hash_file('sha256',$database);
        try{
            $response=$this->getJson(self::ROOT.'/storage-usage',$auth)->assertOk()->assertJsonPath('plugins.bytes',5)->assertJsonPath('theme.bytes',7)->assertJsonPath('complete',true);
            self::assertStringContainsString('no-store',$response->headers->get('Cache-Control'));
            self::assertSame(filesize($database)+12,$response->json('total_bytes'));self::assertStringNotContainsString($root,$response->getContent());
            file_put_contents($root.'/plugins/fixture.php','123456789');
            $this->getJson(self::ROOT.'/storage-usage',$auth)->assertOk()->assertJsonPath('plugins.bytes',9);
            self::assertSame($before,hash_file('sha256',$database));self::assertSame(0,DB::table('onenew_cloud_backup_runs')->count());Bus::assertNotDispatched(BackupJob::class);
        }finally{
            foreach(['/plugins/fixture.php','/storage/theme/fixture.css','/.docker/.data/database.sqlite'] as $file)if(is_file($root.$file))unlink($root.$file);
            foreach(['/storage/theme','/storage','/plugins','/.docker/.data','/.docker',''] as $directory)rmdir($root.$directory);
        }
    }

}
