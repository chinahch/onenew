import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';
import path from 'node:path';
import {patchBundle} from './patch.mjs';
import {TrafficDetails,trafficColumns,fetchTrafficRecords,fetchTrafficDay,archiveDay} from './src/traffic.mjs';
const source=fs.readFileSync(process.env.ONENEW_ADMIN_UPSTREAM?path.join(process.env.ONENEW_ADMIN_UPSTREAM,'assets/index-CEIYH7i8.js'):new URL('../../../../xboard-admin-dist/assets/index-CEIYH7i8.js',import.meta.url),'utf8').replaceAll('\r\n','\n');
const patched=patchBundle(source,'');
const element=(type,props,...children)=>({type,props:{...props,...(children.length?{children}: {})}});
function nodes(tree){const result=[];function walk(node){if(!node||typeof node!=='object')return;if(Array.isArray(node)){node.forEach(walk);return}result.push(node);walk(node.props?.children)}walk(tree);return result}
function text(tree){if(tree===null||tree===undefined||tree===false)return '';if(typeof tree!=='object')return String(tree);if(Array.isArray(tree))return tree.map(text).join(' ');return text(tree.props?.children)}
function hooks(initial=[]){
 const state=[...initial],pending=[];let cursor=0;
 const react={Fragment:'Fragment',createElement:element,
  useState(value){const index=cursor++;if(!(index in state))state[index]=typeof value==='function'?value():value;return[state[index],next=>{state[index]=typeof next==='function'?next(state[index]):next}]},
  useRef(value){const index=cursor++;if(!(index in state))state[index]={current:value};return state[index]},
  useEffect(effect,deps){const index=cursor++,previous=state[index];if(!previous||deps.some((value,i)=>value!==previous.deps[i])){state[index]={deps,cleanup:previous?.cleanup};pending.push(()=>{state[index].cleanup?.();state[index].cleanup=effect()})}}
 };
 return{react,state,render(component,props){cursor=0;return component({...props,react})},effects(){while(pending.length)pending.shift()()},cleanup(){for(const entry of state)entry?.cleanup?.()}};
}
const tick=()=>new Promise(resolve=>setImmediate(resolve));
const epoch=Date.parse('2026-03-08T00:00:00Z')/1000;

test('native archive epoch is passed intact and date formatting never uses browser timezone',async()=>{
 const before=process.env.TZ;process.env.TZ='America/Los_Angeles';
 try{assert.equal(archiveDay(epoch,'UTC'),'2026-03-08');assert.equal(archiveDay(Date.parse('2026-03-08T08:00:00Z')/1000,'America/Los_Angeles'),'2026-03-08');assert.throws(()=>archiveDay(epoch),/时区/)}finally{if(before===undefined)delete process.env.TZ;else process.env.TZ=before}
 const calls=[],get=(...args)=>{calls.push(args);return Promise.resolve({data:[]})};
 await fetchTrafficRecords({get,userId:42,page:3,perPage:20});
 await fetchTrafficDay({get,userId:42,recordAt:String(epoch),tab:'domains',page:2});
 assert.equal(calls[0][0],'/api/onenew/admin/traffic/records');assert.equal(calls[0][1].baseURL,'/');
 assert.deepEqual(calls[0][1].params,{user_id:42,page:3,per_page:20});
 assert.equal(calls[1][0],'/api/onenew/admin/traffic/domains');assert.equal(calls[1][1].params.record_at,epoch);
 assert.throws(()=>fetchTrafficDay({get,userId:42,recordAt:epoch*1000}),/秒级/);
 assert.throws(()=>fetchTrafficDay({get,userId:42,recordAt:epoch,tab:'content'}),/类型/);
});

test('date columns expose only daily native records and preserve other original columns',()=>{
 const original=[{accessorKey:'record_at',cell:()=>element('time',{},'original')},{accessorKey:'u',cell:()=>123}],selected=[];
 const columns=trafficColumns({react:{createElement:element},columns:original,timezone:'UTC',select:value=>selected.push(value)});
 assert.equal(columns[1],original[1]);
 const button=columns[0].cell({row:{original:{record_at:epoch,date:'2026-03-08',record_type:'d'}}});assert.equal(button.type,'button');button.props.onClick();assert.equal(selected[0].recordAt,epoch);
 const month=columns[0].cell({row:{original:{record_at:epoch,date:'2026-03-08',record_type:'m'}}});assert.equal(month.type,'span');assert.match(text(month),/月汇总/);assert.equal(month.props.onClick,undefined);
 const unknown=trafficColumns({react:{createElement:element},columns:original,select:()=>{throw Error('not clickable')}})[0].cell({row:{original:{record_at:epoch}}});assert.equal(unknown.type,'time');
});

function originalModal(){
 const runner=hooks([true,{pageIndex:2,pageSize:20},null]),queries=[],requests=[],row={record_at:epoch,date:'2026-03-08',record_type:'d',u:100,d:200,server_rate:2};let tableOptions;
 const context={H:runner.react,Q:{jsx:(type,props)=>({type,props}),jsxs:(type,props)=>({type,props})},jy:()=>({t:key=>key}),OnenewAdmin:{TrafficDetails:'TrafficDetails',trafficColumns,fetchTrafficRecords},RL:()=>{},IL:(...args)=>{requests.push(args);return Promise.resolve({data:[]})},kS:()=> '2026-03-07',RS:n=>`${n}B`,Im:(...args)=>args.join(' '),kGt:(render,ctx)=>typeof render==='function'?render(ctx):render,
  pC:options=>{queries.push(options);return{data:{data:[row],total:81,timezone:'UTC'},isLoading:false}},vGt:()=>null,
  NGt:options=>{tableOptions=options;return{getHeaderGroups:()=>[],getRowModel:()=>({rows:[{id:'row',getIsSelected:()=>false,getVisibleCells:()=>options.columns.map((column,index)=>({id:index,column:{columnDef:column},getContext:()=>({row:{original:row}})}))}]}),getState:()=>({pagination:runner.state[1]}),getPageCount:()=>5,getCanPreviousPage:()=>true,getCanNextPage:()=>true,previousPage:()=>{},nextPage:()=>{},setPageSize:()=>{}}}
 };
 for(const name of ['htt','gtt','ptt','mtt','_tt','EGt','LGt','IGt','RGt','DGt','OGt','q$t','yzt','Czt','wzt','Nzt','Lzt','xtt','S8t','k8t','w8t','C8t','nKt'])context[name]=name;
 const start=patched.indexOf('N8t=[{accessorKey:"record_at"'),end=patched.indexOf('function L8t(',start);
 const component=vm.runInNewContext(`var ${patched.slice(start,end)}; E8t`,context);
 const render=(userId=42)=>runner.render(component,{user_id:userId,dialogTrigger:element('original-trigger',{})});
 return{runner,render,queries,requests,table:()=>tableOptions};
}

test('real original modal date callback opens nested panel; back/close preserve original list and pagination',async()=>{
 const ui=originalModal();let tree=ui.render();assert.equal(tree.type,'htt');assert.ok(nodes(tree).some(n=>n.type==='original-trigger'));
 assert.match(text(tree),/trafficRecord.title/);assert.equal(ui.table().state.pagination.pageIndex,2);
 await ui.queries.at(-1).queryFn();assert.equal(ui.requests[0][1].params.page,3);assert.equal(ui.requests[0][1].baseURL,'/');
 const date=nodes(tree).find(n=>n.type==='button'&&n.props['aria-label']?.startsWith('查看 '));assert.equal(text(date),'2026-03-08');date.props.onClick();
 tree=ui.render();const detail=nodes(tree).find(n=>n.type==='TrafficDetails');assert.ok(detail);assert.equal(detail.props.selection.recordAt,epoch);assert.equal(detail.props.userId,42);assert.equal(nodes(tree).some(n=>n.type==='EGt'),false);
 assert.equal(nodes(ui.render(43)).some(n=>n.type==='TrafficDetails'),false,'a different user must not see the selected user record');
 detail.props.onBack();tree=ui.render();assert.ok(nodes(tree).some(n=>n.type==='EGt'));assert.equal(ui.runner.state[1].pageIndex,2);
 nodes(tree).find(n=>n.type==='button'&&n.props['aria-label']?.startsWith('查看 ')).props.onClick();tree=ui.render();tree.props.onOpenChange(false);assert.equal(ui.runner.state[2],null);assert.equal(ui.runner.state[0],false);
 const originalFollowing=source.slice(source.indexOf('function L8t('),source.indexOf('function ',source.indexOf('function L8t(')+15));
 assert.ok(patched.includes(originalFollowing),'adjacent original reset modal stays byte-identical');
});

const selection={recordAt:epoch,date:'2026-03-08',timezone:'UTC'};
const nodeData={data:[{server_id:7,node_name:'Tokyo',server_type:'vless',server_rate:1,raw_u:100,raw_d:200,total:300},{server_id:7,node_name:'Tokyo',server_type:'vless',server_rate:2,raw_u:50,raw_d:50,total:200}],total:2,per_page:20,date:'2026-03-08',timezone:'UTC',totals:{total:500},native_totals:{total:900},unattributed:{total:400},coverage:{state:'partial',reason:'legacy_node_breakdown_unavailable'}};

test('detail tabs render genuine rate rows, billed coverage, domain reports and accessible keyboard navigation',async()=>{
 const requests=[],runner=hooks(),domainData={data:[{domain:'reported.example.invalid',server_id:8,node_name:'Deleted snapshot',server_type:'trojan',node_deleted:true,first_seen:'2026-03-08T01:02:03Z',last_seen:'2026-03-08T01:04:05Z',hit_count:6}],total:1,per_page:20,date:selection.date,timezone:'UTC',coverage:{state:'available',reason:''}};
 const props={get:async(url,options)=>{requests.push([url,options]);return url.endsWith('/audit-config')?{enabled:false,retention_days:7,metadata_only:true}:url.endsWith('/nodes')?nodeData:domainData},format:n=>`${n}B`,userId:42,selection,onBack:()=>{}};
 let tree=runner.render(TrafficDetails,props);runner.effects();await tick();tree=runner.render(TrafficDetails,props);
 assert.match(text(tree),/500B/);assert.match(text(tree),/900B/);assert.match(text(tree),/400B/);assert.match(text(tree),/历史记录/);assert.equal(nodes(tree).filter(n=>n.type==='tr').length,3,'same node at two rates remains two rows');
 const tabs=nodes(tree).filter(n=>n.props?.role==='tab');assert.equal(tabs.length,2);assert.equal(tabs[0].props['aria-selected'],true);assert.equal(tabs[1].props.tabIndex,-1);
 tabs[1].props.onClick();tree=runner.render(TrafficDetails,props);assert.equal(nodes(tree).some(n=>n.type==='table'),false,'old tab data hidden before next response');runner.effects();await tick();tree=runner.render(TrafficDetails,props);
 assert.match(text(tree),/reported.example.invalid/);assert.match(text(tree),/Deleted snapshot（已删除）/);assert.match(text(tree),/01:02:03/);assert.match(text(tree),/01:04:05/);assert.match(text(tree),/6/);assert.doesNotMatch(text(tree),/暂无域名记录/);
 assert.equal(requests.at(-1)[0],'/api/onenew/admin/traffic/domains');assert.equal(requests.at(-1)[1].params.record_at,epoch);
 let focused=false,prevented=false;const currentTabs=nodes(tree).filter(n=>n.props?.role==='tab');currentTabs[0].props.ref({focus:()=>{focused=true}});currentTabs[1].props.onKeyDown({key:'ArrowLeft',preventDefault:()=>{prevented=true}});assert.ok(focused&&prevented);
 tree=runner.render(TrafficDetails,props);assert.equal(nodes(tree).find(n=>n.props?.role==='tab').props['aria-selected'],true);
 runner.cleanup();
});

test('historical empty domain records are explicit and never reconstructed from IPs or node totals',async()=>{
 const runner=hooks(),props={get:async()=>({data:[],total:0,per_page:20,date:selection.date,timezone:'UTC',coverage:{state:'unavailable',reason:'no_reported_domain_records'}}),format:n=>`${n}B`,userId:42,selection,onBack:()=>{}};
 let tree=runner.render(TrafficDetails,props);nodes(tree).find(n=>n.props?.role==='tab'&&text(n)==='访问域名').props.onClick();tree=runner.render(TrafficDetails,props);runner.effects();await tick();tree=runner.render(TrafficDetails,props);
 assert.match(text(tree),/暂无域名记录/);assert.match(text(tree),/该日暂无节点报告的域名记录/);assert.equal(nodes(tree).some(n=>n.type==='table'),false);runner.cleanup();
});

test('late responses for an earlier tab are discarded rather than shown under domain records',async()=>{
 const runner=hooks();let finish;const props={get:url=>url.endsWith('/nodes')?new Promise(resolve=>{finish=resolve}):Promise.resolve({data:[],total:0,per_page:20,coverage:{state:'unavailable'}}),format:n=>`${n}B`,userId:42,selection,onBack:()=>{}};
 let tree=runner.render(TrafficDetails,props);runner.effects();nodes(tree).find(n=>n.props?.role==='tab'&&text(n)==='访问域名').props.onClick();tree=runner.render(TrafficDetails,props);runner.effects();await tick();finish(nodeData);await tick();tree=runner.render(TrafficDetails,props);assert.match(text(tree),/暂无域名记录/);assert.doesNotMatch(text(tree),/Tokyo/);runner.cleanup();
});

test('domain collection changes only after an explicit native admin POST and preserves actual retention',async()=>{
 const runner=hooks(),writes=[];let enabled=false;
 const props={get:async url=>url.endsWith('/audit-config')?{enabled,retention_days:14,metadata_only:true}:{data:[],total:0,per_page:20,coverage:{state:'disabled',reason:'域名收集已停用'}},post:async(...args)=>{writes.push(args);enabled=args[1].enabled;return{enabled,retention_days:14,metadata_only:true}},format:n=>`${n}B`,userId:42,selection,onBack:()=>{}};
 let tree=runner.render(TrafficDetails,props);nodes(tree).find(n=>n.props?.role==='tab'&&text(n)==='访问域名').props.onClick();tree=runner.render(TrafficDetails,props);runner.effects();await tick();tree=runner.render(TrafficDetails,props);
 assert.equal(writes.length,0);assert.match(text(tree),/已停用/);assert.match(text(tree),/保留 14 天/);
 await nodes(tree).find(n=>n.type==='button'&&text(n)==='启用域名收集').props.onClick();
 assert.equal(writes.length,1);assert.equal(writes[0][0],'/api/onenew/admin/traffic/audit-config');assert.deepEqual(writes[0][1],{enabled:true,retention_days:14});assert.equal(writes[0][2].baseURL,'/');
 tree=runner.render(TrafficDetails,props);runner.effects();await tick();tree=runner.render(TrafficDetails,props);assert.match(text(tree),/域名元数据收集： 已启用/);assert.ok(nodes(tree).some(n=>n.type==='button'&&text(n)==='停用域名收集'));assert.match(text(tree),/暂无域名记录/);runner.cleanup();
});

test('audit-setting failures do not hide genuine domain records or pretend collection is enabled',async()=>{
 const runner=hooks(),props={get:async url=>{if(url.endsWith('/audit-config'))throw Error('设置读取失败');return{data:[{domain:'actual.example.invalid',node_name:'Actual node',server_id:1,first_seen:'2026-03-08T01:00:00Z',hit_count:1}],total:1,coverage:{state:'available'}}},post:async()=>{throw Error('must not save without configuration')},format:n=>`${n}B`,userId:42,selection,onBack:()=>{}};
 let tree=runner.render(TrafficDetails,props);nodes(tree).find(n=>n.props?.role==='tab'&&text(n)==='访问域名').props.onClick();tree=runner.render(TrafficDetails,props);runner.effects();await tick();tree=runner.render(TrafficDetails,props);assert.match(text(tree),/actual.example.invalid/);assert.match(text(tree),/设置读取失败/);assert.equal(nodes(tree).some(n=>n.type==='button'&&text(n)==='启用域名收集'),false);runner.cleanup();
});

test('actual API coverage enums render Chinese notices and never expose unknown internal codes',async()=>{
 const cases=[
  ['nodes','legacy_node_breakdown_unavailable','历史记录未保存逐节点明细，无法还原。'],
  ['nodes','no_archived_traffic','该日暂无归档流量。'],
  ['nodes','archive_totals_do_not_match_recorded_node_breakdown','节点明细与归档总量不一致，请以归档总量为准。'],
  ['domains','domain_records_outside_retention','该日域名记录已超出保留期限。'],
  ['domains','domain_collection_disabled','域名收集已停用，暂无新增域名记录。'],
  ['domains','no_reported_domain_records','该日暂无节点报告的域名记录。'],
  ['nodes','future_internal_node_reason','逐节点明细暂不完整，请以归档总量为准。'],
  ['domains','future_internal_domain_reason','域名记录暂不可用。']
 ];
 for(const [tab,reason,expected] of cases){
  const runner=hooks(),props={get:async url=>url.endsWith('/audit-config')?{enabled:false,retention_days:7,metadata_only:true}:{data:[],total:0,per_page:20,date:selection.date,timezone:'UTC',coverage:{state:'unavailable',reason}},format:n=>`${n}B`,userId:42,selection,onBack:()=>{}};
  let tree=runner.render(TrafficDetails,props);
  if(tab==='domains'){nodes(tree).find(n=>n.props?.role==='tab'&&text(n)==='访问域名').props.onClick();tree=runner.render(TrafficDetails,props)}
  runner.effects();await tick();tree=runner.render(TrafficDetails,props);
  assert.ok(nodes(tree).some(n=>n.props?.role==='status'&&text(n)===expected),`${reason} should have a readable Chinese notice`);
  assert.equal(text(tree).includes(reason),false,`${reason} must not leak into the rendered interface`);
  runner.cleanup();
 }
});

test('page selection is bounded and rejects invalid input',async()=>{const {trafficPage}=await import('./src/traffic.mjs');assert.equal(trafficPage('12',15),12);assert.equal(trafficPage('99',15),15);assert.equal(trafficPage('0',15),1);assert.equal(trafficPage('',15),null);assert.equal(trafficPage('1.5',15),null)});
