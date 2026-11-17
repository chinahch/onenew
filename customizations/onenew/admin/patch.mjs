import {createHash} from 'node:crypto';
import contract from './upstream.json' with {type:'json'};
const sha=value=>createHash('sha256').update(value).digest('hex');
function replaceOnce(source,anchor,replacement){
  const pos=source.indexOf(anchor);
  if(pos<0||source.indexOf(anchor,pos+anchor.length)>=0)throw new Error(`上游补丁锚点不唯一：${anchor.slice(0,80)}`);
  return source.slice(0,pos)+replacement+source.slice(pos+anchor.length);
}
export function patchBundle(source,runtime){
  source=source.replace(/\r\n/g,'\n');
  if(sha(source)!==contract.files['assets/index-CEIYH7i8.js'])throw new Error('上游 bundle SHA256 不匹配，拒绝修改');
  const editorStart=source.indexOf('function m4t('),editorEnd=source.indexOf('const f4t=',editorStart);
  let editor=source.slice(editorStart,editorEnd);
  if(sha(editor)!==contract.networkEditorSha256)throw new Error('协议编辑器 SHA256 不匹配');
  const templateStart=editor.indexOf('xhttp:{label:"XHTTP",content:'),templateEnd=editor.indexOf('},g=()=>{switch(i)',templateStart);
  if(templateStart<0||templateEnd<templateStart)throw new Error('XHTTP 模板边界不存在');
  editor=editor.slice(0,templateStart)+'xhttp:{label:"XHTTP",content:{host:"example.com",path:"/",mode:"packet-up",alpn:"h2",extra:{headers:{},xPaddingBytes:"100-1000",noGRPCHeader:!1,noSSEHeader:!1}}}'+editor.slice(templateEnd);
  editor=replaceOnce(editor,'},g=()=>{switch(i)',',h3:{label:"H3",content:{host:"example.com",path:"/",mode:"auto",alpn:"h3",extra:{headers:{},xPaddingBytes:"100-1000",noGRPCHeader:!1,noSSEHeader:!1}}}},g=()=>{switch(i)');
  editor=replaceOnce(editor,'case"xhttp":return["xhttp"]','case"xhttp":return["xhttp","h3"]');
  source=source.slice(0,editorStart)+editor+source.slice(editorEnd);
  const start=source.indexOf('function l5t('),end=source.indexOf('const c5t=',start);
  let advanced=source.slice(start,end);
  if(sha(advanced)!==contract.advancedSha256)throw new Error('高级协议组件 SHA256 不匹配');
  advanced=replaceOnce(advanced,'repeat(${2+(l?1:0)+(n?1:0)}','repeat(${Math.max(1,(l?1:0)+(n?1:0))}');
  for(const [value,key,label] of [['outbounds','outbounds_tab','Outbounds'],['routes','routes_tab','Routes']]){
    advanced=replaceOnce(advanced,',Q.jsx(ow,{value:"'+value+'",className:"text-xs data-[state=active]:bg-background data-[state=active]:shadow-sm",children:i("dynamic_form.routing.'+key+'","'+label+'")})','');
  }
  const paneStart=advanced.indexOf('Q.jsx(aw,{value:"outbounds"'),footer=advanced.indexOf('Q.jsxs(ftt,',paneStart);
  if(paneStart<0||footer<0)throw new Error('高级协议内容边界不存在');
  advanced=advanced.slice(0,paneStart)+']}),'+advanced.slice(footer);
  const saveStart=advanced.indexOf('onClick:()=>{(()=>{let t=!1;'),saveEnd=advanced.indexOf('},className:"px-8',saveStart);
  if(saveStart<0||saveEnd<0)throw new Error('高级协议保存边界不存在');
  // TLS/multiplex fields already write to the form; removed routing panes must
  // never overwrite a library binding with their stale initialization snapshot.
  advanced=advanced.slice(0,saveStart)+'onClick:()=>{s(!1)'+advanced.slice(saveEnd);
  source=source.slice(0,start)+advanced+source.slice(end);
  const menus=[
    '{id:"knowledge-management",title:"nav:knowledgeManagement",label:"",href:"/config/knowledge",icon:Q.jsx(af,{size:18})}',
    '{id:"coupon-management",title:"nav:couponManagement",label:"",href:"/finance/coupon",icon:Q.jsx(rf,{size:18})}',
    '{id:"gift-card-management",title:"nav:giftCardManagement",label:"",href:"/finance/gift-card",icon:Q.jsx(lf,{size:18})}',
    '{id:"ticket-management",title:"nav:ticketManagement",label:"",href:"/user/ticket",icon:Q.jsx(wf,{size:18})}'
  ];
  for(const entry of menus)source=replaceOnce(source,','+entry,'');
  const id='{accessorKey:"id",header:({column:e})=>Q.jsx(eQt,{column:e,title:t("columns.nodeId")}),cell:({row:e})=>Q.jsx(H5t,{node:e.original,t:t}),size:50,enableSorting:!0}';
  source=replaceOnce(source,id,id+',{id:"onenew-proxy-health",header:()=>"节点状态",cell:({row:e})=>Q.jsx(OnenewAdmin.HealthCell,{react:H,node:e.original}),size:100,enableSorting:!1}');
  const trafficStart=source.indexOf('N8t=[{accessorKey:"record_at"'),trafficEnd=source.indexOf('function L8t(',trafficStart);
  if(trafficStart<0||trafficEnd<0)throw new Error('用户流量弹窗边界不存在');
  let traffic=source.slice(trafficStart,trafficEnd);
  if(sha(traffic)!==contract.trafficSha256)throw new Error('用户流量弹窗 SHA256 不匹配');
  traffic=replaceOnce(traffic,'[s,o]=H.useState({pageIndex:0,pageSize:20}),','[s,o]=H.useState({pageIndex:0,pageSize:20}),[onenewDay,onenewSelect]=H.useState(null),');
  traffic=replaceOnce(traffic,'queryFn:()=>i?dT({user_id:e,pageSize:s.pageSize,page:s.pageIndex+1}):null','queryFn:()=>i?OnenewAdmin.fetchTrafficRecords({get:IL,userId:e,perPage:s.pageSize,page:s.pageIndex+1}):null');
  traffic=replaceOnce(traffic,'columns:N8t','columns:OnenewAdmin.trafficColumns({react:H,columns:N8t,timezone:a?.timezone,select:day=>onenewSelect({...day,userId:e})})');
  traffic=replaceOnce(traffic,'onOpenChange:r','onOpenChange:e=>{r(e);if(!e)onenewSelect(null)}');
  traffic=replaceOnce(traffic,'Q.jsxs("div",{className:"space-y-4",children:','onenewDay?.userId===e?Q.jsx(OnenewAdmin.TrafficDetails,{key:e+":"+onenewDay.recordAt,react:H,get:IL,post:RL,format:RS,userId:e,selection:onenewDay,onBack:()=>onenewSelect(null)}):Q.jsxs("div",{className:"space-y-4",children:');
  source=source.slice(0,trafficStart)+traffic+source.slice(trafficEnd);
  const externalButton='children:[Q.jsx(YXt,{icon:"ion:add"}),Q.jsx("div",{children:e("form.add_node")})]}),Q.jsxs(ptt';
  source=replaceOnce(source,externalButton,'children:[Q.jsx(YXt,{icon:"ion:add"}),Q.jsx("div",{children:e("form.add_node")})]}),Q.jsx(OnenewAdmin.ExternalNodes,{react:H,get:IL,post:RL}),Q.jsxs(ptt');
  source=replaceOnce(source,'Q.jsx(OnenewAdmin.ExternalNodes,{react:H,get:IL,post:RL}),','Q.jsx(OnenewAdmin.ExternalNodes,{react:H,get:IL,post:RL}),Q.jsx(OnenewAdmin.BatchAddresses,{react:H,get:IL,post:RL,useQueryClient:lC}),');
  source=replaceOnce(source,'Q.jsx(OnenewAdmin.ExternalNodes,{react:H,get:IL,post:RL}),','Q.jsx(OnenewAdmin.ExternalNodes,{react:H,get:IL,post:RL}),Q.jsx(OnenewAdmin.LandingLibraryButton,{react:H,get:IL,post:RL}),');
  source=replaceOnce(source,'custom_outbounds:gy(hy()).default([]),','onenew_landing_bindings:gy(hy()).nullable().default(null),custom_outbounds:gy(hy()).default([]),');
  source=replaceOnce(source,'custom_outbounds:[],custom_routes:[],protocol_settings:','onenew_landing_bindings:null,custom_outbounds:[],custom_routes:[],protocol_settings:');
  source=replaceOnce(source,'Q.jsx($y,{control:x.control,name:"machine_id",','Q.jsx(OnenewAdmin.NodeLandingField,{react:H,form:x,get:IL,post:RL}),Q.jsx($y,{control:x.control,name:"machine_id",');
  source=replaceOnce(source,'const t=x.getValues(),n=parseFloat(t.transfer_enable_gb||"0")','const t=x.getValues();if(t.onenew_landing_bindings!=null){const e=OnenewAdmin.validateLandingBindings(t.onenew_landing_bindings);if(e)return void gE.error(e)}const n=parseFloat(t.transfer_enable_gb||"0")');
  source=replaceOnce(source,'queryKey:["nodeList"],queryFn:async()=>{const{data:e}=await $L();return e}','queryKey:["nodeList"],queryFn:()=>OnenewAdmin.fetchManagedNodes($L,IL)');
  source=replaceOnce(source,'H.useEffect(()=>{!g&&Array.isArray(x)&&v(x)},[x,g]);','H.useEffect(()=>{const t=()=>w();window.addEventListener("onenew-external-changed",t);return()=>window.removeEventListener("onenew-external-changed",t)},[w]);H.useEffect(()=>{!g&&Array.isArray(x)&&v(x)},[x,g]);');
  source=replaceOnce(source,'columns:Z5t(w,e,b)','columns:OnenewAdmin.managedColumns(Z5t(w,e,b),H,RL,w)');
  const tableStart=source.indexOf('function X5t('),tableEnd=source.indexOf('function Q5t(',tableStart);
  let nodeTable=source.slice(tableStart,tableEnd);
  nodeTable=replaceOnce(nodeTable,'enableRowSelection:!0','enableRowSelection:e=>!e.original.onenew_external_id');
  nodeTable=replaceOnce(nodeTable,'await QL(t)','await OnenewAdmin.saveManagedOrder(RL,t)');
  source=source.slice(0,tableStart)+nodeTable+source.slice(tableEnd);
  source=replaceOnce(source,'const n=e.getRowModel().rows,i=n.length>0&&n.every(e=>e.getIsSelected());','const n=e.getRowModel().rows.filter(e=>e.getCanSelect()),i=n.length>0&&n.every(e=>e.getIsSelected());');
  source=replaceOnce(source,'rate_time_enable:uy().default(!1),rate_time_ranges:gy(t)','node_speed_limit_mbps:dy().int().min(0).max(100000).default(0),rate_time_enable:uy().default(!1),rate_time_ranges:gy(t)');
  source=replaceOnce(source,'name:"",rate:"1",rate_time_enable:','name:"",rate:"1",node_speed_limit_mbps:0,rate_time_enable:');
  source=replaceOnce(source,'Q.jsx($y,{control:x.control,name:"transfer_enable_gb",','Q.jsx(OnenewAdmin.NodeBandwidthField,{react:H,form:x}),Q.jsx($y,{control:x.control,name:"transfer_enable_gb",');
  const machineStart=source.indexOf('function n3t('),machineEnd=source.indexOf('function i3t(',machineStart);
  let machine=source.slice(machineStart,machineEnd);
  if(sha(machine)!==contract.machineEditorSha256)throw new Error('服务器编辑器 SHA256 不匹配');
  machine=replaceOnce(machine,'[h,g]=H.useState(null);','[h,g]=H.useState(null),onenewEgress=H.useRef(null),[onenewEgressReady,onenewSetReady]=H.useState(!1);');
  machine=replaceOnce(machine,'const n=await TT(t);n.data&&','const n=await TT(t);if(n.data&&"edit"===i){const t=onenewEgress.current?.();if(!t)throw new Error("出口设置尚未读取");await OnenewAdmin.saveMachineEgress(RL,t)}n.data&&');
  machine=replaceOnce(machine,'Q.jsx($y,{control:s.control,name:"is_active",','"edit"===i&&Q.jsx(OnenewAdmin.MachineEgress,{react:H,machineId:n?.id,open:o,get:IL,onChange:t=>{onenewEgress.current=t;onenewSetReady(!!t);s.trigger()}}),Q.jsx($y,{control:s.control,name:"is_active",');
  machine=replaceOnce(machine,'disabled:l||!s.formState.isValid','disabled:l||!s.formState.isValid||("edit"===i&&!onenewEgressReady)');
  machine=replaceOnce(machine,'className:"max-w-md gap-0 overflow-hidden p-0 sm:rounded-2xl"','className:"max-w-xl max-h-[90vh] gap-0 overflow-y-auto p-0 sm:rounded-2xl"');
  source=source.slice(0,machineStart)+machine+source.slice(machineEnd);
  const installStart=source.indexOf('function R3t('),installEnd=source.indexOf('function O3t(',installStart);
  if(installStart<0||installEnd<0)throw new Error('服务器安装命令组件不存在');
  let install=source.slice(installStart,installEnd);
  const installSuffix=']})]})}';
  if(!install.endsWith(installSuffix))throw new Error('服务器安装命令组件边界已变更');
  install=install.slice(0,-installSuffix.length)+',Q.jsx(OnenewAdmin.UpgradeCommand,{react:H,command:a?.upgrade_command,loading:l})'+installSuffix;
  source=source.slice(0,installStart)+install+source.slice(installEnd);
  source=replaceOnce(source,'Q.jsx(n3t,{refetch:t}),Q.jsx(u8e,','Q.jsx(n3t,{refetch:t}),Q.jsx(OnenewAdmin.RemoteUpgrade,{react:H,get:IL,post:RL,table:e}),Q.jsx(u8e,');
  source=replaceOnce(source,'Q.jsx(R3t,{machineId:e.id,open:t,t:i}),','Q.jsx(R3t,{machineId:e.id,open:t,t:i}),Q.jsx(OnenewAdmin.RemoteUpgrade,{react:H,get:IL,post:RL,machineId:e.id}),');
  source=replaceOnce(source,'const KGt=[','const KGt=[{title:"能量补充审核",key:"energy",icon:Q.jsx(Zm,{size:18}),href:"/config/system/energy",description:"审核用户补充申请"},{title:"备份与恢复",key:"migration",icon:Q.jsx(Zm,{size:18}),href:"/config/system/migration",description:"加密备份、云盘上传及网页恢复"},');
  source=replaceOnce(source,'{path:"subscribe-template",element:Q.jsx(WQe,{})}',
    '{path:"energy",element:Q.jsx(OnenewAdmin.EnergyRequests,{react:H,get:IL,post:RL})},{path:"migration",element:Q.jsx(OnenewAdmin.MigrationPage,{react:H,get:IL,post:RL})},{path:"subscribe-template",element:Q.jsx(WQe,{})}');
  source=replaceOnce(source,'{path:"plugins/:pluginCode/*",','{path:"plugins/subscription_guard/logs",element:Q.jsx(OnenewAdmin.SubscriptionLogsPage,{react:H,get:IL})},{path:"plugins/:pluginCode/*",');
  source=replaceOnce(source,'shadowsocks:{schema:h4t(t),component:p4t}',
    'shadowsocks:{schema:h4t(t).extend({onenew_transport_managed:uy().default(!1),tls:yy().default(0),network:cy().default("tcp"),network_settings:fy(hy()).nullable().optional(),tls_settings:fy(hy()).default({}),cert_config:e.optional().default({cert_mode:"none"})}),component:e=>Q.jsxs(Q.Fragment,{children:[Q.jsx(p4t,e),Q.jsx(OnenewAdmin.ShadowsocksTransport,{react:H,form:e.form})]})}');
  source=replaceOnce(source,'app_url:cy().nullable().default(""),subscribe_url:','app_url:cy().nullable().default(""),user_frontend_urls:gy(cy()).default([]),subscribe_url:');
  source=replaceOnce(source,'Q.jsx($y,{control:o.control,name:"force_https",','Q.jsx(OnenewAdmin.UserDomainsField,{react:H,form:o}),Q.jsx($y,{control:o.control,name:"force_https",');
  return runtime+'\n'+source;
}

