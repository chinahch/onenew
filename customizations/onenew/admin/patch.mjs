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
  const start=source.indexOf('function l5t('),end=source.indexOf('const c5t=',start);
  let advanced=source.slice(start,end);
  if(sha(advanced)!==contract.advancedSha256)throw new Error('高级协议组件 SHA256 不匹配');
  advanced=replaceOnce(advanced,'repeat(${2+(l?1:0)+(n?1:0)}','repeat(${1+(l?1:0)+(n?1:0)}');
  advanced=replaceOnce(advanced,'i("dynamic_form.routing.outbounds_tab","Outbounds")','"落地链接解析"');
  advanced=replaceOnce(advanced,',Q.jsx(ow,{value:"routes",className:"text-xs data-[state=active]:bg-background data-[state=active]:shadow-sm",children:i("dynamic_form.routing.routes_tab","Routes")})','');
  const paneStart=advanced.indexOf('Q.jsx(aw,{value:"outbounds"'),footer=advanced.indexOf('Q.jsxs(ftt,',paneStart);
  if(paneStart<0||footer<0)throw new Error('高级协议内容边界不存在');
  advanced=advanced.slice(0,paneStart)+'Q.jsx(aw,{value:"outbounds",className:"mt-0",children:Q.jsx(OnenewAdmin.LandingEditor,{react:H,outbounds:g,routes:_,setOutbounds:p,setRoutes:v})})]}),'+advanced.slice(footer);
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
  return runtime+'\n'+source;
}
