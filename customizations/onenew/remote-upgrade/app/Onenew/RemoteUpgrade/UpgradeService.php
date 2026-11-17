<?php
namespace App\Onenew\RemoteUpgrade;

use App\Models\ServerMachine;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class UpgradeService
{
    public const ACTIVE=['leased','downloading','verifying','installing','checking'];
    public const TERMINAL=['succeeded','failed','rolled_back','cancelled','expired'];
    public const ERRORS=['download_failed','verification_failed','install_failed','health_failed','rollback_failed','interrupted','invalid_task','expired','unsupported','internal_error'];
    private const JOBS='v2_onenew_upgrade_jobs';
    private const AGENTS='v2_onenew_upgrade_agents';
    public function __construct(private ReleaseCatalog $catalog) {}
    private function locked(callable $fn) {
        return DB::transaction(function () use ($fn) {
            // Updating a pre-existing singleton serializes writers on MySQL, PostgreSQL and SQLite.
            DB::table('v2_onenew_upgrade_mutex')->where('id',1)->increment('revision');
            $this->expire(); return $fn();
        },3);
    }
    private function binding(ServerMachine $m): array { return ['identity'=>(string)$m->onenew_identity,'credential_hash'=>hash('sha256',(string)$m->token)]; }
    private function matches($row,ServerMachine $m): bool {
        $b=$this->binding($m); return $row && hash_equals($row->identity,$b['identity']) && hash_equals($row->credential_hash,$b['credential_hash']);
    }
    private function expire(): void {
        $now=now()->timestamp;
        DB::table(self::JOBS)->where('state','pending')->where('expires_at','<=',$now)->update(['state'=>'expired','error_code'=>'expired','updated_at'=>$now]);
        // An agent must finish rollback before its lease deadline. Keep the slot for an extra grace interval.
        DB::table(self::JOBS)->whereIn('state',self::ACTIVE)->where('lease_expires_at','<=',$now-180)->update(['state'=>'expired','error_code'=>'expired','updated_at'=>$now]);
    }
    public function overview(): array {
        return $this->locked(function () {
            $agents=DB::table(self::AGENTS)->get()->keyBy('machine_id');
            $jobs=DB::table(self::JOBS)->orderByDesc('sequence')->get()->groupBy('machine_id');
            $rows=[];
            foreach(ServerMachine::orderBy('id')->get() as $m) {
                $a=$agents->get($m->id); $valid=$this->matches($a,$m); $latest=$jobs->get($m->id)?->first();
                if ($latest && !$this->matches($latest,$m)) $latest=null;
                $rows[]=['machine_id'=>(int)$m->id,'name'=>(string)$m->name,'is_active'=>(bool)$m->is_active,
                    'capable'=>$valid,'online'=>$valid && $a->seen_at>=now()->timestamp-90,
                    'version'=>$valid?$a->version:null,'arch'=>$valid?$a->arch:null,'seen_at'=>$valid?(int)$a->seen_at:null,
                    'assistant_version'=>$valid?$a->assistant_version:null,'job'=>$latest?$this->project($latest):null];
            }
            return ['machines'=>$rows,'versions'=>array_keys($this->catalog->all()),'concurrency'=>1];
        });
    }
    public function create(array $ids,string $target,int $admin): array {
        return $this->locked(function () use($ids,$target,$admin) {
            $result=[];
            foreach($ids as $id) {
                $m=ServerMachine::findOrFail($id); $a=DB::table(self::AGENTS)->where('machine_id',$id)->first();
                abort_unless($m->is_active && $this->matches($a,$m),422,'Install the upgrade assistant on every selected server first');
                $this->catalog->asset($target,$a->arch);
                abort_unless(version_compare(ltrim($a->version,'v'),ltrim($target,'v'),'<'),422,'Selected server is already current or newer');
                abort_if(DB::table(self::JOBS)->where('machine_id',$id)->whereIn('state',array_merge(['pending'],self::ACTIVE))->exists(),409,'A selected server already has an upgrade queued');
                $now=now()->timestamp;
                $row=array_merge(['id'=>(string)Str::uuid(),'machine_id'=>(int)$id,'admin_id'=>$admin,'target'=>$target,'arch'=>$a->arch,
                    'previous_version'=>$a->version,'reported_version'=>null,'state'=>'pending','error_code'=>'','lease_hash'=>null,
                    'created_at'=>$now,'updated_at'=>$now,'expires_at'=>$now+86400,'lease_expires_at'=>null],$this->binding($m));
                DB::table(self::JOBS)->insert($row); $result[]=$this->project((object)$row);
            }
            return ['jobs'=>$result];
        });
    }
    public function poll(ServerMachine $m,array $data): ?array {
        return $this->locked(function () use($m,$data) {
            $agent=array_merge($this->binding($m),['machine_id'=>(int)$m->id,'arch'=>$data['arch'],'version'=>$data['version'],
                'assistant_version'=>$data['assistant_version'],'seen_at'=>now()->timestamp]);
            DB::table(self::AGENTS)->updateOrInsert(['machine_id'=>$m->id],$agent);
            // FIFO across a batch; an offline first server remains visibly waiting, but does not block other online servers.
            if(DB::table(self::JOBS)->whereIn('state',self::ACTIVE)->exists()) return null;
            $row=DB::table(self::JOBS)->where('machine_id',$m->id)->where('state','pending')->orderBy('sequence')->first();
            if(!$row) return null;
            if(!$this->matches($row,$m) || $row->arch!==$data['arch']) {
                DB::table(self::JOBS)->where('id',$row->id)->update(['state'=>'failed','error_code'=>'invalid_task','updated_at'=>now()->timestamp]); return null;
            }
            if(!version_compare(ltrim($data['version'],'v'),ltrim($row->target,'v'),'<')) {
                DB::table(self::JOBS)->where('id',$row->id)->update(['state'=>'failed','error_code'=>'invalid_task','reported_version'=>$data['version'],'updated_at'=>now()->timestamp]); return null;
            }
            $asset=$this->catalog->asset($row->target,$row->arch); $lease=bin2hex(random_bytes(32)); $expiry=now()->timestamp+900;
            DB::table(self::JOBS)->where('id',$row->id)->update(['state'=>'leased','lease_hash'=>hash('sha256',$lease),'lease_expires_at'=>$expiry,'updated_at'=>now()->timestamp]);
            return ['id'=>$row->id,'lease'=>$lease,'expires_at'=>$expiry,'release'=>$asset];
        });
    }
    public function accept(ServerMachine $m,string $id,array $data): void {
        $this->locked(function () use($m,$id,$data) {
            $row=DB::table(self::JOBS)->where('id',$id)->first();
            abort_unless($row && (int)$row->machine_id===(int)$m->id && $this->matches($row,$m) && $row->lease_hash && hash_equals($row->lease_hash,hash('sha256',$data['lease'])),409);
            $state=$data['state']; $error=$data['error_code']??'';
            if(in_array($row->state,self::TERMINAL,true)) {
                abort_unless($row->state===$state && ($row->reported_version??'')===$data['version'] && $row->error_code===$error,409); return;
            }
            abort_unless(in_array($row->state,self::ACTIVE,true) && $row->lease_expires_at>now()->timestamp,409,'Lease expired');
            $stages=array_merge(['leased'],['downloading','verifying','installing','checking']);
            if(in_array($state,$stages,true)) abort_unless(array_search($state,$stages,true)>=array_search($row->state,$stages,true),409);
            if($state==='succeeded') abort_unless($data['version']===$row->target && $error==='',422);
            if($state==='rolled_back') abort_unless($data['version']===$row->previous_version && $error!=='',422);
            if($state==='failed') abort_unless($error!=='',422);
            if(in_array($state,$stages,true)) abort_unless($error==='',422);
            DB::table(self::JOBS)->where('id',$id)->update(['state'=>$state,'reported_version'=>$data['version'],'error_code'=>$error,'updated_at'=>now()->timestamp]);
            DB::table(self::AGENTS)->where('machine_id',$m->id)->update(['version'=>$data['version'],'seen_at'=>now()->timestamp]);
        });
    }
    public function cancel(string $id): void {
        $this->locked(function() use($id) {
            $row=DB::table(self::JOBS)->where('id',$id)->first(); abort_unless($row,404);
            $machine=ServerMachine::find($row->machine_id); abort_unless($machine && $this->matches($row,$machine),409,'Server binding changed');
            abort_unless($row->state==='pending',409,'Only a waiting upgrade can be cancelled');
            DB::table(self::JOBS)->where('id',$id)->update(['state'=>'cancelled','updated_at'=>now()->timestamp]);
        });
    }
    private function project(object $row): array {
        return ['id'=>$row->id,'machine_id'=>(int)$row->machine_id,'target'=>$row->target,'previous_version'=>$row->previous_version,
            'reported_version'=>$row->reported_version,'state'=>$row->state,'error_code'=>$row->error_code,
            'created_at'=>(int)$row->created_at,'updated_at'=>(int)$row->updated_at,'expires_at'=>(int)$row->expires_at,
            'lease_expires_at'=>$row->lease_expires_at?(int)$row->lease_expires_at:null];
    }
}
