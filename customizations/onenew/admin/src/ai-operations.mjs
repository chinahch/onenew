const root='/api/onenew/admin/ai';
export const scopes={panel:'全站面板',nodes:'节点 / 机器 / 落地',users:'用户 / 套餐 / 订阅',orders:'订单 / 能量',backups:'备份 / 恢复',upgrades:'升级'};
export function scopeForPath(path=''){
 const parts=path.split('/').filter(Boolean);
 if(parts.some(v=>v==='upgrade'||v==='upgrades'))return 'upgrades';
 if(parts.slice(0,3).join('/')==='config/system/migration')return 'backups';
 if(parts[0]==='finance'||parts.slice(0,3).join('/')==='config/system/energy')return 'orders';
 if(['user','plan'].includes(parts[0]))return 'users';
 if(parts[0]==='server')return 'nodes';
 return 'panel';
}
export function createAiSession(){return {question:'',scope:'panel',nodeId:'',history:[],preview:null,requestId:null,busy:false,error:'',sources:[]}}
let session=createAiSession();const listeners=new Set();const emit=()=>listeners.forEach(fn=>fn());
function change(values){Object.assign(session,values);emit()}
export function previewPayload(s){return {question:s.question.trim(),scope:s.scope,...(s.nodeId?{node_id:Number(s.nodeId)}:{}),history:s.history.slice(-8).map(({role,content})=>({role,content:Array.from(String(content)).slice(0,4000).join('')}))}}
export function confirmPayload(s){return {preview_id:s.preview.preview_id,request_id:s.requestId,confirmed:true}}
function useSession(R){const [,render]=R.useState(0);R.useEffect(()=>{const f=()=>render(v=>v+1);listeners.add(f);return()=>listeners.delete(f)},[]);return session}
const errorText=()=> '操作失败，请检查配置或稍后重试。未自动重试外部请求。';
const apiGet=(get,path)=>get(root+path,{baseURL:'/'});
const apiPost=(post,path,body)=>post(root+path,body,{baseURL:'/'});
const links=[['节点管理','/server/manage'],['机器管理','/server/machine'],['用户管理','/user/manage'],['订单管理','/finance/order'],['能量审核','/config/system/energy'],['备份与恢复','/config/system/migration'],['助手设置','/config/system/ai']];
export function AiPage({react:R,get,post,Link,useLocation}){return R.createElement('section',{className:'onenew-ai-page'},R.createElement(AiConversation,{react:R,get,post,Link,useLocation}))}
export function AiConversation({react:R,get,post,Link,useLocation}){
 const h=R.createElement,s=useSession(R),[nodes,setNodes]=R.useState([]),location=useLocation?useLocation():{pathname:''};
 R.useEffect(()=>{let active=true;apiGet(get,'/options').then(v=>{if(active)setNodes(v.nodes||[])}).catch(()=>{});return()=>{active=false}},[get]);
 const btn=(text,fn,props={})=>h('button',{type:'button',disabled:s.busy,onClick:fn,...props},text);
 async function preview(){if(s.busy||!s.question.trim())return;change({busy:true,error:'',preview:null,requestId:null});try{const p=await apiPost(post,'/preview',previewPayload(s));if(session!==s)return;change({preview:p,requestId:globalThis.crypto.randomUUID()})}catch{if(session===s)change({error:errorText()})}finally{if(session===s)change({busy:false})}}
 async function send(){if(s.busy||!s.preview)return;const p=s.preview;change({busy:true,error:''});try{const a=await apiPost(post,'/chat',confirmPayload(s));if(session!==s)return;change({history:[...s.history,{role:'user',content:p.question},{role:'assistant',content:String(a.answer||'')}].slice(-8),sources:a.sources||[],question:'',preview:null,requestId:null})}catch{if(session===s)change({error:errorText()})}finally{if(session===s)change({busy:false})}}
 const edit=values=>change({...values,preview:null,requestId:null,error:''});
 return h('div',{className:'onenew-ai'},h('h2',null,'AI运维助手'),h('p',null,'回答面板功能、配置与排障问题。只读分析；任何更改请在原管理页面确认。对话仅保存在本次页面内存中。'),
 h('nav',{'aria-label':'运维页面',className:'onenew-ai-links'},links.map(([label,href])=>h(Link,{key:href,to:href},label))),
 h('div',{className:'onenew-ai-history','aria-live':'polite'},s.history.length?s.history.map((m,i)=>h('article',{key:i,className:'onenew-ai-message'},h('strong',null,m.role==='user'?'你':'助手'),h('pre',null,m.content))):h('p',null,'例如：套餐续费后流量如何计算？节点显示正常为何客户端仍无法连接？怎样安全恢复备份？')),
 s.sources.length>0&&h('details',null,h('summary',null,'回答来源'),h('pre',null,JSON.stringify(s.sources,null,2))),
 h('div',{className:'onenew-ai-controls'},h('label',null,'问题范围',h('select',{value:s.scope,disabled:s.busy,onChange:e=>edit({scope:e.target.value})},Object.entries(scopes).map(([value,label])=>h('option',{key:value,value},label)))),h('label',null,'节点（可选）',h('select',{value:s.nodeId,disabled:s.busy,onChange:e=>edit({nodeId:e.target.value})},h('option',{value:''},'不指定节点'),nodes.map(n=>h('option',{key:n.id,value:n.id},'节点 #'+n.id))))),
 h('label',null,'问题',h('textarea',{value:s.question,maxLength:4000,rows:4,disabled:s.busy,placeholder:'请勿输入密码、API Key 或用户隐私。',onChange:e=>edit({question:e.target.value})})),
 h('div',{className:'onenew-ai-actions'},btn('使用当前页面范围',()=>edit({scope:scopeForPath(location.pathname)})),btn(s.busy?'处理中…':'预览待发送内容',preview,{disabled:s.busy||!s.question.trim()}),btn('清空对话',()=>{session=createAiSession();emit()})),
 s.error&&h('p',{role:'alert'},s.error),s.preview&&h('section',{className:'onenew-ai-preview','aria-label':'发送确认'},h('h3',null,'确认发送给外部模型'),h('p',null,'以下为服务器保存的不可变快照；确认后才会发送。包含脱敏问题、最近8条历史（每条最多4000字）、知识与面板上下文。'),h('pre',null,JSON.stringify(s.preview,null,2)),btn('确认发送 / 重试同一请求',send),btn('取消',()=>change({preview:null,requestId:null}))),h('p',{className:'onenew-ai-note'},'模型回答可能有误，快照与代码说明不能证明客户端连接成功。'));
}
export function AiLauncher({react:R,get,post,Link,useLocation}){
 const h=R.createElement,[open,setOpen]=R.useState(false),launcher=R.useRef(null),dialog=R.useRef(null);
 R.useEffect(()=>()=>{session=createAiSession();emit()},[]);
 R.useEffect(()=>{if(!open)return;const before=document.activeElement;dialog.current?.focus();function key(e){if(e.key==='Escape'){e.preventDefault();setOpen(false)}if(e.key==='Tab'){const items=[...dialog.current.querySelectorAll('button,a[href],select,textarea,input,[tabindex="0"]')].filter(x=>!x.disabled);if(!items.length)return;const first=items[0],last=items.at(-1);if(e.shiftKey&&(document.activeElement===first||document.activeElement===dialog.current)){e.preventDefault();last.focus()}else if(!e.shiftKey&&document.activeElement===last){e.preventDefault();first.focus()}}}document.addEventListener('keydown',key);return()=>{document.removeEventListener('keydown',key);if(before?.isConnected)before.focus();else launcher.current?.focus()}},[open]);
 return h(R.Fragment,null,h('button',{type:'button',ref:launcher,className:'onenew-ai-launcher','aria-expanded':open,'aria-controls':'onenew-ai-drawer',onClick:()=>setOpen(v=>!v)},'AI 运维助手'),open&&h('div',{className:'onenew-ai-backdrop',onClick:e=>{if(e.target===e.currentTarget)setOpen(false)}},h('aside',{id:'onenew-ai-drawer',ref:dialog,tabIndex:-1,role:'dialog','aria-modal':true,'aria-label':'AI运维助手',className:'onenew-ai-drawer'},h('header',null,h(Link,{to:'/ai-operations',onClick:()=>setOpen(false)},'打开完整页面'),h('button',{type:'button',onClick:()=>setOpen(false),'aria-label':'关闭助手'},'关闭')),h(AiConversation,{react:R,get,post,Link,useLocation}))));
}
export function AiSettings({react:R,get,post}){
 const h=R.createElement,[form,setForm]=R.useState({enabled:false,endpoint:'',model:'',api_key:'',clear_key:false,key_configured:false}),[busy,setBusy]=R.useState(false),[message,setMessage]=R.useState('');
 R.useEffect(()=>{let active=true;apiGet(get,'/config').then(v=>{if(active)setForm({...v.config,api_key:'',clear_key:false})}).catch(()=>{if(active)setMessage('配置读取失败，请刷新页面重试。')});return()=>{active=false}},[get]);
 async function save(){setBusy(true);setMessage('');try{const {key_configured,...payload}=form;const v=await apiPost(post,'/config',payload);setForm({...v.config,api_key:'',clear_key:false});setMessage('配置已保存。')}catch{setMessage(errorText())}finally{setBusy(false)}}
 async function test(){setBusy(true);setMessage('');try{const v=await apiPost(post,'/test',{});setMessage(v.success?'连接测试成功（仅发送简单问候，无面板数据）。':'连接测试失败，请检查已保存配置。')}catch{setMessage(errorText())}finally{setBusy(false)}}
 const field=(key,label,type='text')=>h('label',null,label,h('input',{type,value:form[key],disabled:busy,autoComplete:'off',onChange:e=>setForm({...form,[key]:e.target.value})}));
 return h('section',{className:'onenew-ai'},h('h2',null,'AI运维助手设置'),h('p',null,'使用兼容 Chat Completions 的 HTTPS 服务。保存配置不会发送测试请求。'),h('label',null,h('input',{type:'checkbox',checked:form.enabled,disabled:busy,onChange:e=>setForm({...form,enabled:e.target.checked})}),'启用助手'),field('endpoint','API 地址（HTTPS /v1 或完整 /chat/completions）'),field('model','模型名称'),field('api_key',form.key_configured?'API Key（已配置，留空保留）':'API Key','password'),h('label',null,h('input',{type:'checkbox',checked:form.clear_key,disabled:busy,onChange:e=>setForm({...form,clear_key:e.target.checked})}),'明确清除已保存 API Key'),h('div',{className:'onenew-ai-actions'},h('button',{type:'button',disabled:busy,onClick:save},'保存配置'),h('button',{type:'button',disabled:busy,onClick:test},'测试已保存配置')),message&&h('p',{role:'status'},message));
}
