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
  const fixture={versions:['v0.1.16','v0.1.9','v0.1.12'],machines:[{machine_id:7,name:'fixture-server',is_active:true,capable:true,online:true,version:'v0.1.11',job:null}]};
 const props={react:R,get:async()=>({data:fixture}),post:async(...args)=>calls.push(args),machineId:7};
 const render=()=>{cursor=0;effectCursor=0;return RemoteUpgrade(props)};
 const visit=tree=>{const out=[];function scan(n){if(!n||typeof n!=='object')return;out.push(n);n.children?.forEach(scan)}scan(tree);return out};
 const button=(tree,text)=>visit(tree).find(n=>n.type==='button'&&n.children.includes(text));
 let tree=render();assert.equal(calls.length,0);button(tree,'远程升级').props.onClick();tree=render();
 for(const effect of effects.splice(0))cleanups.push(effect());await new Promise(resolve=>setImmediate(resolve));tree=render();
  const selector=visit(tree).find(node=>node.type==='select');
  assert.equal(selector.props.value,'v0.1.16');
  selector.props.onChange({target:{value:'v0.1.12'}});tree=render();
  assert.equal(calls.length,0);button(tree,'升级已选 1 台').props.onClick();tree=render();
 assert.ok(visit(tree).some(n=>n.props.role==='alertdialog'));assert.match(JSON.stringify(tree),/确认将 1 台服务器升级到 v0.1.12/);
 assert.match(JSON.stringify(tree),/短暂中断/);assert.equal(calls.length,0);
  await button(tree,'确认升级').props.onClick();assert.deepEqual(calls[0],['/api/onenew/admin/upgrades',{machine_ids:[7],version:'v0.1.12',confirmed:true},{baseURL:'/'}]);
  assert.equal(visit(render()).find(node=>node.type==='select').props.value,'v0.1.12');
 fixture.machines[0].job={id:'fixture-job',state:'rolled_back',target:'v0.1.12',reported_version:'v0.1.11',error_code:'health_failed'};
 tree=render();assert.match(JSON.stringify(tree),/已回滚/);assert.match(JSON.stringify(tree),/实际结果版本/);
 fixture.machines[0].job={...fixture.machines[0].job,state:'failed',error_code:'journal_failed'};
 tree=render();assert.match(JSON.stringify(tree),/升级记录写入失败/);
 cleanups.forEach(cleanup=>cleanup?.());
});

async function bulkHarness(t,machines,extra={}){
 const state=[],effects=[],dependencies=[],cleanups=[],posts=[];
 let cursor=0,effectCursor=0;
 const R={Fragment:'fragment',createElement:(type,props,...children)=>({type,props:props||{},children:children.flat(Infinity)}),
  useState:init=>{const slot=cursor++;if(!(slot in state))state[slot]=init;return [state[slot],next=>{state[slot]=typeof next==='function'?next(state[slot]):next}]},
  useEffect:(fn,deps)=>{const slot=effectCursor++;if(JSON.stringify(dependencies[slot])!==JSON.stringify(deps)){dependencies[slot]=deps;effects.push(fn)}}};
 const props={react:R,get:async()=>({data:{versions:['v0.1.16','v0.1.14'],machines}}),post:async(...args)=>posts.push(args),...extra};
 const render=()=>{cursor=0;effectCursor=0;return RemoteUpgrade(props)};
 const nodes=tree=>{const result=[];function walk(node){if(!node||typeof node!=='object')return;result.push(node);node.children?.forEach(walk)}walk(tree);return result};
 const find=label=>nodes(render()).find(node=>node.props['aria-label']===label);
 const button=label=>nodes(render()).find(node=>node.type==='button'&&node.children.includes(label));
 t.after(()=>cleanups.forEach(cleanup=>cleanup?.()));
 render().props.onClick();render();
 for(const effect of effects.splice(0))cleanups.push(effect());
 await new Promise(resolve=>setImmediate(resolve));
 return {render,nodes,find,button,posts,props};
}
const upgradeMachine=(id,extra={})=>({machine_id:id,name:`server-${id}`,is_active:true,capable:true,online:true,version:'v0.1.15',job:null,...extra});

test('bulk selection includes eligible offline nodes, excludes blocked nodes and preserves confirmation',async t=>{
 const harness=await bulkHarness(t,[upgradeMachine(1),upgradeMachine(2,{online:false}),upgradeMachine(3,{capable:false}),
  upgradeMachine(4,{is_active:false}),upgradeMachine(5,{version:'v0.1.16'}),upgradeMachine(6,{job:{state:'pending'}})]);
 const {find,button,posts,render}=harness;
 assert.match(JSON.stringify(render()),/最多同时升级 5 台/);
 assert.equal(find('全选可升级服务器').props.checked,false);
 find('选择服务器 1').props.onChange({target:{checked:true}});
 const partial=find('全选可升级服务器');
 assert.equal(partial.props['aria-checked'],'mixed');
 const element={};partial.props.ref(element);assert.equal(element.indeterminate,true);
 partial.props.onChange({target:{checked:true}});
 assert.equal(find('全选可升级服务器').props.checked,true);
 for(const id of [1,2])assert.equal(find(`选择服务器 ${id}`).props.checked,true);
 for(const id of [3,4,5,6])assert.equal(find(`选择服务器 ${id}`).props.checked,false);
 assert.equal(posts.length,0);
 button('升级已选 2 台').props.onClick();
 assert.match(JSON.stringify(render()),/确认将 2 台服务器升级到 v0.1.16/);
 await button('确认升级').props.onClick();
 assert.deepEqual(posts[0],[ '/api/onenew/admin/upgrades',{machine_ids:[1,2],version:'v0.1.16',confirmed:true},{baseURL:'/'} ]);
 assert.equal(find('全选可升级服务器').props.checked,false);
});

test('bulk selection respects the 100 machine batch limit and can be cleared',async t=>{
 const {find,button,render}=await bulkHarness(t,Array.from({length:105},(_,i)=>upgradeMachine(i+1)));
 find('全选可升级服务器').props.onChange({target:{checked:true}});
 assert.ok(button('升级已选 100 台'));
 assert.match(JSON.stringify(render()),/前 100 台可升级服务器/);
 assert.equal(find('选择服务器 101').props.disabled,true);
 find('选择服务器 1').props.onChange({target:{checked:false}});
 assert.equal(find('选择服务器 101').props.disabled,false);
 assert.equal(find('全选可升级服务器').props['aria-checked'],'mixed');
 button('清空选择').props.onClick();
 assert.ok(button('升级已选 0 台').props.disabled);
 find('全选可升级服务器').props.onChange({target:{checked:true}});
 find('全选可升级服务器').props.onChange({target:{checked:false}});
 assert.ok(button('升级已选 0 台').props.disabled);
});

test('bulk selection is disabled without eligible nodes and is scoped to server details',async t=>{
 const empty=await bulkHarness(t,[upgradeMachine(1,{version:'v0.1.16'})]);
 assert.equal(empty.find('全选可升级服务器').props.disabled,true);
 const detail=await bulkHarness(t,[upgradeMachine(1),upgradeMachine(2)],{machineId:2});
 detail.find('全选可升级服务器').props.onChange({target:{checked:true}});
 assert.equal(detail.find('选择服务器 1'),undefined);
 assert.equal(detail.find('选择服务器 2').props.checked,true);
});
