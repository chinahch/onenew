<?php
namespace App\Onenew\AiOperations;

use App\Models\{User,Server,Plan,ServerMachine,ServerGroup};
use App\Services\UserService;
use App\Http\Requests\Admin\ServerSave;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\{Cache,Crypt,DB,Validator};
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Local capability boundary. No model output is executed without a cached confirmation card. */
final class OperatorActions
{
    private const NODE_FIELDS=['name','host','port','server_port','show','enabled','machine_id','group_ids','node_speed_limit_mbps'];
    public function catalog(): array
    {
        return [
            ['kind'=>'user.create','args'=>['email'=>'explicit administrator email alias resolved locally','plan_id'=>'optional integer','days'=>'optional 1..3650','expired_at'=>'optional Unix timestamp; mutually exclusive with days']],
            ['kind'=>'user.extend','args'=>['user_id'=>'integer','amount'=>'1..3650','unit'=>'hours|days|months'],'description'=>'Free administrator duration adjustment; unlimited expiry needs clarification.'],
            ['kind'=>'node.create','args'=>['template_node_id'=>'only required field: existing node integer ID','name'=>'optional; unique template name + -副本 suffix by default','host'=>'optional; inherit template host locally including IPv6','port'=>'optional 1..65535; follows new listener when template public/listener match, otherwise preserves public forwarding port','server_port'=>'optional 1..65535; next unused listener on target machine/host by default','machine_id'=>'optional; inherits template machine','group_ids'=>'optional integer array; inherits template groups','enabled'=>'optional boolean; default false and hidden from subscriptions','regenerate_reality'=>'optional boolean; default true for REALITY'],'description'=>'Template ID alone creates a disabled hidden draft with fresh REALITY keys. Missing optional fields are resolved locally, not guessed by the model. Explicit listener collisions are rejected.'],
            ['kind'=>'node.update','args'=>array_merge(['node_id'],self::NODE_FIELDS)],
            ['kind'=>'node.delete','args'=>['node_id'=>'integer'],'description'=>'Remove this exact node from subscriptions; never delete its machine.'],
        ];
    }
    public function read(string $tool,array $args): array
    {
        if ($tool==='users.lookup') {
            $this->only($args,['user_id','email']);Validator::make($args,['user_id'=>'required_without:email|integer|min:1','email'=>'required_without:user_id|email|max:254'])->validate();
            $q=User::query();if(isset($args['user_id']))$q->where('id',$args['user_id']);else $q->where('email',User::normalizeEmail($args['email']));
            return ['users'=>$q->limit(10)->get(['id','plan_id','group_id','expired_at','banned'])->toArray()];
        }
        if ($tool==='nodes.lookup') {
            $this->only($args,['node_id','name','query']);Validator::make($args,['node_id'=>'nullable|integer|min:1','name'=>'nullable|string|max:100','query'=>'nullable|string|max:100'])->validate();
            $q=Server::query();if(isset($args['node_id']))$q->where('id',$args['node_id']);if(isset($args['name'])||isset($args['query']))$q->where('name',$args['name']??$args['query']);
            return ['nodes'=>$q->orderBy('id')->limit(30)->get(['id','name','type','machine_id','host','port','server_port','group_ids','show','enabled'])->toArray()];
        }
        if($tool==='inventory'){
            $this->only($args,['kind']);Validator::make($args,['kind'=>'required|in:plans,machines,groups'])->validate();
            $class=['plans'=>Plan::class,'machines'=>ServerMachine::class,'groups'=>ServerGroup::class][$args['kind']];
            return [$args['kind']=>$class::orderBy('id')->limit(50)->get(['id','name'])->toArray()];
        }
        $this->invalid('tool','Unknown administrator read tool.');
    }
    public function prepare(int $admin,string $kind,array $args): array
    {
        abort_if($admin<1,403);$snapshot=[];$before=[];$after=[];
        if($kind==='user.create'){
            $this->only($args,['email','plan_id','days','expired_at']);
            if(isset($args['email'])&&is_string($args['email']))$args['email']=User::normalizeEmail($args['email']);
            Validator::make($args,['email'=>'required|email|max:254|unique:v2_user,email','plan_id'=>'nullable|integer|exists:v2_plan,id','days'=>'nullable|integer|min:1|max:3650|prohibits:expired_at','expired_at'=>'nullable|integer|min:1|prohibits:days'])->validate();
            if(isset($args['days'])){$args['expired_at']=now()->addDays((int)$args['days'])->timestamp;unset($args['days']);}
            if(!isset($args['plan_id'])){
                $args['trial_config']=$this->trialConfig();
                $trial=Plan::find($args['trial_config']['plan_id']);
                if($trial){$args['plan_id']=$trial->id;if(!isset($args['expired_at']))$args['expired_at']=now()->addHours($args['trial_config']['hours'])->timestamp;}
            }
            if(isset($args['plan_id']))$snapshot[]=$this->snapshot(Plan::findOrFail($args['plan_id']));
            $after=['email'=>$args['email'],'plan_id'=>$args['plan_id']??null,'expiry'=>$this->date($args['expired_at']??null),'initial_password'=>'Generated securely and shown locally after confirmation'];
        }elseif($kind==='user.extend'){
            $this->only($args,['user_id','amount','unit']);Validator::make($args,['user_id'=>'required|integer|min:1','amount'=>'required|integer|min:1|max:3650','unit'=>'required|in:hours,days,months'])->validate();
            $user=User::findOrFail($args['user_id']);if($user->expired_at===null)$this->invalid('user_id','This user has unlimited expiry. Choose a finite expiry explicitly outside this operation.');
            $snapshot[]=$this->snapshot($user);$base=CarbonImmutable::createFromTimestamp(max(time(),(int)$user->expired_at),config('app.timezone','UTC'));
            $args['new_expired_at']=match($args['unit']){'hours'=>$base->addHours((int)$args['amount'])->timestamp,'days'=>$base->addDays((int)$args['amount'])->timestamp,'months'=>$base->addMonthsNoOverflow((int)$args['amount'])->timestamp};
            $before=['user_id'=>$user->id,'expiry'=>$this->date($user->expired_at)];$after=['user_id'=>$user->id,'expiry'=>$this->date($args['new_expired_at']),'charge'=>0];
        }elseif(in_array($kind,['node.create','node.update','node.delete'],true)){
            $create=$kind==='node.create';$delete=$kind==='node.delete';$idKey=$create?'template_node_id':'node_id';
            $allowed=$delete?[$idKey]:array_merge([$idKey],$create?['name','host','port','server_port','machine_id','group_ids','enabled','regenerate_reality']:self::NODE_FIELDS);$this->only($args,$allowed);
            Validator::make($args,[$idKey=>'required|integer|min:1'])->validate();$node=Server::findOrFail($args[$idKey]);$snapshot[]=$this->snapshot($node);
            $before=$this->nodeView($node);$before['effect']=$delete?'This exact node will be removed from subscriptions. Its machine remains.':'Current node';
            if(!$delete){
                $rules=['regenerate_reality'=>'sometimes|boolean','name'=>'sometimes|string|max:255','host'=>'sometimes|string|max:255','port'=>'sometimes|integer|between:1,65535','server_port'=>'sometimes|integer|between:1,65535','show'=>'sometimes|boolean','enabled'=>'sometimes|boolean','machine_id'=>'nullable|integer|exists:v2_server_machine,id','group_ids'=>'sometimes|array|max:50','group_ids.*'=>'integer|distinct|exists:v2_server_group,id','node_speed_limit_mbps'=>'nullable|numeric|min:0|max:1000000'];Validator::make($args,$rules)->validate();
                $machineId=array_key_exists('machine_id',$args)?$args['machine_id']:$node->machine_id;
                if($machineId)$snapshot[]=$this->snapshot(ServerMachine::findOrFail($machineId));
                foreach($args['group_ids']??$node->group_ids??[] as $id)$snapshot[]=$this->snapshot(ServerGroup::findOrFail($id));
                $data=$node->attributesToArray();unset($data['id'],$data['created_at'],$data['updated_at']);
                if($create){foreach(['onenew_identity','code','spectific_key'] as $key)unset($data[$key]);foreach(['u','d'] as $key)$data[$key]=0;foreach(['last_check_at','last_push_at'] as $key)unset($data[$key]);$data['parent_id']=null;$data['enabled']=false;foreach(['onenew_landing_bindings','custom_routes','custom_outbounds','route_ids'] as $key)$data[$key]=[];}
                foreach(self::NODE_FIELDS as $key)if(array_key_exists($key,$args))$data[$key]=$args[$key];
                if($create){
                    if(!isset($args['name'])){$data['name']=$this->copyName($node->name);$args['generated_name']=true;}
                    if(!isset($args['server_port']))$data['server_port']=$this->copyPort($data,(int)($node->server_port?:$node->port));
                    if(!isset($args['port'])&&(string)$node->port===(string)($node->server_port?:$node->port))$data['port']=$data['server_port'];
                    if(!$data['enabled'])$data['show']=false;
                }
                if($create && (int)($data['protocol_settings']['tls']??0)===2){
                    if($args['regenerate_reality']??true){
                        $private=random_bytes(32);$private[0]=chr(ord($private[0])&248);$private[31]=chr((ord($private[31])&127)|64);
                        $encode=static fn(string $bytes): string=>rtrim(strtr(base64_encode($bytes),'+/','-_'),'=');
                        $data['protocol_settings']['reality_settings']=array_merge($data['protocol_settings']['reality_settings']??[],['private_key'=>$encode($private),'public_key'=>$encode(sodium_crypto_scalarmult_base($private)),'short_id'=>bin2hex(random_bytes(8))]);
                    }
                }
                $this->validateNodePort($data,$create?null:$node->id);
                $request=ServerSave::create('/','POST',$data);Validator::make($data,$request->rules(),$request->messages())->validate();
                $args['data']=$data;$after=array_intersect_key($data,array_flip(self::NODE_FIELDS));if($create){
                    $after['effect']=$data['enabled']?(!empty($data['machine_id'])?'Save enabled node and queue native configuration; runtime listening is not verified.':'Save enabled node without an assigned machine; runtime listening is not verified.'):'Create a disabled draft; review and enable the node separately.';
                    if((string)$data['port']!==(string)$data['server_port'])$after['effect'].=' Public forwarding/CDN port is preserved; verify its mapping before enabling.';
                    if((int)($data['protocol_settings']['tls']??0)===2)$after['reality']=array_intersect_key($data['protocol_settings']['reality_settings']??[],array_flip(['public_key','short_id']));
                }
            }else $after=['deleted'=>true,'machine_deleted'=>false];
        }else $this->invalid('kind','Unknown administrator operation.');
        $id=(string)Str::uuid();$card=['id'=>$id,'kind'=>$kind,'title'=>$kind,'summary'=>'Review the exact target and changes, then explicitly confirm.','before'=>$before,'after'=>$after,'expires_at'=>time()+600];
        Cache::put($this->key($admin,$id),Crypt::encryptString(json_encode(['card'=>$card,'args'=>$args,'snapshots'=>$snapshot],JSON_THROW_ON_ERROR)),600);
        return $card;
    }
    public function execute(int $admin,string $id,string $requestId,array $inputs=[]): array
    {
        Validator::make(['id'=>$id,'request_id'=>$requestId],['id'=>'required|uuid','request_id'=>'required|uuid'])->validate();$this->only($inputs,[]);abort_if($admin<1,403);
        return Cache::lock('onenew:ai:action-lock:'.$id,60)->block(5,function()use($admin,$id,$requestId){
            // The listener reservation lock wraps the whole transaction, including
            // commit. Different templates on the same unassigned host must serialize.
            $cached=Cache::get($this->key($admin,$id));$listenerKey=null;$nameKey=null;
            if(is_string($cached)){
                $prepared=json_decode(Crypt::decryptString($cached),true,512,JSON_THROW_ON_ERROR);
                if(in_array($prepared['card']['kind'],['node.create','node.update'],true))$listenerKey=$this->listenerLockKey($prepared['args']['data']);
                if($prepared['card']['kind']==='node.create'&&!empty($prepared['args']['generated_name']))$nameKey='onenew:ai:copy-name:'.hash('sha256',$prepared['args']['data']['name']);
            }
            $dispatch=function()use($admin,$id,$requestId){return DB::transaction(function()use($admin,$id,$requestId){
                $prior=DB::table('onenew_ai_action_audit')->where('action_id',$id)->first();
                if($prior){abort_unless((int)$prior->admin_id===$admin,404);$r=json_decode(Crypt::decryptString($prior->encrypted_result),true,512,JSON_THROW_ON_ERROR);$secret=Cache::get($this->key($admin,$id).':credentials');if(is_string($secret)&&time()<=$prior->credential_expires_at)$r['credentials']=json_decode(Crypt::decryptString($secret),true,512,JSON_THROW_ON_ERROR);return $r;}
                abort_if(DB::table('onenew_ai_action_audit')->where('admin_id',$admin)->where('request_id',$requestId)->exists(),409,'Request ID already belongs to another action.');
                $cached=Cache::get($this->key($admin,$id));abort_unless(is_string($cached),404,'Action expired or belongs to another administrator.');$p=json_decode(Crypt::decryptString($cached),true,512,JSON_THROW_ON_ERROR);abort_if(time()>$p['card']['expires_at'],409,'Action expired.');
                $targets=[];foreach($p['snapshots'] as $s){$model=$s['class']::query()->lockForUpdate()->find($s['id']);abort_unless($model&&hash_equals($s['hash'],$this->fingerprint($model)),409,'Target changed; prepare a new action.');$targets[$s['class'].':'.$s['id']]=$model;}
                $a=$p['args'];$kind=$p['card']['kind'];$result=['id'=>$id,'kind'=>$kind,'status'=>'succeeded'];
                if($kind==='user.create'){
                    if(isset($a['trial_config']))abort_unless($a['trial_config']===$this->trialConfig(),409,'Default trial configuration changed; prepare a new action.');
                    abort_if(User::where('email',$a['email'])->exists(),409,'Email already exists.');$password=bin2hex(random_bytes(24));$user=app(UserService::class)->createUser(array_merge($a,['password'=>$password]));
                    // Trial defaults must not override an explicit administrator expiry.
                    if(isset($a['expired_at']))$user->expired_at=$a['expired_at'];$user->saveOrFail();$result['target_id']=$user->id;$result['credentials']=['password'=>$password];
                }elseif($kind==='user.extend'){$user=$targets[User::class.':'.$a['user_id']];$user->expired_at=$a['new_expired_at'];$user->saveOrFail();$result['target_id']=$user->id;$result['expired_at']=$user->expired_at;
                }elseif($kind==='node.create'){$this->validateNodePort($a['data']);if(!empty($a['generated_name'])&&Server::where('name',$a['data']['name'])->exists())$this->invalid('name','Generated copy name is now in use; prepare a new action.');$node=new Server();$node->fill($a['data']);$node->saveOrFail();$result['target_id']=$node->id;$result['deployment_status']=$node->enabled&&$node->machine_id?'saved_configuration_queued':'saved_disabled_or_unassigned';
                }else{$node=$targets[Server::class.':'.$a['node_id']];$result['target_id']=$node->id;if($kind==='node.delete'){if(!$node->delete())throw new \RuntimeException('Node delete failed.');}else{$this->validateNodePort($a['data'],$node->id);$node->fill(array_intersect_key($a['data'],array_flip(self::NODE_FIELDS)));$node->saveOrFail();}}
                $auditResult=$result;unset($auditResult['credentials']);
                DB::table('onenew_ai_action_audit')->insert(['admin_id'=>$admin,'action_id'=>$id,'request_id'=>$requestId,'kind'=>$kind,'target_id'=>$result['target_id'],'result_code'=>'succeeded','encrypted_result'=>Crypt::encryptString(json_encode($auditResult,JSON_THROW_ON_ERROR)),'credential_expires_at'=>time()+600,'created_at'=>time()]);
                if(isset($result['credentials']))Cache::put($this->key($admin,$id).':credentials',Crypt::encryptString(json_encode($result['credentials'],JSON_THROW_ON_ERROR)),600);
                return $result;
            });};
            $save=fn()=>$listenerKey===null?$dispatch():Cache::lock($listenerKey,60)->block(5,$dispatch);
            return $nameKey===null?$save():Cache::lock($nameKey,60)->block(5,$save);
        });
    }
    private function listenerLockKey(array $data): string
    {
        $scope=!empty($data['machine_id'])?'machine:'.(int)$data['machine_id']:'host:'.strtolower(trim((string)$data['host']));
        return 'onenew:ai:listener:'.hash('sha256',$scope.':'.(int)$data['server_port']);
    }
    private function copyName(string $name): string
    {
        $base=mb_substr($name,0,230).'-副本';
        for($i=1;$i<=1000;$i++){$candidate=$base.($i===1?'':' '.$i);if(!Server::where('name',$candidate)->exists())return $candidate;}
        $this->invalid('name','No unused copy name found; provide an explicit name.');
    }
    private function copyPort(array $data,int $base): int
    {
        $query=Server::query();if(!empty($data['machine_id']))$query->where('machine_id',$data['machine_id']);else $query->whereNull('machine_id')->where('host',$data['host']);
        $used=array_fill_keys(array_map('intval',$query->pluck('server_port')->all()),true);$start=max(1,min(65535,$base));
        for($offset=1;$offset<=65535;$offset++){$candidate=(($start+$offset-1)%65535)+1;if(!isset($used[$candidate]))return $candidate;}
        $this->invalid('server_port','No unused listener port remains on this machine/host.');
    }
    /** Drafts reserve listener ports too. The outer listener lock serializes AI saves. */
    private function validateNodePort(array $data,?int $except=null): void
    {
        $port=(int)$data['server_port'];$query=Server::query()->where('server_port',$port);
        if($except)$query->where('id','!=',$except);
        if(!empty($data['machine_id']))$query->where('machine_id',$data['machine_id']);
        else $query->whereNull('machine_id')->where('host',$data['host']);
        if($query->exists())$this->invalid('server_port','Listen port '.$port.' is already reserved by another active node or disabled draft on this machine/host. Choose a different server_port.');
    }
    private function nodeView(Server $node): array {return array_intersect_key($node->attributesToArray(),array_flip(array_merge(['id','type'],self::NODE_FIELDS)));}
    private function key(int $admin,string $id): string{return 'onenew:ai:action:'.$admin.':'.$id;}
    private function snapshot($model): array{return ['class'=>get_class($model),'id'=>$model->getKey(),'hash'=>$this->fingerprint($model)];}
    private function fingerprint($model): string
    {
        $raw=$model->getRawOriginal();
        // Heartbeats, counters, balances and login activity do not change the
        // reviewed identity/configuration and must not expire confirmation cards.
        $live=['updated_at','u','d','last_seen_at','load_status','last_check_at','last_push_at','online','online_conn','metrics'];
        if($model instanceof User)$live=array_merge($live,['balance','commission_balance','last_login_at','last_login_ip','last_checkin_at','last_checkin_time','next_reset_at','last_reset_at','reset_count']);
        foreach($live as $key)unset($raw[$key]);ksort($raw);return hash('sha256',json_encode($raw,JSON_THROW_ON_ERROR));
    }
    private function trialConfig(): array{return ['plan_id'=>(int)admin_setting('try_out_plan_id',0),'hours'=>(int)admin_setting('try_out_hour',1)];}
    private function date($timestamp): ?string{return $timestamp===null?null:CarbonImmutable::createFromTimestamp((int)$timestamp,config('app.timezone','UTC'))->format('Y-m-d H:i:s T (P)');}
    private function only(array $args,array $keys): void{if(array_diff(array_keys($args),$keys))$this->invalid('args','Unknown or forbidden operation arguments.');}
    private function invalid(string $field,string $message): never{throw ValidationException::withMessages([$field=>$message]);}
}
