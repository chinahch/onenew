import test from 'node:test';
import assert from 'node:assert/strict';
import {RemoteUpgrade,upgradeEligible,upgradePayload} from './src/remote-upgrade.mjs';
test('only capable active older machines without live jobs are eligible',()=>{
 const m={machine_id:1,is_active:true,capable:true,online:false,version:'v0.1.11'};
 assert.equal(upgradeEligible(m,'v0.1.12'),true);
 for(const changed of [{capable:false},{is_active:false},{version:'v0.1.12'},{version:'v0.2.0'},{version:null},{job:{state:'pending'}},{job:{state:'checking'}}])assert.equal(upgradeEligible({...m,...changed},'v0.1.12'),false);
 assert.equal(upgradeEligible({...m,job:{state:'rolled_back'}},'v0.1.12'),true);
});
test('bounded batch contains identifiers and fixed version only',()=>{
 assert.deepEqual(upgradePayload([1,'2',1],'v0.1.12'),{machine_ids:[1,2],version:'v0.1.12',confirmed:true});
 for(const ids of [[],[0],['bad'],Array.from({length:101},(_,i)=>i+1)])assert.throws(()=>upgradePayload(ids,'v0.1.12'));
 assert.throws(()=>upgradePayload([1],'https://example.test/command'));
});
test('actual server controls require selected-count confirmation before posting and retain status after reload',async()=>{
 const values=[],effects=[],cleanups=[],calls=[];let cursor=0,effectCursor=0;const dependencies=[];
 const R={Fragment:'fragment',createElement:(type,props,...children)=>({type,props:props||{},children:children.flat(Infinity)}),
  useState:init=>{const slot=cursor++;if(!(slot in values))values[slot]=init;return [values[slot],next=>{values[slot]=typeof next==='function'?next(values[slot]):next}]},
  useEffect:(fn,deps)=>{const slot=effectCursor++;if(JSON.stringify(dependencies[slot])!==JSON.stringify(deps)){dependencies[slot]=deps;effects.push(fn)}}};
 const fixture={versions:['v0.1.12'],machines:[{machine_id:7,name:'fixture-server',is_active:true,capable:true,online:true,version:'v0.1.11',job:null}]};
 const props={react:R,get:async()=>({data:fixture}),post:async(...args)=>calls.push(args),machineId:7};
 const render=()=>{cursor=0;effectCursor=0;return RemoteUpgrade(props)};
 const visit=tree=>{const out=[];function scan(n){if(!n||typeof n!=='object')return;out.push(n);n.children?.forEach(scan)}scan(tree);return out};
 const button=(tree,text)=>visit(tree).find(n=>n.type==='button'&&n.children.includes(text));
 let tree=render();assert.equal(calls.length,0);button(tree,'远程升级').props.onClick();tree=render();
 for(const effect of effects.splice(0))cleanups.push(effect());await new Promise(resolve=>setImmediate(resolve));tree=render();
 assert.equal(calls.length,0);button(tree,'升级已选 1 台').props.onClick();tree=render();
 assert.ok(visit(tree).some(n=>n.props.role==='alertdialog'));assert.match(JSON.stringify(tree),/确认将 1 台服务器升级到 v0.1.12/);
 assert.match(JSON.stringify(tree),/短暂中断/);assert.equal(calls.length,0);
 await button(tree,'确认升级').props.onClick();assert.deepEqual(calls[0],['/api/onenew/admin/upgrades',{machine_ids:[7],version:'v0.1.12',confirmed:true},{baseURL:'/'}]);
 fixture.machines[0].job={id:'fixture-job',state:'rolled_back',target:'v0.1.12',reported_version:'v0.1.11',error_code:'health_failed'};
 tree=render();assert.match(JSON.stringify(tree),/已回滚/);assert.match(JSON.stringify(tree),/实际结果版本/);
 fixture.machines[0].job={...fixture.machines[0].job,state:'failed',error_code:'journal_failed'};
 tree=render();assert.match(JSON.stringify(tree),/升级记录写入失败/);
 cleanups.forEach(cleanup=>cleanup?.());
});
