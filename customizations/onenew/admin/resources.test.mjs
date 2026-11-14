import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';
import {externalOrder,egressPayload,saveMachineEgress} from './src/resources.mjs';
import {patchBundle} from './patch.mjs';
const source=fs.readFileSync(process.env.ONENEW_ADMIN_UPSTREAM?`${process.env.ONENEW_ADMIN_UPSTREAM}/assets/index-CEIYH7i8.js`:new URL('../../../../xboard-admin-dist/assets/index-CEIYH7i8.js',import.meta.url),'utf8');
const walk=n=>!n||typeof n!=='object'?[]:[n,...[].concat(n.props?.children||[]).flatMap(walk)];
test('external ordering preserves real record IDs and moves only the requested neighbor',()=>{
 const rows=[{id:10},{id:20},{id:30}];assert.deepEqual(externalOrder(rows,30,-1),[10,30,20]);assert.deepEqual(externalOrder(rows,10,-1),[10,20,30]);assert.deepEqual(rows.map(r=>r.id),[10,20,30]);
});
test('machine payload applies global preference and only explicit node overrides',async()=>{
 const payload=egressPayload(7,{machine_identity:'machine-generation',mode:'prefer_ipv6',nodes:[{id:1,identity:'node-1',mode:'inherit'},{id:3,identity:'node-3',mode:'prefer_ipv4'},{id:5,identity:'node-5',mode:'default'}]});
 assert.deepEqual(payload,{machine_id:7,machine_identity:'machine-generation',mode:'prefer_ipv6',node_overrides:{3:'prefer_ipv4',5:'default'},node_identities:{1:'node-1',3:'node-3',5:'node-5'}});
 const calls=[];await saveMachineEgress(async(...args)=>{calls.push(args)},payload);
 assert.deepEqual(calls,[['/api/onenew/admin/machine-egress',payload,{baseURL:'/'}]]);
 assert.throws(()=>egressPayload(7,{mode:'ipv4_only',nodes:[]}));
});
test('actual pinned machine editor saves egress before closing and refreshing',async()=>{
 const patched=patchBundle(source,''),a=patched.indexOf('function n3t('),b=patched.indexOf('function i3t(',a),calls=[],cells=[];let cursor=0;
 const form={control:{},reset(){},trigger(){},formState:{isValid:true},handleSubmit:fn=>fn};
 const ctx={H:{useState:init=>{const i=cursor++;if(!(i in cells))cells[i]=init;return[cells[i],v=>cells[i]=v]},useRef:init=>{const i=cursor++;return cells[i]??=( {current:init})},useEffect(){}},
  Q:{Fragment:'fragment',jsx:(type,props)=>({type,props}),jsxs:(type,props)=>({type,props})},jy:()=>({t:key=>key}),Nv:()=>form,Mv:x=>x,t3t:()=>({}),
  TT:async()=>{calls.push('native');return{data:true}},RL:async(url,data)=>{calls.push([url,data])},IL(){},gE:{success:()=>calls.push('success'),error:()=>calls.push('error')},
  OnenewAdmin:{MachineEgress:'MachineEgress',saveMachineEgress}};
 for(const name of ['htt','gtt','Lf','YXt','ptt','mtt','_tt','vtt','zy','$y','ftt','btt','elt','e3t'])ctx[name]=name;
 const render=vm.runInNewContext(`(${patched.slice(a,b)})`,ctx);
 const nodes=walk(render({defaultValues:{id:7,name:'test'},type:'edit',refetch:()=>calls.push('refresh')}));
 const panel=nodes.find(n=>n.type==='MachineEgress');assert.equal(panel.props.machineId,7);
 const payload={machine_id:7,mode:'prefer_ipv6',node_overrides:{3:'prefer_ipv4'}};
 panel.props.onChange(()=>payload);
 await nodes.find(n=>n.type==='form').props.onSubmit({id:7,name:'test'});
 assert.deepEqual(calls,['native',['/api/onenew/admin/machine-egress',payload],'success','refresh']);
 assert.equal(cells[0],false);
 assert.ok(patched.includes('OnenewAdmin.ExternalNodes,{react:H,get:IL,post:RL}'));
});
