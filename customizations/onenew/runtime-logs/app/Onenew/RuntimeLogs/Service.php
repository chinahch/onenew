<?php
namespace App\Onenew\RuntimeLogs;
use App\Models\ServerMachine;
use Illuminate\Support\Facades\{Cache,DB};
final class Service
{
 public function identity(ServerMachine $machine):string{return hash('sha256',$machine->id.'|'.$machine->onenew_identity.'|'.$machine->token);}
 public function accept(ServerMachine $machine,array $data):array{
  $identity=$this->identity($machine);$first=(int)$data['first_seq'];foreach($data['entries'] as $i=>$entry)abort_unless((int)$entry['seq']===$first+$i,422,'Log sequence must be contiguous.');
  return Cache::lock('onenew:runtime-log:'.$identity,15)->block(3,function()use($machine,$data,$identity,$first){return DB::transaction(function()use($machine,$data,$identity,$first){
   $current=ServerMachine::whereKey($machine->id)->lockForUpdate()->first();abort_unless($current&&$current->is_active&&hash_equals($identity,$this->identity($current)),403);
   $streams=DB::table('onenew_runtime_log_streams');$stream=$streams->where('machine_identity',$identity)->where('deployment_id',$data['deployment_id'])->first();
   if(!$stream){$id=DB::table('onenew_runtime_log_streams')->insertGetId(['machine_id'=>$machine->id,'machine_identity'=>$identity,'deployment_id'=>$data['deployment_id'],'version'=>$data['version'],'last_received_at'=>time()]);$stream=DB::table('onenew_runtime_log_streams')->where('id',$id)->first();}
   if(DB::table('onenew_runtime_log_batches')->where('stream_id',$stream->id)->where('batch_id',$data['batch_id'])->exists())return ['accepted'=>true,'last_seq'=>(int)$stream->last_seq,'duplicate'=>true];
   abort_if($data['entries']&&$first<=(int)$stream->last_seq,409,'Stale log batch. Keep batch identity on retry.');
   $accepted=0;$filtered=0;$last=(int)$stream->last_seq;$now=time();
   foreach($data['entries'] as $entry){$last=(int)$entry['seq'];$message=LogFilter::message($entry['level'],$entry['message'],[$machine->token]);if($message===null||$message===''||(int)$entry['at']<$now-604800){$filtered++;continue;}
    DB::table('onenew_runtime_log_entries')->insert(['machine_id'=>$machine->id,'machine_identity'=>$identity,'stream_id'=>$stream->id,'seq'=>$entry['seq'],'level'=>$entry['level'],'at'=>$entry['at'],'message'=>$message,'received_at'=>$now]);$accepted++;
   }
   DB::table('onenew_runtime_log_batches')->insert(['stream_id'=>$stream->id,'batch_id'=>$data['batch_id'],'received_at'=>$now]);
   DB::table('onenew_runtime_log_streams')->where('id',$stream->id)->update(['last_seq'=>$last,'dropped'=>(int)$stream->dropped+(int)$data['dropped'],'filtered'=>(int)$stream->filtered+$filtered,'last_received_at'=>$now,'version'=>$data['version']]);
   return ['accepted'=>true,'last_seq'=>$last,'count'=>$accepted,'filtered'=>$filtered];
  });});
 }
 public function query(ServerMachine $machine,array $filters,int $admin):array{
  $identity=$this->identity($machine);$cutoff=time()-604800;$query=DB::table('onenew_runtime_log_entries')->where('machine_id',$machine->id)->where('machine_identity',$identity)->where('received_at','>=',$cutoff);
  foreach(['from'=>'>=','to'=>'<='] as $key=>$operator)if(isset($filters[$key]))$query->where('at',$operator,$filters[$key]);
  if(!empty($filters['level']))$query->where('level',$filters['level']);
  if(!empty($filters['search'])){$search=str_replace(['!','%','_'],['!!','!%','!_'],$filters['search']);$query->whereRaw("message LIKE ? ESCAPE '!'",['%'.$search.'%']);}
  $total=(clone $query)->count();$limit=(int)($filters['limit']??50);$page=(int)($filters['page']??1);if(isset($filters['before_id']))$query->where('id','<',$filters['before_id']);else $query->offset(($page-1)*$limit);
  $rows=$query->orderByDesc('id')->limit($limit+1)->get();$more=$rows->count()>$limit;$rows=$rows->take($limit);$entries=[];foreach($rows as $row)$entries[]=['id'=>(int)$row->id,'seq'=>(int)$row->seq,'level'=>$row->level,'at'=>(int)$row->at,'message'=>$row->message];
  $streams=DB::table('onenew_runtime_log_streams')->where('machine_id',$machine->id)->where('machine_identity',$identity)->where('last_received_at','>=',$cutoff);$last=(clone $streams)->max('last_received_at');
  DB::table('onenew_runtime_log_reads')->insert(['admin_id'=>$admin,'machine_id'=>$machine->id,'machine_identity'=>$identity,'result_count'=>count($entries),'searched'=>!empty($filters['search']),'created_at'=>time()]);
  return ['machine_id'=>(int)$machine->id,'status'=>$last?($last>=time()-60?'collecting':'waiting'):'upgrade_required','last_received_at'=>$last?(int)$last:null,'dropped'=>(int)(clone $streams)->sum('dropped'),'filtered'=>(int)(clone $streams)->sum('filtered'),'retention_days'=>7,'entries'=>$entries,'next_before_id'=>$more&&$entries?$entries[array_key_last($entries)]['id']:null,'total'=>$total,'page'=>$page,'per_page'=>$limit,'pages'=>(int)ceil($total/$limit)];
 }
 public function prune():void{$cutoff=time()-604800;foreach(['entries'=>'received_at','batches'=>'received_at','reads'=>'created_at','streams'=>'last_received_at'] as $table=>$field)DB::table('onenew_runtime_log_'.$table)->where($field,'<',$cutoff)->delete();}
}
