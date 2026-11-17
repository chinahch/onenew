<?php
namespace Tests\Feature\Onenew;
use App\Models\ServerMachine;
use App\Models\User;
use App\Onenew\RemoteUpgrade\ReleaseCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class RemoteUpgradeTest extends TestCase
{
    use RefreshDatabase;
    protected function setUp(): void {
        parent::setUp();
        $this->app->instance(ReleaseCatalog::class,new class extends ReleaseCatalog {
            public function all(): array { $assets=[];foreach(['amd64','arm64'] as $arch)$assets[$arch]=['version'=>'v0.1.12','arch'=>$arch,'sha256'=>str_repeat('a',64),'url'=>'https://github.com/chinahch/onenew-releases/releases/download/node-v0.1.12/onenew-node-linux-'.$arch.'.tar.gz'];return ['v0.1.12'=>$assets]; }
        });
    }
    private function machine(string $name='upgrade'): ServerMachine { return ServerMachine::create(['name'=>$name,'token'=>bin2hex(random_bytes(24)),'is_active'=>true]); }
    private function admin(): void {
        Sanctum::actingAs(User::create(['email'=>Str::uuid().'@example.test','password'=>'unused','uuid'=>(string)Str::uuid(),'token'=>bin2hex(random_bytes(16)),'is_admin'=>true,'transfer_enable'=>1000,'u'=>0,'d'=>0,'banned'=>0]));
    }
    private function poll(ServerMachine $m,string $version='v0.1.11',string $arch='amd64') {
        return $this->withToken($m->token)->getJson('/api/v3/server/upgrade/poll?'.http_build_query(['machine_id'=>$m->id,'version'=>$version,'assistant_version'=>'v0.1.12','arch'=>$arch]));
    }
    private function create(array $machines) { return $this->postJson('/api/onenew/admin/upgrades',['machine_ids'=>array_map(fn($m)=>$m->id,$machines),'version'=>'v0.1.12','confirmed'=>true]); }
    private function report(ServerMachine $m,array $job,string $state,string $version='v0.1.12',string $error='') {
        return $this->withToken($m->token)->postJson('/api/v3/server/upgrade/'.$job['id'].'/status',['machine_id'=>$m->id,'lease'=>$job['lease'],'state'=>$state,'version'=>$version,'error_code'=>$error]);
    }
    public function test_auth_bootstrap_and_catalog_fail_closed(): void {
        $m=$this->machine();
        $this->getJson('/api/onenew/admin/upgrades')->assertForbidden();
        $this->getJson('/api/v3/server/upgrade/poll?machine_id='.$m->id)->assertForbidden();
        $this->admin();$this->create([$m])->assertUnprocessable();
        $this->poll($m)->assertExactJson(['job'=>null]);
        $this->postJson('/api/onenew/admin/upgrades',['machine_ids'=>[$m->id],'version'=>'https://evil.test','confirmed'=>true])->assertUnprocessable();
        $this->postJson('/api/onenew/admin/upgrades',['machine_ids'=>[$m->id],'version'=>'v0.1.12','confirmed'=>true,'url'=>'https://evil.test'])->assertUnprocessable();
        $this->getJson('/api/onenew/admin/upgrades')->assertOk()->assertJsonPath('machines.0.capable',true)->assertJsonMissing(['token'=>$m->token]);
    }
    public function test_offline_wait_does_not_block_online_and_global_single_claim_survives_refresh(): void {
        $a=$this->machine('offline');$b=$this->machine('online');$c=$this->machine('later');
        foreach([$a,$b,$c] as $m)$this->poll($m)->assertOk();$this->admin();
        $this->create([$a,$b,$c])->assertOk()->assertJsonCount(3,'jobs');
        $this->create([$b])->assertConflict();
        $job=$this->poll($b)->assertOk()->json('job');$this->assertNotEmpty($job['lease']);
        $this->poll($a)->assertExactJson(['job'=>null]);$this->poll($b)->assertExactJson(['job'=>null]);
        $this->getJson('/api/onenew/admin/upgrades')->assertOk()->assertJsonPath('machines.1.job.state','leased');
        $raw=DB::table('v2_onenew_upgrade_jobs')->where('id',$job['id'])->first();
        $this->assertNotSame($job['lease'],$raw->lease_hash);
        $this->report($b,$job,'checking','v0.1.11')->assertOk();
        $this->report($b,$job,'downloading','v0.1.11')->assertConflict();
        $this->report($b,$job,'succeeded','v0.1.11')->assertUnprocessable();
        $this->report($b,$job,'succeeded')->assertOk();$this->report($b,$job,'succeeded')->assertOk();
        $this->report($b,$job,'failed','v0.1.11','install_failed')->assertConflict();
        $this->poll($c)->assertOk()->assertJsonPath('job.release.version','v0.1.12');
    }
    public function test_machine_reuse_rotation_and_wrong_machine_cannot_inherit_lease(): void {
        $a=$this->machine();$b=$this->machine();$this->poll($a);$this->poll($b);$this->admin();$this->create([$a])->assertOk();
        $job=$this->poll($a)->json('job');$this->report($b,$job,'succeeded')->assertConflict();
        $a->token=bin2hex(random_bytes(24));$a->onenew_identity=(string)Str::uuid();$a->save();
        $this->report($a,$job,'succeeded')->assertConflict();
        $this->getJson('/api/onenew/admin/upgrades')->assertOk()->assertJsonPath('machines.0.capable',false)->assertJsonPath('machines.0.job',null);
        $this->postJson('/api/onenew/admin/upgrades/'.$job['id'].'/cancel')->assertConflict();
    }
    public function test_pending_cancel_expiry_and_lease_expiry_grace(): void {
        $a=$this->machine();$b=$this->machine();$this->poll($a);$this->poll($b);$this->admin();
        $id=$this->create([$a])->json('jobs.0.id');$this->postJson('/api/onenew/admin/upgrades/'.$id.'/cancel')->assertOk();
        $this->create([$a,$b])->assertOk();$job=$this->poll($a)->json('job');
        $this->postJson('/api/onenew/admin/upgrades/'.$job['id'].'/cancel')->assertConflict();
        $this->travel(901)->seconds();$this->report($a,$job,'succeeded')->assertConflict();$this->poll($b)->assertExactJson(['job'=>null]);
        $this->travel(181)->seconds();$this->poll($b)->assertOk()->assertJsonPath('job.release.version','v0.1.12');
        $this->getJson('/api/onenew/admin/upgrades')->assertJsonPath('machines.0.job.state','expired');
    }
    public function test_failed_health_report_records_actual_rollback_version_without_logs(): void {
        $m=$this->machine();$this->poll($m);$this->admin();$this->create([$m]);$job=$this->poll($m)->json('job');
        $this->report($m,$job,'rolled_back','v0.1.12','health_failed')->assertUnprocessable();
        $this->report($m,$job,'rolled_back','v0.1.11','health_failed')->assertOk();
        $this->getJson('/api/onenew/admin/upgrades')->assertJsonPath('machines.0.version','v0.1.11')->assertJsonPath('machines.0.job.state','rolled_back')->assertJsonPath('machines.0.job.reported_version','v0.1.11');
        $this->create([$m])->assertOk();
    }
}
