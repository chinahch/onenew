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
    public function test_offline_wait_does_not_block_online_and_machine_claim_survives_refresh(): void {
        $a=$this->machine('offline');$b=$this->machine('online');$c=$this->machine('later');
        foreach([$a,$b,$c] as $m)$this->poll($m)->assertOk();$this->admin();
        $this->create([$a,$b,$c])->assertOk()->assertJsonCount(3,'jobs');
        $this->create([$b])->assertConflict();
        $job=$this->poll($b)->assertOk()->json('job');$this->assertNotEmpty($job['lease']);
        $this->poll($b)->assertExactJson(['job'=>null]);
        $this->getJson('/api/onenew/admin/upgrades')->assertOk()->assertJsonPath('concurrency',5)
            ->assertJsonPath('machines.0.job.state','pending')->assertJsonPath('machines.1.job.state','leased');
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
        $others=[];foreach(range(1,4) as $i){$others[]=$m=$this->machine('grace-'.$i);$this->poll($m)->assertOk();}
        $this->create(array_merge([$a,$b],$others))->assertOk();$job=$this->poll($a)->json('job');
        foreach($others as $m)$this->assertNotNull($this->poll($m)->assertOk()->json('job'));
        $this->postJson('/api/onenew/admin/upgrades/'.$job['id'].'/cancel')->assertConflict();
        $this->travel(901)->seconds();$this->report($a,$job,'succeeded')->assertConflict();$this->poll($b)->assertExactJson(['job'=>null]);
        $this->travel(181)->seconds();$this->poll($b)->assertOk()->assertJsonPath('job.release.version','v0.1.12');
        $this->getJson('/api/onenew/admin/upgrades')->assertJsonPath('machines.0.job.state','expired');
    }
    public function test_five_global_slots_include_every_active_stage_and_refill_once_after_completion(): void {
        $machines=[];foreach(range(1,7) as $i){$machines[]=$m=$this->machine('parallel-'.$i);$this->poll($m)->assertOk();}
        $this->admin();$this->create($machines)->assertOk();
        $jobs=[];
        foreach(array_slice($machines,0,5) as $index=>$m){
            $jobs[$index]=$this->poll($m)->assertOk()->json('job');
            $this->assertNotNull($jobs[$index]);
            if($index>0)$this->report($m,$jobs[$index],['downloading','verifying','installing','checking'][$index-1],'v0.1.11')->assertOk();
        }
        $this->poll($machines[5])->assertExactJson(['job'=>null]);
        $this->poll($machines[6])->assertExactJson(['job'=>null]);
        $this->poll($machines[0])->assertExactJson(['job'=>null]);
        $this->report($machines[0],$jobs[0],'succeeded')->assertOk();
        $this->report($machines[0],$jobs[0],'succeeded')->assertOk();
        $this->assertNotNull($this->poll($machines[5])->assertOk()->json('job'));
        $this->poll($machines[6])->assertExactJson(['job'=>null]);
        $this->report($machines[1],$jobs[1],'rolled_back','v0.1.11','health_failed')->assertOk();
        $this->assertNotNull($this->poll($machines[6])->assertOk()->json('job'));
        $this->assertSame(5,DB::table('v2_onenew_upgrade_jobs')->whereIn('state',\App\Onenew\RemoteUpgrade\UpgradeService::ACTIVE)->count());
    }
    public function test_rotated_machine_cannot_claim_a_second_lease_before_old_lease_grace_expires(): void {
        $a=$this->machine('rotated');$b=$this->machine('other');
        $this->poll($a);$this->poll($b);$this->admin();$this->create([$a])->assertOk();
        $this->assertNotNull($this->poll($a)->assertOk()->json('job'));
        $a->token=bin2hex(random_bytes(24));$a->onenew_identity=(string)Str::uuid();$a->save();
        $this->poll($a)->assertExactJson(['job'=>null]);$this->create([$a,$b])->assertOk();
        $this->poll($a)->assertExactJson(['job'=>null]);
        $this->assertNotNull($this->poll($b)->assertOk()->json('job'));
        $this->travel(901)->seconds();$this->poll($a)->assertExactJson(['job'=>null]);
        $this->travel(181)->seconds();$this->assertNotNull($this->poll($a)->assertOk()->json('job'));
    }
    public function test_failed_health_report_records_actual_rollback_version_without_logs(): void {
        $m=$this->machine();$this->poll($m);$this->admin();$this->create([$m]);$job=$this->poll($m)->json('job');
        $this->report($m,$job,'rolled_back','v0.1.12','health_failed')->assertUnprocessable();
        $this->report($m,$job,'rolled_back','v0.1.11','health_failed')->assertOk();
        $this->getJson('/api/onenew/admin/upgrades')->assertJsonPath('machines.0.version','v0.1.11')->assertJsonPath('machines.0.job.state','rolled_back')->assertJsonPath('machines.0.job.reported_version','v0.1.11');
        $this->create([$m])->assertOk();
    }
    public function test_overview_returns_latest_job_and_projects_expiry_without_writing(): void {
        $m=$this->machine();$this->poll($m);$this->admin();
        $old=$this->create([$m])->json('jobs.0.id');
        $this->postJson('/api/onenew/admin/upgrades/'.$old.'/cancel')->assertOk();
        $latest=$this->create([$m])->json('jobs.0.id');
        $revision=DB::table('v2_onenew_upgrade_mutex')->where('id',1)->value('revision');
        $this->travel(86401)->seconds();
        $this->getJson('/api/onenew/admin/upgrades')->assertOk()
            ->assertJsonPath('machines.0.job.id',$latest)->assertJsonPath('machines.0.job.state','expired');
        $this->assertSame($revision,DB::table('v2_onenew_upgrade_mutex')->where('id',1)->value('revision'));
        $this->assertSame('pending',DB::table('v2_onenew_upgrade_jobs')->where('id',$latest)->value('state'));
    }
    public function test_journal_write_failure_is_reported_without_being_called_interrupted(): void {
        $m=$this->machine();$this->poll($m);$this->admin();$this->create([$m]);$job=$this->poll($m)->json('job');
        $this->report($m,$job,'failed','v0.1.11','journal_failed')->assertOk();
        $this->getJson('/api/onenew/admin/upgrades')->assertJsonPath('machines.0.job.error_code','journal_failed')->assertJsonPath('machines.0.job.reported_version','v0.1.11');
    }
    public function test_reused_machine_id_drops_pending_old_binding_and_uses_claimed_actual_version(): void {
        $m=$this->machine();$this->poll($m);$this->admin();$old=$this->create([$m])->json('jobs.0.id');
        $m->token=bin2hex(random_bytes(24));$m->onenew_identity=(string)Str::uuid();$m->save();
        $this->poll($m)->assertOk()->assertJsonPath('job',null);$this->create([$m])->assertOk();
        $this->assertSame('failed',DB::table('v2_onenew_upgrade_jobs')->where('id',$old)->value('state'));
        $job=$this->poll($m,'v0.1.10')->json('job');
        $this->report($m,$job,'rolled_back','v0.1.10','health_failed')->assertOk();
    }
}
