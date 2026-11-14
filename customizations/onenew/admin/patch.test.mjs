import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';
import path from 'node:path';
import {patchBundle} from './patch.mjs';
const source=fs.readFileSync(process.env.ONENEW_ADMIN_UPSTREAM?path.join(process.env.ONENEW_ADMIN_UPSTREAM,'assets/index-CEIYH7i8.js'):new URL('../../../../xboard-admin-dist/assets/index-CEIYH7i8.js',import.meta.url),'utf8');
test('patch refuses unexpected upstream bytes before modifying anything',()=>{
  assert.throws(()=>patchBundle(source+'\n',''),/SHA256/);
});
test('patched advanced component keeps TLS, multiplex and commits draft only through save',()=>{
  const patched=patchBundle(source,'');const a=patched.indexOf('function l5t('),b=patched.indexOf('const c5t=',a);
  const state=[true,'outbounds','[{"tag":"new"}]',null,'[{"outbound":"new"}]',null,''];let cursor=0;const saved=[];
  const ctx={H:{useState:()=>[state[cursor++],()=>{}]},Q:{jsx:(type,props)=>({type,props}),jsxs:(type,props)=>({type,props})},jy:()=>({t:(_key,fallback)=>fallback}),OnenewAdmin:{LandingEditor:'LandingEditor'}};
  for(const name of ['xtt','flt','htt','ptt','rw','mtt','_tt','sw','ow','aw','w4t','a5t','ftt'])ctx[name]=name;
  const form={watch:()=>[],getValues:()=>[],setValue:(k,v)=>saved.push([k,v])};
  const render=vm.runInNewContext(`(${patched.slice(a,b)})`,ctx);const tree=render({form,hasMultiplex:true});
  const nodes=[];function walk(node){if(!node||typeof node!=='object')return;nodes.push(node);for(const x of [].concat(node.props?.children||[]))walk(x)}walk(tree);
  assert.deepEqual(nodes.filter(n=>n.type==='ow').map(n=>n.props.value),['cert','multiplex','outbounds']);
  assert.equal(nodes.find(n=>n.type==='ow'&&n.props.value==='outbounds').props.children,'落地链接解析');
  assert.equal(nodes.filter(n=>n.type==='LandingEditor').length,1);assert.equal(saved.length,0);
  nodes.find(n=>n.type==='xtt'&&n.props.children==='Cancel').props.onClick();assert.equal(saved.length,0);
  nodes.find(n=>n.type==='xtt'&&n.props.children==='Save').props.onClick();
  assert.deepEqual(JSON.parse(JSON.stringify(saved)),[['custom_outbounds',[{tag:'new'}]],['custom_routes',[{outbound:'new'}]]]);
});
test('sidebar removal leaves original menu hierarchy and unrelated navigation intact',()=>{
  const patched=patchBundle(source,'');const a=patched.indexOf('Vlt=[')+4,b=patched.indexOf(',Wlt=',a),expression=patched.slice(a,b);
  const ctx={Q:{jsx:()=>null}};for(const match of expression.matchAll(/Q\.jsx\((\w+),/g))ctx[match[1]]=match[1];
  const menu=vm.runInNewContext(`(${expression})`,ctx),hrefs=[];function visit(entries){for(const item of entries){if(item.href)hrefs.push(item.href);visit(item.sub||[])}}visit(menu);
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
