const externalRoot='/api/onenew/admin/external-nodes';
const egressRoot='/api/onenew/admin/machine-egress';
const read=(get,url,params)=>get(url,{baseURL:'/',...(params?{params}:{})});
const write=(post,url,data)=>post(url,data,{baseURL:'/'});
const message=e=>e?.response?.data?.message||e?.message||'操作失败，请重试';
export function externalOrder(rows,id,direction){
 const ids=rows.map(row=>Number(row.id)),at=ids.indexOf(Number(id)),next=at+direction;
 if(![-1,1].includes(direction)||at<0||next<0||next>=ids.length)return ids;
 [ids[at],ids[next]]=[ids[next],ids[at]];return ids;
}
export function egressPayload(machineId,settings){
 const modes=['inherit','default','prefer_ipv4','prefer_ipv6'];
 if(!Number.isSafeInteger(Number(machineId))||Number(machineId)<=0||!modes.includes(settings?.mode))throw new Error('服务器出口设置无效');
 const node_overrides={},node_identities={};
 if(!settings.machine_identity)throw new Error('服务器身份缺失，请刷新');
 for(const row of settings.nodes||[]){if(!modes.includes(row.mode)||!row.identity)throw new Error('节点出口设置无效');node_identities[row.id]=row.identity;if(row.mode!=='inherit')node_overrides[row.id]=row.mode}
 return {machine_id:Number(machineId),machine_identity:settings.machine_identity,mode:settings.mode,node_overrides,node_identities};
}
export function saveMachineEgress(post,payload){return write(post,egressRoot,payload)}

function Dialog({react:React,title,onClose,children}){
 const h=React.createElement,box=React.useRef(null),close=React.useRef(onClose);close.current=onClose;
 React.useEffect(()=>{
  const previous=document.activeElement;
  const focus=()=>box.current?.querySelector('button,input,textarea,select')?.focus();focus();
  const key=e=>{
   if(e.key==='Escape'){e.preventDefault();e.stopPropagation();close.current();return}
   if(e.key==='Tab'){const list=[...(box.current?.querySelectorAll('button:not(:disabled),input:not(:disabled),textarea:not(:disabled),select:not(:disabled)')||[])];const first=list[0],last=list.at(-1);if(e.shiftKey&&document.activeElement===first){e.preventDefault();last?.focus()}else if(!e.shiftKey&&document.activeElement===last){e.preventDefault();first?.focus()}}
  };
  document.addEventListener('keydown',key,true);return()=>{document.removeEventListener('keydown',key,true);previous?.focus?.()};
 },[]);
 return h('div',{className:'onenew-resource-overlay',onMouseDown:e=>{if(e.target===e.currentTarget)onClose()}},
  h('section',{className:'onenew-resource-dialog',role:'dialog','aria-modal':true,'aria-label':title,ref:box},
   h('header',null,h('h2',null,title),h('button',{type:'button','aria-label':'关闭',onClick:onClose},'×')),children));
}

export function ExternalNodes({react:React,get,post}){
 const h=React.createElement,[open,setOpen]=React.useState(false),[rows,setRows]=React.useState([]),[draft,setDraft]=React.useState(null),
  [lookups,setLookups]=React.useState({users:[],groups:[]}),[search,setSearch]=React.useState(''),[error,setError]=React.useState(''),[busy,setBusy]=React.useState(false),[confirmId,setConfirmId]=React.useState(null);
 const chosen=React.useRef(new Map()),generation=React.useRef(0);
 async function load(){const value=await read(get,externalRoot);setRows(value.data||[])}
 React.useEffect(()=>{if(!open)return;let active=true;setBusy(true);setError('');read(get,externalRoot).then(v=>{if(active)setRows(v.data||[])}).catch(e=>{if(active)setError(message(e))}).finally(()=>{if(active)setBusy(false)});return()=>{active=false;generation.current++}},[open,get]);
 React.useEffect(()=>{if(!draft)return;let active=true;const timer=setTimeout(async()=>{try{const v=await read(get,externalRoot+'/lookups',{search});if(active){for(const u of v.users||[])chosen.current.set(u.id,u);setLookups(v)}}catch(e){if(active)setError(message(e))}},200);return()=>{active=false;clearTimeout(timer)}},[!!draft,search,get]);
 function create(){chosen.current.clear();setSearch('');setError('');setDraft({name:'',links:'',user_ids:[],group_ids:[],enabled:true})}
 async function edit(row){setBusy(true);setError('');const token=++generation.current;try{
  const [detail,users]=await Promise.all([read(get,externalRoot+'/'+row.id),row.user_ids.length?read(get,externalRoot+'/lookups',{user_ids:row.user_ids}):Promise.resolve({users:[]})]);
  if(token!==generation.current)return;for(const user of users.users||[])chosen.current.set(user.id,user);setSearch('');setDraft({...row,links:detail.link});
 }catch(e){setError(message(e))}finally{setBusy(false)}}
 async function run(fn){if(busy)return;setBusy(true);setError('');try{await fn();await load()}catch(e){setError(message(e))}finally{setBusy(false)}}
 function toggle(field,id){setDraft(d=>({...d,[field]:d[field].includes(id)?d[field].filter(value=>value!==id):[...d[field],id]}))}
 async function save(){const d=draft;await run(async()=>{
  const common={user_ids:d.user_ids,group_ids:d.group_ids};
  if(d.id)await write(post,externalRoot+'/save',{...common,id:d.id,name:d.name,link:d.links,enabled:d.enabled});
  else await write(post,externalRoot+'/import',{...common,links:d.links});
  setDraft(null);
 })}
 const button=(label,onClick,props={})=>h('button',{type:'button',className:'onenew-resource-button',disabled:busy,onClick,...props},label);
 const allUsers=new Map((lookups.users||[]).map(u=>[u.id,u]));for(const id of draft?.user_ids||[]){const u=chosen.current.get(id);allUsers.set(id,u||{id,email:`用户 #${id}`})}
 return h(React.Fragment,null,button('外部节点',()=>{setDraft(null);setOpen(true)}),open&&h(Dialog,{react:React,title:'外部节点',onClose:()=>{if(!busy){generation.current++;setOpen(false);setDraft(null)}}},
  h('div',{className:'onenew-resource-body'},
   error&&h('p',{role:'alert',className:'onenew-error'},error),
   draft?h('div',{className:'onenew-landing'},
    draft.id&&h('label',{className:'onenew-field'},h('span',null,'节点名称'),h('input',{value:draft.name,onChange:e=>setDraft({...draft,name:e.target.value}),maxLength:255})),
    h('label',{className:'onenew-field'},h('span',null,draft.id?'节点链接':'批量粘贴链接（每行一个，最多 200 条）'),h('textarea',{rows:5,value:draft.links,autoComplete:'off',spellCheck:false,onChange:e=>setDraft({...draft,links:e.target.value})})),
    h('fieldset',null,h('legend',null,'权限组'),h('div',{className:'onenew-assignment-grid'},lookups.groups.map(group=>h('label',{key:group.id,className:'onenew-check'},h('input',{type:'checkbox',checked:draft.group_ids.includes(group.id),onChange:()=>toggle('group_ids',group.id)}),group.name)))),
    h('fieldset',null,h('legend',null,'指定用户'),h('input',{className:'onenew-search',placeholder:'搜索用户邮箱',value:search,onChange:e=>setSearch(e.target.value)}),h('div',{className:'onenew-assignment-grid'},[...allUsers.values()].map(user=>h('label',{key:user.id,className:'onenew-check'},h('input',{type:'checkbox',checked:draft.user_ids.includes(user.id),onChange:()=>toggle('user_ids',user.id)}),user.email)))),
    h('p',{className:'onenew-help'},'指定用户或权限组任一匹配即可使用；未选择时不向任何用户提供。'),
    draft.id&&h('label',{className:'onenew-check'},h('input',{type:'checkbox',checked:draft.enabled,onChange:e=>setDraft({...draft,enabled:e.target.checked})}),'启用'),
    h('div',{className:'onenew-resource-actions'},button('返回',()=>setDraft(null)),button(busy?'保存中…':draft.id?'保存':'导入',save,{disabled:busy||!draft.links.trim()})))
   :h(React.Fragment,null,h('div',{className:'onenew-resource-actions'},button('粘贴链接添加',create),button('刷新',()=>run(async()=>{}))),
    h('p',{className:'onenew-help'},'外部节点使用链接自带账号；订阅时加入已授权的节点。'),
    rows.length?h('div',{className:'onenew-resource-list'},rows.map((row,index)=>h('article',{key:row.id},
     h('div',null,h('strong',null,row.name),h('small',null,`${row.type.toUpperCase()} · ${row.host}:${row.port}`),h('small',null,`${row.user_ids.length} 个用户 · ${row.group_ids.length} 个权限组 · ${row.enabled?'启用':'停用'}`)),
     h('div',{className:'onenew-resource-actions'},button('↑',()=>run(()=>write(post,externalRoot+'/order',{ids:externalOrder(rows,row.id,-1)})),{'aria-label':`上移 ${row.name}`,disabled:busy||index===0}),button('↓',()=>run(()=>write(post,externalRoot+'/order',{ids:externalOrder(rows,row.id,1)})),{'aria-label':`下移 ${row.name}`,disabled:busy||index===rows.length-1}),button('编辑',()=>edit(row)),button('删除',()=>setConfirmId(row.id),{className:'onenew-resource-button onenew-error'})),
     confirmId===row.id&&h('div',{className:'onenew-resource-actions'},h('span',null,'确认删除此外部节点？'),button('取消',()=>setConfirmId(null)),button('确认删除',()=>run(async()=>{await write(post,externalRoot+'/delete',{id:row.id});setConfirmId(null)})))
    ))):h('p',null,busy?'加载中…':'暂无外部节点')))));
}

const familyLabels={inherit:'继承服务器设置',default:'系统默认',prefer_ipv4:'IPv4 优先',prefer_ipv6:'IPv6 优先'};
export function BatchAddresses({react:React,get,post,useQueryClient}){
 const queryClient=useQueryClient();
 const h=React.createElement,[open,setOpen]=React.useState(false),[source,setSource]=React.useState(''),[target,setTarget]=React.useState(''),[port,setPort]=React.useState(''),[rows,setRows]=React.useState(null),[selected,setSelected]=React.useState([]),[busy,setBusy]=React.useState(false),[error,setError]=React.useState(''),[done,setDone]=React.useState('');
 const api='/api/onenew/admin/node-addresses';
 const button=(label,onClick,disabled=false)=>h('button',{type:'button',className:'onenew-resource-button',onClick,disabled:busy||disabled},label);
 async function search(){setBusy(true);setError('');setDone('');setRows(null);try{const value=await read(get,api,{ip:source.trim()});setRows(value.nodes);setSelected(value.nodes.map(n=>n.id))}catch(e){setError(message(e))}finally{setBusy(false)}}
 async function apply(){setBusy(true);setError('');try{const value=await write(post,api,{source_ip:source.trim(),target_ip:target.trim()||null,target_port:port?Number(port):null,nodes:rows.filter(n=>selected.includes(n.id)).map(n=>({id:n.id,identity:n.identity,port:n.port}))});setDone(`已修改 ${value.updated} 个节点。客户端更新订阅后可使用新地址。`);setRows(null);setSelected([]);await queryClient.invalidateQueries().catch(()=>{})}catch(e){setError(message(e))}finally{setBusy(false)}}
 return h(React.Fragment,null,button('批量修改地址/端口',()=>{setOpen(true);setRows(null);setError('');setDone('')}),open&&h(Dialog,{react:React,title:'批量修改 IP / 连接端口',onClose:()=>{if(!busy)setOpen(false)}},h('div',{className:'onenew-resource-body onenew-landing'},
  h('label',{className:'onenew-field'},h('span',null,'搜索旧 IP（IPv4 / IPv6）'),h('input',{value:source,disabled:busy,onChange:e=>{setSource(e.target.value);setRows(null);setSelected([]);setDone('')},placeholder:'输入需要替换的 IP'})),button('搜索相同地址',search,!source.trim()),
  error&&h('p',{role:'alert',className:'onenew-error'},error),done&&h('p',{role:'status'},done),
  rows&&h(React.Fragment,null,h('div',{className:'onenew-resource-actions'},h('strong',null,`匹配 ${rows.length} 个，已选 ${selected.length} 个`),button('全选',()=>setSelected(rows.map(n=>n.id))),button('取消全选',()=>setSelected([]))),
   h('div',{className:'onenew-resource-list'},rows.map(row=>h('label',{key:row.id,className:'onenew-check'},h('input',{type:'checkbox',disabled:busy,checked:selected.includes(row.id),onChange:()=>setSelected(selected.includes(row.id)?selected.filter(id=>id!==row.id):[...selected,row.id])}),`#${row.id} ${row.name} · ${row.type.toUpperCase()} · ${row.host}:${row.port}`))),
   h('div',{className:'onenew-row'},h('label',{className:'onenew-field'},h('span',null,'新 IP（留空保持）'),h('input',{value:target,disabled:busy,onChange:e=>setTarget(e.target.value)})),h('label',{className:'onenew-field'},h('span',null,'新连接端口（留空保持）'),h('input',{type:'number',min:1,max:65535,value:port,disabled:busy,onChange:e=>setPort(e.target.value)}))),
   h('p',{className:'onenew-help'},'只修改勾选节点的对外地址和连接端口，监听端口保持原值。'),button(`确认修改 ${selected.length} 个节点`,apply,!selected.length||(!target.trim()&&!port))))));
}
export function MachineEgress({react:React,machineId,open,get,onChange}){
 const h=React.createElement,[settings,setSettings]=React.useState(null),[error,setError]=React.useState(''),callback=React.useRef(onChange);callback.current=onChange;
 React.useEffect(()=>{setSettings(null);setError('');if(!open||!machineId)return;let active=true;
  read(get,egressRoot,{machine_id:machineId}).then(v=>{if(active)setSettings(v.data||v)}).catch(()=>{if(active)setError('无法读取出口设置，请重新打开编辑窗口')});
  return()=>{active=false};
 },[open,machineId,get]);
 React.useEffect(()=>{callback.current?.(settings?()=>egressPayload(machineId,settings):null)},[settings,machineId]);
 if(!machineId)return null;
 const select=(value,change,global=false)=>h('select',{value,disabled:!settings,onChange:e=>change(e.target.value)},Object.entries(familyLabels).map(([key,label])=>h('option',{key,value:key},global&&key==='inherit'?'保留节点机原设置':label)));
 const nodeRows=(settings?.nodes||[]).map(row=>h('label',{key:row.id,className:'onenew-field'},
  h('span',null,`#${row.id} ${row.name}`),select(row.mode,mode=>setSettings({...settings,nodes:settings.nodes.map(n=>n.id===row.id?{...n,mode}:n)}))));
 return h('section',{className:'onenew-machine-egress onenew-landing','aria-label':'服务器出口优先'},
  h('h3',null,'出口优先'),error&&h('p',{role:'alert',className:'onenew-error'},error),!settings&&!error&&h('p',null,'加载出口设置…'),
  h('label',{className:'onenew-field'},h('span',null,'服务器全局（所有 caddy 节点）'),select(settings?.mode||'inherit',mode=>setSettings({...settings,mode}),true)),
  nodeRows.length>0&&h('details',null,h('summary',null,`单独设置节点（${nodeRows.length}）`),h('div',{className:'onenew-egress-nodes'},nodeRows)),
  h('p',{className:'onenew-help'},'节点单独设置优先，其余继承服务器全局设置。保存后自动下发。'));
}
