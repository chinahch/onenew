import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';
import path from 'node:path';
import {patchBundle} from './patch.mjs';
import {prepareLandingSave} from './src/landing-state.mjs';
const source=fs.readFileSync(process.env.ONENEW_ADMIN_UPSTREAM?path.join(process.env.ONENEW_ADMIN_UPSTREAM,'assets/index-CEIYH7i8.js'):new URL('../../../../xboard-admin-dist/assets/index-CEIYH7i8.js',import.meta.url),'utf8');
test('patch refuses unexpected upstream bytes before modifying anything',()=>{
  assert.throws(()=>patchBundle(source+'\n',''),/SHA256/);
});
test('machine list prefers the shared server status and supports older API responses',()=>{
  const patched=patchBundle(source,'');
  const status=vm.runInNewContext(`(${patched.slice(patched.indexOf('function g5t('),patched.indexOf('function p5t('))})`);
  assert.equal(status({connection_status:'online',is_active:true,last_seen_at:1}),'online');
  assert.equal(status({connection_status:'offline',is_active:true,last_seen_at:Date.now()/1000}),'offline');
  assert.equal(status({is_active:true,last_seen_at:Date.now()/1000-46}),'online');
});
test('patched advanced component keeps TLS, multiplex and commits draft only through save',()=>{
  const patched=patchBundle(source,'');const a=patched.indexOf('function l5t('),b=patched.indexOf('const c5t=',a);
  const state=[true,'outbounds','[{"tag":"new"}]',null,'[{"outbound":"new"}]',null,''];let cursor=0;const saved=[];
  const ctx={IL:()=>{},RL:()=>{},H:{useState:()=>[state[cursor++],()=>{}]},Q:{jsx:(type,props)=>({type,props}),jsxs:(type,props)=>({type,props})},jy:()=>({t:(_key,fallback)=>fallback}),OnenewAdmin:{LandingEditor:'LandingEditor',prepareLandingSave}};
  for(const name of ['xtt','flt','htt','ptt','rw','mtt','_tt','sw','ow','aw','w4t','a5t','ftt'])ctx[name]=name;
  const form={watch:()=>[],getValues:k=>k==='id'?42:k==='machine_id'?7:[],setValue:(k,v)=>saved.push([k,v])};
  const render=vm.runInNewContext(`(${patched.slice(a,b)})`,ctx);const tree=render({form,hasMultiplex:true});
  const nodes=[];function walk(node){if(!node||typeof node!=='object')return;nodes.push(node);for(const x of [].concat(node.props?.children||[]))walk(x)}walk(tree);
  assert.deepEqual(nodes.filter(n=>n.type==='ow').map(n=>n.props.value),['cert','multiplex']);
  assert.equal(nodes.filter(n=>n.type==='LandingEditor').length,0);assert.equal(saved.length,0);
  nodes.find(n=>n.type==='xtt'&&n.props.children==='Cancel').props.onClick();assert.equal(saved.length,0);
  nodes.find(n=>n.type==='xtt'&&n.props.children==='Save').props.onClick();
  assert.deepEqual(saved,[]);
});
test('sidebar removal leaves original menu hierarchy and unrelated navigation intact',()=>{
  const patched=patchBundle(source,'');const a=patched.indexOf('Vlt=[')+4,b=patched.indexOf(',Wlt=',a),expression=patched.slice(a,b);
  const ctx={Q:{jsx:(type,props)=>({type,props})},H:{},IL:()=>{},OnenewAdmin:{AttentionIcon:'AttentionIcon'}};for(const match of expression.matchAll(/Q\.jsx\((\w+),/g))ctx[match[1]]=match[1];
  const menu=vm.runInNewContext(`(${expression})`,ctx),hrefs=[];function visit(entries){for(const item of entries){if(item.href)hrefs.push(item.href);visit(item.sub||[])}}visit(menu);
  assert.equal(menu.find(row=>row.id==='subscription-management').icon.props.kind,'orders');
  assert.equal(menu.find(row=>row.id==='system-management').icon.props.kind,'energy');
  assert.ok(hrefs.includes('/config/payment'));assert.ok(hrefs.includes('/finance/order'));assert.ok(hrefs.includes('/server/manage'));assert.ok(hrefs.includes('/user/manage'));
  for(const removed of ['/config/knowledge','/finance/coupon','/finance/gift-card','/user/ticket'])assert.ok(!hrefs.includes(removed));
});
test('health column follows node ID and receives original proxy health record',()=>{
  const patched=patchBundle(source,'');const a=patched.indexOf('{accessorKey:"id",header:({column:e})=>Q.jsx(eQt,{column:e,title:t("columns.nodeId")})'),b=patched.indexOf('{accessorKey:"name"',a);
  const ctx={Q:{jsx:(type,props)=>({type,props})},H:{},OnenewAdmin:{HealthCell:'HealthCell'}};
  const columns=vm.runInNewContext(`([${patched.slice(a,b)}])`,ctx);
  assert.equal(columns[0].accessorKey,'id');assert.equal(columns[1].header(),'节点状态');assert.equal(columns[2].accessorKey,'show');
  const node={id:42,proxy_health:{state:'failed',finished_at:'2026-11-14T11:59:00Z'}};
  assert.equal(columns[1].cell({row:{original:node}}).props.node,node);
});

test('server detail shows the upgrade command next to installation instructions',()=>{
 const patched=patchBundle(source,'');const a=patched.indexOf('function R3t('),b=patched.indexOf('function O3t(',a);
 assert.ok(a>=0&&b>a);
 assert.ok(patched.slice(a,b).includes('OnenewAdmin.UpgradeCommand,{react:H,command:a?.upgrade_command,loading:l}'));
});

test('main node form and toolbar expose reusable landings without an advanced routing pane',()=>{
 const patched=patchBundle(source,'');
 assert.ok(patched.includes('Q.jsx(OnenewAdmin.LandingLibraryButton,{react:H,get:IL,post:RL})'));
 assert.ok(patched.includes('Q.jsx(OnenewAdmin.NodeLandingField,{react:H,form:x,get:IL,post:RL})'));
 assert.ok(patched.includes('onenew_landing_bindings:gy(hy()).nullable().default(null)'));
 const a=patched.indexOf('function l5t('),b=patched.indexOf('const c5t=',a),advanced=patched.slice(a,b);
 assert.ok(!advanced.includes('value:"outbounds"'));
 assert.ok(!advanced.includes('value:"routes"'));
 assert.ok(!advanced.includes('setValue(u,'));
});

test('uTLS fingerprint selector includes chrome140 (support x25519mlkem768)',()=>{
 const patched=patchBundle(source,'');
 const match=patched.match(/b4t=(\[[^\]]+\])/);
 assert.ok(match,'uTLS fingerprint list b4t missing');
 const options=vm.runInNewContext(`(${match[1]})`);
 assert.deepEqual(JSON.parse(JSON.stringify(options.find(item=>item.value==='chrome140'))),{
  value:'chrome140',
  label:'chrome140 (support x25519mlkem768)',
 });
 assert.ok(patched.includes('OnenewAdmin.CloudflaredTunnel,{react:H,get:IL,post:RL,machineId:e.original.id,machineName:e.original.name,tunnelActive:!!e.original.tunnel_installed}'));
});

