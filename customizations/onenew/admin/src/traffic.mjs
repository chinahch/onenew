// React and the authenticated request/byte formatter are supplied by the pinned admin bundle.
const apiRoot='/api/onenew/admin/traffic';
const integer=(value,name)=>{const n=Number(value);if(!Number.isSafeInteger(n)||n<=0)throw new Error(`${name}无效`);return n};
export function archiveDay(recordAt,timezone){
 const epoch=integer(recordAt,'归档时间');
 if(epoch>=100000000000)throw new Error('归档时间必须使用原始秒级时间戳');
 if(typeof timezone!=='string'||!timezone)throw new Error('缺少面板归档时区');
 const parts=new Intl.DateTimeFormat('en-CA',{timeZone:timezone,year:'numeric',month:'2-digit',day:'2-digit'}).formatToParts(new Date(epoch*1000));
 const part=name=>parts.find(p=>p.type===name)?.value;
 return `${part('year')}-${part('month')}-${part('day')}`;
}
export function fetchTrafficRecords({get,userId,page=1,perPage=20}){
 return get(`${apiRoot}/records`,{baseURL:'/',params:{user_id:integer(userId,'用户'),page:integer(page,'页码'),per_page:integer(perPage,'每页条数')}});
}
export function fetchTrafficDay({get,userId,recordAt,tab='nodes',page=1,perPage=20}){
 if(!['nodes','domains'].includes(tab))throw new Error('流量明细类型无效');
 const epoch=integer(recordAt,'归档时间');
 if(epoch>=100000000000)throw new Error('归档时间必须使用原始秒级时间戳');
 return get(`${apiRoot}/${tab}`,{baseURL:'/',params:{user_id:integer(userId,'用户'),record_at:epoch,page:integer(page,'页码'),per_page:integer(perPage,'每页条数')}});
}
export function trafficColumns({react:React,columns,select,timezone}){
 const h=React.createElement;
 return columns.map(column=>column.accessorKey!=='record_at'?column:{...column,cell:context=>{
  const row=context.row.original;
  let date=row.date,epoch;
  try{epoch=integer(row.record_at,'归档时间');if(epoch>=100000000000)throw new Error('无效归档');date=date||archiveDay(epoch,timezone)}catch{return column.cell(context)}
  if(row.record_type==='m')return h('span',{className:'text-sm text-muted-foreground'},date,'（月汇总）');
  return h('button',{type:'button',className:'text-sm text-primary underline-offset-4 hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring',
   'aria-label':`查看 ${date} 的流量与域名记录`,onClick:()=>select({recordAt:epoch,date,timezone})},date);
 }});
}
const tabNames={nodes:'节点流量',domains:'访问域名'};
const coverageMessages=new Map([
 ['legacy_node_breakdown_unavailable','历史记录未保存逐节点明细，无法还原。'],
 ['no_archived_traffic','该日暂无归档流量。'],
 ['archive_totals_do_not_match_recorded_node_breakdown','节点明细与归档总量不一致，请以归档总量为准。'],
 ['domain_records_outside_retention','该日域名记录已超出保留期限。'],
 ['domain_collection_disabled','域名收集已停用，暂无新增域名记录。'],
 ['no_reported_domain_records','该日暂无节点报告的域名记录。']
]);
function coverageNotice(coverage,tab){return coverageMessages.get(coverage?.reason)||(tab==='domains'?'域名记录暂不可用。':'逐节点明细暂不完整，请以归档总量为准。')}
function trafficValue(value,format){return value===null||value===undefined||!Number.isFinite(Number(value))||Number(value)<0?'—':format(Number(value))}
function reportTime(value,timezone){
 if(!value||!Number.isFinite(Date.parse(value)))return '—';
 try{return new Date(value).toLocaleString('zh-CN',{timeZone:timezone,hour12:false})}catch{return '—'}
}
export function TrafficDetails({react:React,get,post,format,userId,selection,onBack}){
 const h=React.createElement,[tab,setTab]=React.useState('nodes'),[page,setPage]=React.useState(1),[result,setResult]=React.useState(null),[busy,setBusy]=React.useState(true),[error,setError]=React.useState(''),[retry,setRetry]=React.useState(0),[setting,setSetting]=React.useState(false),[auditError,setAuditError]=React.useState(''),tabRefs=React.useRef([]);
 const requestKey=`${userId}:${selection.recordAt}:${tab}:${page}`,data=result?.key===requestKey?result.data:null;
 React.useEffect(()=>{
  let active=true;setBusy(true);setError('');
  const config=tab==='domains'?get(`${apiRoot}/audit-config`,{baseURL:'/'}).then(value=>({value})).catch(e=>({error:e?.message||'域名收集设置读取失败'})):Promise.resolve(null);
  Promise.all([fetchTrafficDay({get,userId,recordAt:selection.recordAt,tab,page}),config]).then(([value,audit])=>{if(active)setResult({key:requestKey,data:value,audit})}).catch(e=>{if(active)setError(e?.message||'明细加载失败，请稍后重试')}).finally(()=>{if(active)setBusy(false)});
  return()=>{active=false};
 },[get,userId,selection.recordAt,tab,page,retry]);
 const prefix=`onenew-traffic-${userId}-${selection.recordAt}`,timezone=data?.timezone||selection.timezone;
 function choose(value,focus=false){setTab(value);setPage(1);if(focus)tabRefs.current[value==='nodes'?0:1]?.focus()}
 function keydown(event,index){let next;if(event.key==='ArrowRight'||event.key==='ArrowLeft')next=1-index;else if(event.key==='Home')next=0;else if(event.key==='End')next=1;else return;event.preventDefault();choose(next?'domains':'nodes',true)}
 const cells=(values,header=false)=>values.map((value,index)=>h(header?'th':'td',{key:index,className:header?'h-10 px-2 text-left text-xs text-muted-foreground':'px-2 py-2 text-sm',style:index?{whiteSpace:'nowrap'}:{overflowWrap:'anywhere'}},value));
 const head=tab==='nodes'?['节点','原始上传','原始下载','倍率','计费总计']:['访问域名','报告时间','节点','命中次数'];
 const rows=Array.isArray(data?.data)?data.data:[],count=Number(data?.total||0),perPage=Number(data?.per_page||20),pages=Math.max(1,Math.ceil(count/perPage));
 const body=rows.map((row,index)=>{
  const node=(row.node_name||`节点 #${row.server_id}`)+(row.node_deleted?'（已删除）':'');
  const values=tab==='nodes'?[h('span',null,node,h('small',{className:'block text-xs text-muted-foreground'},row.server_type||'')),trafficValue(row.raw_u,format),trafficValue(row.raw_d,format),`${row.server_rate}x`,trafficValue(row.total,format)]:[
   row.domain||'—',h('span',{className:'text-xs'},reportTime(row.first_seen,timezone),row.last_seen&&row.last_seen!==row.first_seen&&h('small',{className:'block text-muted-foreground'},'至 ',reportTime(row.last_seen,timezone))),node,Number.isSafeInteger(row.hit_count)&&row.hit_count>=0?String(row.hit_count):'—'];
  return h('tr',{key:`${row.server_id}:${row.server_rate??row.domain??index}:${index}`,className:'border-b'},...cells(values));
 });
 const coverage=data?.coverage;
 const audit=result?.key===requestKey?result.audit:null,auditConfig=typeof audit?.value?.enabled==='boolean'&&audit.value.metadata_only===true&&Number.isInteger(audit.value.retention_days)&&audit.value.retention_days>=1&&audit.value.retention_days<=30?audit.value:null;
 async function toggleAudit(){
  if(setting||!auditConfig||!post)return;setSetting(true);setAuditError('');
  try{await post(`${apiRoot}/audit-config`,{enabled:!auditConfig.enabled,retention_days:auditConfig.retention_days},{baseURL:'/'});setRetry(value=>value+1)}catch(e){setAuditError(e?.message||'域名收集设置保存失败')}finally{setSetting(false)}
 }
 return h('section',{'aria-label':`${selection.date} 流量明细`,className:'space-y-4'},
  h('div',{className:'flex items-center justify-between gap-2'},h('button',{type:'button',className:'inline-flex items-center rounded-md border px-3 py-1 text-sm hover:bg-accent',onClick:onBack},'← 返回流量记录'),h('span',{className:'text-sm font-medium'},data?.date||selection.date,h('small',{className:'block text-xs text-muted-foreground'},'归档时区：',timezone||'—'))),
  h('div',{role:'tablist','aria-label':'每日记录类型',className:'flex items-center gap-2 border-b'},...Object.entries(tabNames).map(([key,label],index)=>h('button',{key,type:'button',role:'tab',id:`${prefix}-tab-${key}`,'aria-controls':`${prefix}-panel-${key}`,'aria-selected':tab===key,tabIndex:tab===key?0:-1,ref:node=>{tabRefs.current[index]=node},className:tab===key?'border-b-2 border-primary px-3 py-2 text-sm font-medium text-primary':'px-3 py-2 text-sm text-muted-foreground',onClick:()=>choose(key),onKeyDown:event=>keydown(event,index)},label))),
  h('div',{role:'tabpanel',id:`${prefix}-panel-${tab}`,'aria-labelledby':`${prefix}-tab-${tab}`,tabIndex:0,'aria-busy':busy,className:'space-y-4'},
   busy&&h('p',{role:'status',className:'text-sm text-muted-foreground'},'正在加载明细…'),
   error&&h('div',{role:'alert',className:'text-sm text-destructive'},error,' ',h('button',{type:'button',className:'underline',onClick:()=>setRetry(retry+1)},'重试')),
   !busy&&!error&&data&&h(React.Fragment,null,
    tab==='domains'&&h('div',{className:'rounded-md border p-2 space-y-2'},
     auditConfig?h('div',{className:'flex items-center justify-between gap-2'},h('span',{className:'text-xs text-muted-foreground'},'域名元数据收集：',auditConfig.enabled?'已启用':'已停用',` · 保留 ${auditConfig.retention_days} 天`),h('button',{type:'button',className:'rounded-md border px-2 py-1 text-xs',disabled:setting||!post,onClick:toggleAudit},setting?'正在保存…':auditConfig.enabled?'停用域名收集':'启用域名收集')):h('p',{role:'alert',className:'text-xs text-destructive'},audit?.error||'域名收集设置暂不可用',' ',h('button',{type:'button',className:'underline',onClick:()=>setRetry(value=>value+1)},'重试')),
     h('p',{className:'text-xs text-muted-foreground'},'仅收集实际报告的域名、时间、节点及次数，不保存完整 URL 或访问内容。'),
     auditError&&h('p',{role:'alert',className:'text-xs text-destructive'},auditError)),
    tab==='nodes'&&h('p',{className:'text-xs text-muted-foreground'},'本日计费总量 ',trafficValue(data.native_totals?.total,format),' · 已归属节点 ',trafficValue(data.totals?.total,format),' · 未归属 ',trafficValue(data.unattributed?.total,format)),
    coverage&&(tab==='nodes'?coverage.state!=='complete':coverage.state!=='available')&&h('p',{role:'status',className:'rounded-md border p-2 text-xs text-muted-foreground'},coverageNotice(coverage,tab)),
    rows.length?h('div',{className:'rounded-md border',style:{maxHeight:320,overflow:'auto'}},h('table',{className:'w-full text-sm',style:{width:'100%'}},h('thead',null,h('tr',{className:'border-b'},...cells(head,true))),h('tbody',null,...body))):h('p',{className:'py-6 text-center text-sm text-muted-foreground'},tab==='domains'?'暂无域名记录':'暂无逐节点流量记录'),
    h('div',{className:'flex items-center justify-between text-xs text-muted-foreground'},h('span',null,`${count} 条 · 第 ${page} / ${pages} 页`),h('div',{className:'flex items-center gap-2'},h('button',{type:'button',className:'rounded-md border px-2 py-1',disabled:page<=1,onClick:()=>setPage(page-1),'aria-label':'上一页明细'},'上一页'),h('button',{type:'button',className:'rounded-md border px-2 py-1',disabled:page>=pages,onClick:()=>setPage(page+1),'aria-label':'下一页明细'},'下一页'))),
    tab==='domains'&&h('p',{className:'text-xs text-muted-foreground'},'仅显示节点实际报告的域名与次数，不包含完整 URL 或访问内容。'))));
}
