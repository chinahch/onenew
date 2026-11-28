import {NodeUsersTrigger} from './node-users.mjs';
const root='/api/onenew/admin/external-nodes';
export async function fetchManagedNodes(nativeFetch,get){
 const [native,external]=await Promise.all([nativeFetch(),get(root,{baseURL:'/'})]);
 const rows=[...(native.data||[]),...(external.data||[]).map(row=>({...row,id:-row.id,onenew_external_id:row.id,show:row.enabled,machine_id:'__external__',online:null,u:null,d:null,rate:null}))];
 const order=new Map((Array.isArray(external.table_order)?external.table_order:[]).map((id,index)=>[Number(id),index]));
 return rows.sort((a,b)=>(order.get(a.id)??Number.MAX_SAFE_INTEGER)-(order.get(b.id)??Number.MAX_SAFE_INTEGER));
}
export function saveManagedOrder(post,rows){return post(root+'/table-order',{ids:rows.map(r=>r.id)},{baseURL:'/'})}
function ExternalCell({react:React,column,row,post,refetch}){
 const h=React.createElement,[busy,setBusy]=React.useState(false),[error,setError]=React.useState('');
 async function toggle(){if(busy)return;setBusy(true);setError('');try{await post(root+'/toggle',{id:row.onenew_external_id,enabled:!row.enabled},{baseURL:'/'});await refetch()}catch(e){setError(e?.response?.data?.message||'更新失败')}finally{setBusy(false)}}
 const btn=(text,fn)=>h('button',{type:'button',className:'onenew-resource-button',disabled:busy,onClick:fn},text);
 const id=column.id||column.accessorKey;
 if(id==='select')return null;
 if(id==='id')return h('span',{className:'font-mono text-xs'},'E'+row.onenew_external_id);
 if(id==='drag-handle')return h('span',null,'↕');
 if(id==='name')return h('div',{className:'flex items-center gap-2'},h('span',null,row.name),h('span',{className:'rounded bg-blue-50 px-2 text-xs text-blue-600'},'外部'));
 if(id==='machine'||id==='onenew-proxy-health')return h('span',{className:'text-xs text-muted-foreground'},'外部');
 if(id==='show')return h('div',null,btn(row.enabled?'启用':'停用',toggle),error&&h('span',{role:'alert'},error));
 if(id==='host')return h('span',{className:'font-mono text-xs'},(row.host.includes(':')?'['+row.host+']':row.host)+':'+row.port);
 if(id==='group_ids')return h('span',{className:'text-xs'},[...(row.group_ids||[]).map(v=>'权限组 #'+v),...(row.user_ids?.length?['指定用户 '+row.user_ids.length+' 人']:[])].join(' · ')||'未分配');
 if(id==='type')return row.type;
 if(id==='actions')return btn('编辑',()=>window.dispatchEvent(new CustomEvent('onenew-external-edit',{detail:row})));
 return '—';
}
export function managedColumns(columns,React,post,refetch,get){return columns.map(column=>({...column,cell:props=>{
 if(props.row.original.onenew_external_id)return React.createElement(ExternalCell,{react:React,column,row:props.row.original,post,refetch});
 if(get&&(column.accessorKey==='online'||column.id==='online'))return React.createElement(NodeUsersTrigger,{react:React,get,node:props.row.original});
 return typeof column.cell==='function'?column.cell(props):column.cell;
}}))}
