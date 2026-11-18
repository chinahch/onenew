import test from 'node:test';
import assert from 'node:assert/strict';
import {runtimeLogParams,fetchRuntimeLogs,RuntimeLogEntries,RuntimeLogs} from './src/runtime-logs.mjs';
test('bounded filtered cursor requests use the machine endpoint',async()=>{
 const calls=[];await fetchRuntimeLogs(async(...args)=>{calls.push(args);return {status:'collecting',entries:[]}},7,{level:'error',search:'x'.repeat(120),from:'2026-11-18T08:00',to:'2026-11-18T09:00',before_id:80,limit:500});
 assert.equal(calls[0][0],'/api/onenew/admin/machines/7/runtime-logs');
 assert.equal(calls[0][1].baseURL,'/');
 const params=calls[0][1].params;assert.equal(params.limit,100);assert.equal(params.search.length,100);assert.equal(params.before_id,80);assert.equal(params.level,'error');assert.ok(params.to>params.from);
 assert.deepEqual(runtimeLogParams({level:'invalid',before_id:-1,limit:0}),{limit:50});assert.throws(()=>fetchRuntimeLogs(()=>{},'../../bad',{}));
 assert.equal(runtimeLogParams({page:99999}).page,10000);
});
test('failed refresh shows safe migration guidance, clears old empty state and recovers on retry',async()=>{
 const values=[],refs=[],effects=[],deps=[],cleanups=[];let slot=0,ref=0,effect=0,failure=null;
 const R={Fragment:'fragment',createElement:(type,props,...children)=>({type,props:props||{},children:children.flat(Infinity)}),useState:init=>{const id=slot++;if(!(id in values))values[id]=init;return[values[id],next=>values[id]=typeof next==='function'?next(values[id]):next]},useRef:init=>refs[ref++]??(refs[ref-1]={current:init}),useEffect:(fn,next)=>{const id=effect++;if(!deps[id]||next.some((value,i)=>value!==deps[id][i])){deps[id]=next;effects.push(()=>{cleanups[id]?.();cleanups[id]=fn()})}}};
 const get=async()=>{if(failure)throw failure;return {status:'collecting',entries:[],retention_days:7,dropped:3,pages:1}};
 const render=()=>{slot=ref=effect=0;return RuntimeLogs({react:R,get,machineId:3})};
 const nodes=tree=>{const result=[];function walk(n){if(!n||typeof n!=='object')return;result.push(n);n.children?.forEach(walk)}walk(tree);return result};
 const settle=async()=>{for(const run of effects.splice(0))run();await new Promise(resolve=>setTimeout(resolve,230))};
 const refresh=async()=>{nodes(render()).find(n=>n.type==='button'&&n.children.includes('刷新')).props.onClick();render();await settle();return JSON.stringify(render())};
 nodes(render()).find(n=>n.type==='button').props.onClick();render();await settle();assert.match(JSON.stringify(render()),/保留期内暂无匹配日志/);
 failure={response:{status:503,data:{code:'runtime_logs_schema_missing',message:'private SQL password=fixture-secret',trace:['private-stack']}},message:'private-error'};
 let output=await refresh();assert.match(output,/数据库迁移/);assert.match(output,/php artisan migrate --force/);assert.doesNotMatch(output,/暂无匹配日志|已丢弃|private/);
 failure={response:{status:500,data:{code:'untrusted-private-code',message:'private SQL password=fixture-secret'}}};
 output=await refresh();assert.match(output,/无法读取运行日志/);assert.match(output,/刷新/);assert.doesNotMatch(output,/migrate|暂无匹配日志|private/);
 failure=null;output=await refresh();assert.match(output,/暂无匹配日志/);assert.match(output,/已丢弃 3 条/);assert.doesNotMatch(output,/无法读取|数据库迁移/);
 cleanups.forEach(fn=>fn?.());
});
test('modal filters reset page, selectable pages request the correct machine, old nodes require upgrade',async()=>{
 const values=[],refs=[],effects=[],deps=[],cleanups=[],calls=[];let slot=0,ref=0,effect=0;
 const R={Fragment:'fragment',createElement:(type,props,...children)=>({type,props:props||{},children:children.flat(Infinity)}),useState:init=>{const id=slot++;if(!(id in values))values[id]=init;return[values[id],next=>values[id]=typeof next==='function'?next(values[id]):next]},useRef:init=>refs[ref++]??(refs[ref-1]={current:init}),useEffect:(fn,next)=>{const id=effect++;if(!deps[id]||next.some((value,i)=>value!==deps[id][i])){deps[id]=next;effects.push(()=>{cleanups[id]?.();cleanups[id]=fn()})}}};
 const props={react:R,machineId:7,get:async(...args)=>{calls.push(args);return {status:'upgrade_required',entries:[],pages:3,retention_days:7,dropped:12}}};
 const render=()=>{slot=ref=effect=0;return RuntimeLogs(props)};
 const nodes=tree=>{const result=[];function walk(n){if(!n||typeof n!=='object')return;result.push(n);n.children?.forEach(walk)}walk(tree);return result};
 const settle=async()=>{for(const run of effects.splice(0))run();await new Promise(resolve=>setTimeout(resolve,230))};
 let tree=render();nodes(tree).find(n=>n.type==='button').props.onClick();tree=render();await settle();tree=render();
 assert.match(JSON.stringify(tree),/请先升级节点/);assert.match(JSON.stringify(tree),/已丢弃 12 条/);assert.ok(nodes(tree).some(n=>n.type==='dialog'));
 nodes(tree).find(n=>n.props['aria-label']==='日志页数').props.onChange({target:{value:'2'}});tree=render();await settle();assert.equal(calls.at(-1)[1].params.page,3);
 tree=render();nodes(tree).find(n=>n.type==='input'&&n.props.maxLength===100).props.onChange({target:{value:'failure'}});tree=render();await settle();assert.equal(calls.at(-1)[1].params.page,1);assert.equal(calls.at(-1)[1].params.search,'failure');
 nodes(render()).find(n=>n.type==='button'&&n.children.includes('关闭')).props.onClick();render();await settle();cleanups.forEach(fn=>fn?.());
});
test('messages are text children including markup and multiline data',()=>{
 const payload='<img src=x onerror=alert(1)>\n<script>evil()</script>';
 const tree=RuntimeLogEntries({react:{createElement:(type,props,...children)=>({type,props,children})},entries:[{id:1,level:'error',at:123,message:payload}]});
 assert.ok(JSON.stringify(tree).includes(payload.replaceAll('\n','\\n')));const walk=n=>{if(!n||typeof n!=='object')return;assert.equal(n.props?.dangerouslySetInnerHTML,undefined);n.children?.flat(Infinity).forEach(walk)};walk(tree);
});

import fs from 'node:fs';
import path from 'node:path';
import {patchBundle} from './patch.mjs';
const source=fs.readFileSync(path.join(process.env.ONENEW_ADMIN_UPSTREAM||'../../../../xboard-admin-dist','assets/index-CEIYH7i8.js'),'utf8');
test('machine status has an adjacent runtime log modal entry and detail retains an entry',()=>{
 const patched=patchBundle(source,'');
 assert.ok(patched.includes('children:[Q.jsx(s3t,{machine:e.original,t:t}),Q.jsx(OnenewAdmin.RuntimeLogs,{react:H,get:IL,machineId:e.original.id})]'));
 assert.ok(patched.includes('Q.jsx(OnenewAdmin.RuntimeLogs,{react:H,get:IL,machineId:e.id})'));
});
