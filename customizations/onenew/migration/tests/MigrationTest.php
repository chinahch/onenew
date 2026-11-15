<?php
namespace Tests\Feature\Onenew;
use App\Models\User;
use App\Onenew\Migration\ExportMigration;
use App\Onenew\Migration\MigrationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MigrationTest extends TestCase
{
    use RefreshDatabase;
    private string $source;
    private const ROOT='/api/onenew/admin/migration';
    protected function setUp(): void
    {
        parent::setUp();Queue::fake();
        $this->source=sys_get_temp_dir().'/onenew-migration-test-'.bin2hex(random_bytes(6));
        mkdir($this->source.'/.docker/.data',0700,true);mkdir($this->source.'/plugins/Example/resources/assets',0700,true);mkdir($this->source.'/storage/theme',0700,true);
        file_put_contents($this->source.'/.env','APP_KEY='.config('app.key')."\nDB_CONNECTION=sqlite\nDB_DATABASE=.docker/.data/database.sqlite\nINSTALLED=true\n");
        file_put_contents($this->source.'/plugins/Example/resources/assets/test.js','original plugin asset');
        file_put_contents($this->source.'/storage/theme/custom.json','{"original":true}');
        $pdo=new \PDO('sqlite:'.$this->source.'/.docker/.data/database.sqlite');
        $pdo->exec('CREATE TABLE credentials (kind TEXT, value TEXT)');
        $pdo->exec("INSERT INTO credentials VALUES ('subscription','original-subscription'),('machine','original-machine-token')");
        config()->set('onenew.migration_source',$this->source);config()->set('onenew.migration_database',$this->source.'/.docker/.data/database.sqlite');
        config()->set('onenew.migration_revision',str_repeat('a',40));
    }
    protected function tearDown(): void
    {
        if(isset($this->source))File::deleteDirectory($this->source);
        parent::tearDown();
    }
    private function user(bool $admin): User
    {
        return User::create(['email'=>bin2hex(random_bytes(8)).'@example.test','password'=>'unused','is_admin'=>$admin,
            'uuid'=>(string)\Illuminate\Support\Str::uuid(),'token'=>bin2hex(random_bytes(16))]);
    }
    public function test_export_requires_admin_and_password_confirmation(): void
    {
        $this->getJson(self::ROOT)->assertForbidden();Sanctum::actingAs($this->user(false));$this->getJson(self::ROOT)->assertForbidden();
        Sanctum::actingAs($this->user(true));$this->getJson(self::ROOT)->assertOk()->assertJsonPath('supported',true);
        $this->postJson(self::ROOT.'/exports',['password'=>'short','password_confirmation'=>'short'])->assertUnprocessable();
        $this->postJson(self::ROOT.'/exports',['password'=>'valid-migration-pass','password_confirmation'=>'different'])->assertUnprocessable();
        Queue::assertNothingPushed();
        $this->assertSame(1900,config('queue.connections.onenew_migration.retry_after'));
        $this->assertSame(1,config('horizon.environments.production.migration.maxProcesses'));
    }
    public function test_export_restores_snapshot_key_subscription_and_machine_credentials_and_private_files(): void
    {
        $admin=$this->user(true);Sanctum::actingAs($admin);
        $password='correct-private-password';
        $id=$this->postJson(self::ROOT.'/exports',['password'=>$password,'password_confirmation'=>$password])->assertOk()->json('id');
        $this->postJson(self::ROOT.'/exports',['password'=>$password,'password_confirmation'=>$password])->assertStatus(409);
        $job=Queue::pushed(ExportMigration::class)->first();$this->assertNotNull($job);
        $this->assertStringNotContainsString($password,serialize($job));
        $service=app(MigrationService::class);$job->handle($service);
        $this->assertSame('ready',$service->status($id)['state']);
        $this->assertDirectoryDoesNotExist($service->directory($id).'/stage');
        require_once '/opt/onenew/migration/core/MigrationBundle.php';
        $out=$this->source.'/restored';
        \App\Onenew\Migration\MigrationBundle::unpack($service->directory($id).'/package.onm',$password,$out);
        $this->assertSame(file_get_contents($this->source.'/.env'),file_get_contents($out.'/.env'));
        $restored=new \PDO('sqlite:'.$out.'/data/database/database.sqlite');
        $this->assertSame(['original-subscription','original-machine-token'],$restored->query('SELECT value FROM credentials ORDER BY rowid')->fetchAll(\PDO::FETCH_COLUMN));
        $this->assertSame('original plugin asset',file_get_contents($out.'/plugins/Example/resources/assets/test.js'));
        $this->assertSame('{"original":true}',file_get_contents($out.'/storage/theme/custom.json'));
        $ticket=$this->postJson(self::ROOT.'/exports/'.$id.'/ticket')->assertOk()->json('ticket');
        $download=$this->post('/api/onenew/migration/download',['ticket'=>$ticket])->assertOk();
        $this->assertStringContainsString('no-store',$download->headers->get('Cache-Control'));
        $this->post('/api/onenew/migration/download',['ticket'=>$ticket])->assertNotFound();
        Sanctum::actingAs($this->user(true));$this->getJson(self::ROOT.'/exports/'.$id)->assertNotFound();
        $this->postJson(self::ROOT.'/exports/'.$id.'/ticket')->assertNotFound();
        $this->assertArrayNotHasKey('latest',$this->getJson(self::ROOT)->json());
    }
    public function test_failed_exports_clean_plaintext_and_release_global_lock(): void
    {
        Sanctum::actingAs($this->user(true));$password='migration-private-pass';
        $id=$this->postJson(self::ROOT.'/exports',['password'=>$password,'password_confirmation'=>$password])->json('id');
        unlink($this->source.'/.env');
        Queue::pushed(ExportMigration::class)->first()->handle(app(MigrationService::class));
        $this->assertSame('failed',app(MigrationService::class)->status($id)['state']);
        $this->assertDirectoryDoesNotExist(app(MigrationService::class)->directory($id));
        $lock=Cache::store('redis')->lock(MigrationService::LOCK,5);$this->assertTrue($lock->get());$lock->release();
    }
    public function test_unsupported_databases_do_not_enqueue_a_misleading_package(): void
    {
        Sanctum::actingAs($this->user(true));config()->set('database.default','mysql');
        $this->getJson(self::ROOT)->assertOk()->assertJsonPath('supported',false);
        config()->set('database.default','sqlite');
    }
}
