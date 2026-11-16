<?php
function abort_unless($condition, $status, $message = '') { if (!$condition) throw new RuntimeException($message, $status); }
require __DIR__.'/../app/Onenew/ProbeStages.php';
use App\Onenew\ProbeStages;
function check($condition) { if (!$condition) throw new RuntimeException('Stage assertion failed'); }
$expected = [['id'=>'landing_1','name'=>'Saved name'],['id'=>'landing_2','name'=>'Second']];
function stages($entryCode, $codes) {
    return ['version'=>1,'entry'=>['state'=>$entryCode===''?'succeeded':'failed','error_code'=>$entryCode,'source'=>'public_roundtrip'],
        'landings'=>array_map(fn($code,$i)=>['id'=>'landing_'.($i+1),'state'=>$code===''?'succeeded':'failed','error_code'=>$code], $codes, array_keys($codes))];
}
foreach ([['',['',''],'online'],['',['proxy_failed',''],'partial_landing_fault'],['',['proxy_failed','proxy_failed'],'landing_fault'],
    ['proxy_failed',['',''],'node_fault'],['transport_failed',['',''],'unknown'],['',['transport_failed',''],'unknown'],['transport_failed',['transport_failed','transport_failed'],'unknown'],['timeout',['',''],'unknown'],['target_failed',['',''],'unknown'],['proxy_failed',['proxy_failed','proxy_failed'],'node_fault'],['transport_failed',['proxy_failed','proxy_failed'],'landing_fault'],['timeout',['proxy_failed','proxy_failed'],'landing_fault'],['transport_failed',['proxy_failed','target_failed'],'partial_landing_fault'],['timeout',['proxy_failed',''],'partial_landing_fault'],['',['target_failed',''],'unknown']] as [$error,$codes,$health]) {
    $r=ProbeStages::accept($expected,stages($error,$codes),$error===''?'succeeded':'failed',$error);
    check($r['health_state']===$health);
    check($r['stages']['landings'][0]['name']==='Saved name');
}
check(ProbeStages::accept([],stages('proxy_failed',[]),'failed','proxy_failed')['health_state']==='node_fault');
check(ProbeStages::accept($expected,null,'succeeded','')['stage_error']==='upgrade_required');
foreach (['missing','duplicate','extra','mismatch','free_error','inconsistent'] as $bad) {
    $s=stages('',['','']);
    if($bad==='missing') array_pop($s['landings']);
    if($bad==='duplicate') $s['landings'][1]['id']='landing_1';
    if($bad==='extra') $s['landings'][]=$s['landings'][0];
    if($bad==='mismatch') $s['landings'][0]['id']='unknown';
    if($bad==='free_error') $s['landings'][0]=['id'=>'landing_1','state'=>'failed','error_code'=>'secret password'];
    if($bad==='inconsistent') $s['entry']['state']='failed';
    try { ProbeStages::accept($expected,$s,'succeeded',''); throw new LogicException('Invalid stages accepted'); }
    catch(RuntimeException $e) { check($e->getCode()===422); }
}
$local=stages('', ['', '']); $local['entry']['source']='local_listener_roundtrip';
check(ProbeStages::accept($expected,$local,'succeeded','','local_listener_roundtrip')['health_state']==='online');
try { ProbeStages::accept($expected,$local,'succeeded','','public_roundtrip'); throw new LogicException('False public provenance accepted'); } catch(RuntimeException $e) { check($e->getCode()===422); }
echo "Stage classification, provenance, completeness and error allowlist checks passed\n";
