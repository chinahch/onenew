const levels=['debug','info','warn','error'];
export function runtimeLogParams(filters={}){
 const params={limit:Math.min(100,Math.max(1,Math.trunc(Number(filters.limit)||50)))};
 if(levels.includes(filters.level))params.level=filters.level;
 if(filters.search?.trim())params.search=filters.search.trim().slice(0,100);
 for(const key of ['from','to']){const value=filters[key];const seconds=typeof value==='number'?value:Date.parse(value)/1000;if(Number.isFinite(seconds)&&seconds>0)params[key]=Math.floor(seconds)}
 if(Number.isSafeInteger(filters.before_id)&&filters.before_id>0)params.before_id=filters.before_id;
 if(Number.isSafeInteger(filters.page)&&filters.page>0)params.page=Math.min(10000,filters.page);
 return params;
}
export function fetchRuntimeLogs(get,machineId,filters){
 const id=Number(machineId);if(!Number.isSafeInteger(id)||id<1)throw new Error('无效服务器');
 return get(`/api/onenew/admin/machines/${id}/runtime-logs`,{baseURL:'/',params:runtimeLogParams(filters)}).then(value=>value?.data??value);
}
const time=value=>{if(!value)return '—';const date=new Date(typeof value==='number'?value*1000:value);return Number.isNaN(date.getTime())?'—':date.toLocaleString()};
export function RuntimeLogEntries({react:R,entries}){
 const h=R.createElement;
 return h('div',{className:'onenew-runtime-entries',role:'log','aria-label':'运行日志内容'},entries.slice(0,100).map(row=>h('div',{key:row.id,className:'onenew-runtime-entry'},h('span',{className:'onenew-runtime-meta'},`${time(row.at)} · ${levels.includes(row.level)?row.level:'info'}`),h('pre',null,String(row.message??'')))));
}
export function RuntimeLogs({react:R,get,machineId}){
 const h=R.createElement,[open,setOpen]=R.useState(false),[filters,setFilters]=R.useState({level:'',search:'',from:'',to:'',limit:50}),[page,setPage]=R.useState(0),[data,setData]=R.useState(null),[busy,setBusy]=R.useState(false),[error,setError]=R.useState(''),[auto,setAuto]=R.useState(false),[refresh,setRefresh]=R.useState(0),seq=R.useRef(0),dialog=R.useRef(null);
 const change=(key,value)=>{seq.current++;setFilters(old=>({...old,[key]:value}));setPage(0);setData(null)};
 R.useEffect(()=>{if(!open)return;dialog.current?.showModal();return()=>dialog.current?.close()},[open]);
 R.useEffect(()=>{if(!open)return;const token=++seq.current;let active=true;setBusy(true);setError('');const timer=setTimeout(()=>{if(filters.from&&filters.to&&Date.parse(filters.from)>Date.parse(filters.to)){setError('开始时间不能晚于结束时间');setBusy(false);return}fetchRuntimeLogs(get,machineId,{...filters,page:page+1}).then(value=>{if(active&&token===seq.current)setData(value)}).catch(()=>{if(active&&token===seq.current)setError('无法读取运行日志，请重试')}).finally(()=>{if(active&&token===seq.current)setBusy(false)})},200);return()=>{active=false;clearTimeout(timer);seq.current++}},[open,machineId,filters,page,refresh,get]);
 R.useEffect(()=>{seq.current++;setData(null);setPage(0)},[machineId]);
 R.useEffect(()=>{if(!open||!auto||page!==0)return;const timer=setInterval(()=>setRefresh(value=>value+1),10000);return()=>clearInterval(timer)},[open,auto,page]);
 const button=(label,onClick,disabled=false)=>h('button',{type:'button',className:'onenew-resource-button',disabled,onClick},label);
 const field=(label,key,type='text')=>h('label',null,label,h('input',{type,value:filters[key],maxLength:key==='search'?100:undefined,onChange:e=>change(key,e.target.value)}));
 return h(R.Fragment,null,button('日志',e=>{e?.stopPropagation();setOpen(true)}),open&&h('dialog',{ref:dialog,className:'onenew-runtime-dialog',onCancel:()=>setOpen(false),onClose:()=>setOpen(false),'aria-label':`服务器 #${machineId} 运行日志`},
 h('div',{className:'onenew-resource-actions'},h('h2',null,`服务器 #${machineId} 运行日志`),button('关闭',()=>setOpen(false))),
 h('p',{className:'onenew-help'},'仅显示开始采集后、面板保留期内的日志，默认保留 7 天。不会补录服务器原有 journal 历史；缓冲或限流可能丢弃日志。'),
 h('div',{className:'onenew-runtime-filters'},h('label',null,'等级',h('select',{value:filters.level,onChange:e=>change('level',e.target.value)},h('option',{value:''},'全部等级'),levels.map(level=>h('option',{key:level,value:level},level)))),field('搜索','search'),field('开始时间','from','datetime-local'),field('结束时间','to','datetime-local'),h('label',null,'每页条数',h('select',{value:filters.limit,onChange:e=>change('limit',Number(e.target.value))},[20,50,100].map(value=>h('option',{key:value,value},value)))),button('刷新',()=>setRefresh(value=>value+1),busy),h('label',null,h('input',{type:'checkbox',checked:auto,onChange:e=>setAuto(e.target.checked)}),'每 10 秒自动刷新首页')),
 error&&h('p',{role:'alert',className:'onenew-error'},error),data?.status==='upgrade_required'&&h('p',{role:'status'},'此服务器节点版本不支持运行日志采集，请先升级节点。'),data?.status==='waiting'&&h('p',{role:'status'},'等待节点上报运行日志。'),
 data&&h('p',{className:'onenew-help'},`保留 ${data.retention_days||7} 天 · 已丢弃 ${Number(data.dropped)||0} 条 · 最近接收：${time(data.last_received_at)}`),
 busy&&h('p',{role:'status'},'正在读取…'),!busy&&data?.status==='collecting'&&!data.entries?.length&&h('p',null,'保留期内暂无匹配日志。'),h(RuntimeLogEntries,{react:R,entries:data?.entries||[]}),
 h('div',{className:'onenew-resource-actions'},button('上一页',()=>setPage(value=>value-1),busy||page===0),h('label',null,'页数',h('select',{'aria-label':'日志页数',value:page,disabled:busy,onChange:e=>setPage(Number(e.target.value))},Array.from({length:Math.min(10000,data?.pages||1)},(_,index)=>h('option',{key:index,value:index},index+1)))),button('下一页',()=>setPage(value=>value+1),busy||page+1>=(data?.pages||1)))));
}
