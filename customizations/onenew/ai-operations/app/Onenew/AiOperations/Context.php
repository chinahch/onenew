<?php
namespace App\Onenew\AiOperations;
use App\Models\Server;
use App\Models\ServerMachine;
use App\Onenew\ProbeService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
/** Read-only projections; never serialize an Eloquent model or raw configuration. */
final class Context {
 public function snapshot(string $scope,?int $nodeId=null):array {
  abort_unless(in_array($scope,['panel','nodes','users','orders','backups','upgrades'],true),422,'请选择诊断范围');
  $revision=trim((string)@file_get_contents('/opt/onenew/REVISION'));
  $out=['collected_at'=>gmdate(DATE_ATOM),'scope'=>$scope,'revision'=>preg_match('/^[a-f0-9]{40}$/D',$revision)?$revision:'unknown',
   'timezone'=>config('app.timezone'),'read_only'=>true,'limits'=>['fleet_sample'=>50,'no_names_or_credentials'=>true,'live_probe_not_triggered'=>true]];
  $out['counts']=$this->section(fn()=>array_combine(['users','plans','nodes','machines','landings'],array_map(fn($table)=>Schema::hasTable($table)?DB::table($table)->count():null,['v2_user','v2_plan','v2_server','v2_server_machine','onenew_landings'])));
  if(in_array($scope,['panel','nodes'],true)) {
   $out['nodes']=$this->section(function()use($nodeId){
    $query=Server::with('machine')->orderBy('id');if($nodeId!==null)$query->whereKey($nodeId);
    $nodes=$query->limit(50)->get();
    return ['requested_id'=>$nodeId,'total_matching'=>$nodeId!==null?$nodes->count():Server::count(),'sample_limit'=>50,'items'=>$nodes->map(fn($node)=>$this->node($node,$nodeId!==null))->values()->all()];
   });
   $out['machines']=$this->section(fn()=>['total'=>ServerMachine::count(),'items'=>ServerMachine::orderBy('id')->limit(50)->get(['id','is_active','last_seen_at'])->map(fn($m)=>[
    'id'=>(int)$m->id,'enabled'=>(bool)$m->is_active,'last_seen_at'=>$this->number($m->last_seen_at),'last_seen_age_seconds'=>$m->last_seen_at?max(0,time()-(int)$m->last_seen_at):null])->all()]);
  }
  if(in_array($scope,['panel','users'],true))$out['accounts']=$this->section(fn()=>[
   'banned'=>DB::table('v2_user')->where('banned',1)->count(),'expired'=>DB::table('v2_user')->whereNotNull('expired_at')->where('expired_at','<',time())->count(),
   'positive_balance'=>DB::table('v2_user')->where('balance','>',0)->count(),'no_plan'=>DB::table('v2_user')->whereNull('plan_id')->count(),
   'plans'=>DB::table('v2_plan')->orderBy('id')->limit(30)->get(['id','speed_limit','device_limit','renew','sell'])->map(fn($p)=>[
    'id'=>(int)$p->id,'user_speed_limit_mbps'=>$this->number($p->speed_limit),'device_limit'=>$this->number($p->device_limit),'renew_enabled'=>(bool)$p->renew,'sell_enabled'=>(bool)$p->sell])->all()]);
  if(in_array($scope,['panel','users','orders'],true))$out['orders']=$this->section(fn()=>[
   'pending'=>DB::table('v2_order')->where('status',0)->count(),'processing'=>DB::table('v2_order')->where('status',1)->count(),'completed'=>DB::table('v2_order')->where('status',3)->count(),
   'energy_pending'=>Schema::hasTable('onenew_energy_requests')?DB::table('onenew_energy_requests')->where('status','pending')->count():null]);
  if(in_array($scope,['panel','backups'],true))$out['backups']=$this->section(function(){
   $config=Schema::hasTable('onenew_cloud_backup_config')?DB::table('onenew_cloud_backup_config')->where('id',1)->first(['enabled','daily_time','next_run_at']):null;
   $run=Schema::hasTable('onenew_cloud_backup_runs')?DB::table('onenew_cloud_backup_runs')->orderByDesc('sequence')->first(['state','created_at','finished_at','error_code','bytes']):null;
   $restore=['available'=>false];
   try{$latest=app(\App\Onenew\WebRestore\Service::class)->latest();$restore=['available'=>true,'latest'=>$latest?['state'=>$this->enum($latest['state']??null,['uploading','uploaded','validating','ready','queued','restoring','succeeded','failed','rolled_back','cancelled','expired','done','completed']),'updated_at'=>$this->number($latest['updated_at']??null)]:null];}catch(\Throwable){}
   return ['cloud_configured'=>$config!==null,'cloud_enabled'=>$config?(bool)$config->enabled:false,'daily_time_beijing'=>$config&&preg_match('/^\d{2}:\d{2}$/D',$config->daily_time)?$config->daily_time:null,'next_run_at'=>$config?$this->number($config->next_run_at):null,
    'last_cloud_run'=>$run?['state'=>$this->enum($run->state,['queued','generating','uploading','verifying','success','failed']),'created_at'=>$this->number($run->created_at),'finished_at'=>$this->number($run->finished_at),'error_code'=>$this->code($run->error_code),'bytes'=>$this->number($run->bytes)]:null,'web_restore'=>$restore];
  });
  if(in_array($scope,['panel','upgrades'],true))$out['upgrades']=$this->section(function(){
   if(!Schema::hasTable('v2_onenew_upgrade_jobs'))return ['available'=>false];
   return ['states'=>DB::table('v2_onenew_upgrade_jobs')->select('state')->selectRaw('COUNT(*) as n')->groupBy('state')->get()->map(fn($row)=>['state'=>$this->enum($row->state,['pending','leased','downloading','verifying','installing','checking','succeeded','failed','rolled_back','cancelled','expired']),'count'=>(int)$row->n])->all(),
    'recent'=>DB::table('v2_onenew_upgrade_jobs')->orderByDesc('sequence')->limit(15)->get(['machine_id','target','state','error_code','updated_at'])->map(fn($row)=>['machine_id'=>(int)$row->machine_id,'target'=>preg_match('/^v\d+\.\d+\.\d+$/D',$row->target)?$row->target:'unknown','state'=>$this->enum($row->state,['pending','leased','downloading','verifying','installing','checking','succeeded','failed','rolled_back','cancelled','expired']),'error_code'=>$this->code($row->error_code),'updated_at'=>$this->number($row->updated_at)])->all()];
  });
  return $out;
 }
 private function node(Server $node,bool $includeDomains=false):array {
  $s=$node->protocol_settings??[];$bindings=$node->onenew_landing_bindings;$routes=[];
  foreach(array_slice(is_array($bindings)?$bindings:[],0,8) as $binding) {
   $domains=array_values(array_filter($binding['domains']??[],fn($v)=>is_string($v)&&strlen($v)<=253&&preg_match('/^(?:[a-z0-9](?:[a-z0-9-]*[a-z0-9])?\.)+[a-z]{2,}$/D',$v)));
   $routes[]=['landing_id'=>$this->number($binding['landing_id']??null),'global'=>($binding['global']??false)===true,'domain_count'=>count($domains),'domains'=>$includeDomains?array_slice($domains,0,20):[], 'domains_included'=>$includeDomains];
  }
  $probe=$this->section(fn()=>app(ProbeService::class)->status($node));
  $safeProbe=['available'=>!isset($probe['available'])||$probe['available']!==false];
  foreach(['state','health_state','error_code','stage_error'] as $field)$safeProbe[$field]=$this->code($probe[$field]??null);
  foreach(['created_at','finished_at'] as $field)$safeProbe[$field]=$this->iso($probe[$field]??null);
  foreach(['latency_ms','ping_ms'] as $field)$safeProbe[$field]=$this->number($probe[$field]??null);
  $stages=$probe['stages']??[];
  if(is_array($stages)) {
   $safeProbe['entry_source']=$this->enum($stages['entry']['source']??null,['public_roundtrip','cdn_roundtrip','local_listener_roundtrip']);
   foreach(['entry','chain'] as $key)if(is_array($stages[$key]??null))$safeProbe[$key]=['state'=>$this->code($stages[$key]['state']??null),'error_code'=>$this->code($stages[$key]['error_code']??null)];
   $safeProbe['landings']=array_map(fn($stage)=>['state'=>$this->code($stage['state']??null),'error_code'=>$this->code($stage['error_code']??null)],array_slice(is_array($stages['landings']??null)?$stages['landings']:[],0,8));
  }
  $egress=null;try{$egress=\App\Onenew\MachineEgress::forNode($node);}catch(\Throwable){}
  return ['id'=>(int)$node->id,'machine_id'=>$this->number($node->machine_id),'enabled'=>$node->enabled===null?null:(bool)$node->enabled,'shown'=>(bool)$node->show,
   'protocol'=>$this->enum($node->type,['vless','vmess','trojan','shadowsocks','hysteria','hysteria2','tuic','socks','http','anytls','naive','mieru']),
   'transport'=>$this->enum(data_get($s,'network','tcp'),['tcp','ws','grpc','xhttp','httpupgrade','h2','quic','http']),
   'tls_mode'=>in_array(data_get($s,'tls'),[0,1,2,'0','1','2'],true)?(int)data_get($s,'tls'):null,
   'has_sni'=>is_string(data_get($s,'tls_settings.server_name'))&&data_get($s,'tls_settings.server_name')!=='',
   'has_ws_host'=>is_string(data_get($s,'network_settings.headers.Host'))&&data_get($s,'network_settings.headers.Host')!=='',
   'public_host_is_ip'=>filter_var($node->host,FILTER_VALIDATE_IP)!==false,'public_port'=>$this->port($node->port),'listen_port'=>$this->port($node->server_port),
   'node_total_bandwidth_mbps'=>$this->number($node->node_speed_limit_mbps),'egress'=>$this->enum($egress,['default','prefer_ipv4','prefer_ipv6','inherit']),
   'landing_bindings'=>$routes,'legacy_custom_outbound_count'=>count(is_array($node->custom_outbounds)?$node->custom_outbounds:[]),'probe'=>$safeProbe];
 }
 private function section(callable $fn):array {try{return $fn();}catch(\Throwable){return ['available'=>false,'reason'=>'读取不可用，不能据此判断故障'];}}
 private function number($v):?int {return is_numeric($v)&&is_finite((float)$v)&&$v>=0&&$v<=PHP_INT_MAX?(int)$v:null;}
 private function port($v):?int {return is_numeric($v)&&(int)$v>0&&(int)$v<=65535?(int)$v:null;}
 private function enum($v,array $allowed):string {return is_string($v)&&in_array($v,$allowed,true)?$v:'unknown';}
 private function code($v):string {return is_string($v)&&preg_match('/^[a-z_]{0,48}$/D',$v)?$v:'unknown';}
 private function iso($v):?string {return is_string($v)&&preg_match('/^\d{4}-\d{2}-\d{2}T[0-9:+Z.\-]+$/D',$v)?$v:null;}
}
