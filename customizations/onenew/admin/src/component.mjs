import {planRules,serviceLabels,healthLabel} from './rules.mjs';
import {coreLabels,resolveLandingCore,hydrateLanding,prepareLandingSave,landingArray} from './landing-state.mjs';
import {LandingProbe} from './landing-probe.mjs';
export {prepareLandingSave} from './landing-state.mjs';

export function HealthCell({react:React,node}){
  const [now,setNow]=React.useState(Date.now());
  React.useEffect(()=>{const timer=setInterval(()=>setNow(Date.now()),30000);return()=>clearInterval(timer)},[]);
  const h=healthLabel(node,now);
  return React.createElement('span',{className:`onenew-health onenew-health-${h.tone}`,title:h.detail},h.label);
}

// React is provided by the fixed official admin bundle, keeping a single React instance.
export function LandingEditor({react:React,outbounds,routes,setOutbounds,setRoutes,runtimeMetrics,network,nodeId,machineId,get,post,onRuntime}){
  const h=React.createElement;
  const [runtimeReport,setRuntimeReport]=React.useState(null);
  React.useEffect(()=>{
    if(typeof get!=='function'||!Number.isSafeInteger(Number(nodeId))||Number(nodeId)<=0)return;
    let active=true;
    const refresh=async()=>{try{const report=await get(`/api/onenew/admin/node-runtime/${Number(nodeId)}`,{baseURL:'/'});if(active){setRuntimeReport(report);if(!Number(machineId)||report.machine_id===Number(machineId))onRuntime?.(report)}}catch{if(active)setRuntimeReport({node_id:Number(nodeId),machine_id:Number(machineId)})}};
    refresh();const timer=setInterval(refresh,10000);return()=>{active=false;clearInterval(timer)};
  },[nodeId,machineId,get]);
  const actualMetrics=runtimeReport&&runtimeReport.node_id===Number(nodeId)
    ? (Number(machineId)&&runtimeReport.machine_id!==Number(machineId)?{}:runtimeReport):runtimeMetrics;
  const core=resolveLandingCore(actualMetrics,network);
  let existing;try{existing=hydrateLanding(outbounds,routes)}catch{existing={tag:'landing',services:[],customDomains:'',hasSaved:true,savedCore:''}}
  const [link,setLink]=React.useState(''),[tag,setTag]=React.useState(existing.tag);
  const [services,setServices]=React.useState(existing.services),[customDomains,setCustom]=React.useState(existing.customDomains),[mode,setMode]=React.useState('replace');
  const [preview,setPreview]=React.useState(null),[error,setError]=React.useState(''),[applied,setApplied]=React.useState(false);
  React.useEffect(()=>{setTag(existing.tag);setServices(existing.services);setCustom(existing.customDomains);setPreview(null);setError('');setApplied(false)},[outbounds,routes,core]);
  const edit=setter=>event=>{setter(event.target.value);setPreview(null);setError('');setApplied(false)};
  const array=text=>{if(!text.trim())return [];const value=JSON.parse(text);if(!Array.isArray(value))throw new Error('原有出口和规则必须为 JSON 数组');return value};
  function generate(){if(!core){setError('等待机器上报实际运行内核');return}try{setPreview(planRules({link,core,tag,services,customDomains,mode,oldOutbounds:array(outbounds),oldRoutes:array(routes)}));setError('');setApplied(false)}catch(e){setPreview(null);setError(e.message)}}
  function stage(){if(!preview)return;setOutbounds(JSON.stringify(preview.outbounds,null,2));setRoutes(JSON.stringify(preview.routes,null,2));setApplied(true)}
  function selectService(key,checked){setServices(previous=>key==='g'?(checked?['g']:[]):checked?[...previous.filter(x=>x!=='g'),key]:previous.filter(x=>x!==key));setPreview(null);setApplied(false);setError('')}
  const input=(label,element)=>h('label',{className:'onenew-field'},h('span',null,label),element);
  const current=()=>{try{return `当前草稿：${array(outbounds).length} 个出口 / ${array(routes).length} 条规则`}catch{return '当前草稿 JSON 无效，请取消并检查原配置'}};
  let probeOutbound=null;
  try{
    const candidate=preview?{outbounds:preview.outbounds,routes:preview.routes}:{outbounds:array(outbounds),routes:array(routes)};
    const prepared=prepareLandingSave({...candidate,runtimeMetrics:actualMetrics,network});
    if(core)probeOutbound=prepared.outbounds.find(out=>out.tag===tag)||prepared.outbounds[0]||null;
  }catch{/* Incompatible native drafts stay editable and cannot be tested as another kernel. */}
  return h('section',{className:'onenew-landing','aria-label':'落地链接解析'},
    h('p',{className:'onenew-help'},core?'已根据机器上报自动识别运行内核：'+coreLabels[core]:'等待机器上报实际运行内核，暂时不能生成或检测落地。'),
    existing.hasSaved&&h('p',{className:'onenew-notice'},'已保存格式：'+(coreLabels[existing.savedCore]||'复杂或混合配置')+'。原生 JSON 保留凭据及未知字段；无需重新粘贴链接。'+(core&&existing.savedCore&&existing.savedCore!==(core.startsWith('xray')?'xray':core)?' 保存时自动转换可完整表达的 SOCKS 出口和路由，复杂配置会拒绝保存。':'')),
    h('div',{className:'onenew-row'},
      input('目标内核',h('output',{'aria-label':'自动识别内核'},coreLabels[core]||'等待机器上报')),
      input('出口名称',h('input',{value:tag,onChange:edit(setTag),spellCheck:false,autoComplete:'off','aria-label':'出口名称'}))),
    input('新落地链接（替换已有配置时填写）',h('textarea',{value:link,onChange:edit(setLink),rows:3,spellCheck:false,autoComplete:'off','aria-label':'落地链接',placeholder:'socks://… / ss://… / vless://… / vmess://… / trojan://… / hy2://… / tuic://…'})),
    core==='xray'&&h('p',{className:'onenew-notice'},'标准 XB-node 的 Xray 自定义出口仅支持无 TLS 的 TCP SOCKS、SS、VLESS、VMess。TLS / REALITY / WS / gRPC / flow 和 VLESS ENC 会明确拒绝。'),
    core==='xray-caddy'&&h('p',{className:'onenew-notice'},'仅适用于已支持 stream_settings 的定制 caddy 节点。可生成 TLS / REALITY / WS / gRPC / XHTTP 和 VLESS ENC；原版 XB-node 不兼容。'),
    existing.hasSaved&&h('details',null,h('summary',null,'查看或编辑已保存的原生 JSON'),
      input('出口 JSON',h('textarea',{value:outbounds,onChange:e=>setOutbounds(e.target.value),rows:6,spellCheck:false,autoComplete:'off','aria-label':'已保存出口 JSON'})),
      input('路由 JSON',h('textarea',{value:routes,onChange:e=>setRoutes(e.target.value),rows:6,spellCheck:false,autoComplete:'off','aria-label':'已保存路由 JSON'}))),
    existing.hasSaved&&!existing.services.length&&h('p',{className:'onenew-notice'},'已有复杂或多出口规则，实际分流范围请查看原生 JSON；未选择新的范围时不会重建已有规则。'),
    h('fieldset',null,h('legend',null,'分流范围'),h('div',{className:'onenew-services'},
      ...[['g','全局流量'],...Object.entries(serviceLabels),['c','自定义域名']].map(([key,label])=>h('label',{key,className:'onenew-check'},h('input',{type:'checkbox',checked:services.includes(key),onChange:e=>selectService(key,e.target.checked)}),label)))),
    services.includes('c')&&input('域名后缀（逗号或换行分隔）',h('textarea',{value:customDomains,onChange:edit(setCustom),rows:2,'aria-label':'自定义域名',placeholder:'example.com, example.org',spellCheck:false})),
    input('旧配置处理',h('select',{value:mode,onChange:edit(setMode),'aria-label':'旧配置处理'},h('option',{value:'replace'},'整节点覆盖：清除全部旧规则和旧出口（默认）'),h('option',{value:'preserve'},'仅替换选定服务，其余规则和出口保留'))),
    h('p',{className:'onenew-help'},current(),'。保留模式仅接受纯域名规则；复杂、全局或未知规则需人工核对。'),
    h('button',{type:'button',className:'onenew-button',onClick:generate,disabled:!core},'生成并预览'),
    error&&h('p',{role:'alert',className:'onenew-error'},error),
    preview&&h('div',{className:'onenew-preview'},h('p',{className:'onenew-notice'},preview.summary),
      h('p',{className:'onenew-help'},'预览含代理凭据，仅在当前浏览器生成。尚未保存到节点。'),
      h('details',{open:true},h('summary',null,'出口与原生路由 JSON'),h('pre',null,JSON.stringify({custom_outbounds:preview.outbounds,custom_routes:preview.routes},null,2))),
      h('button',{type:'button',className:'onenew-button',onClick:stage,disabled:applied},applied?'已写入本次草稿':'确认使用此预览')),
    h(LandingProbe,{react:React,nodeId,machineId,core,outbound:probeOutbound,post,get}),
    h('p',{className:'onenew-help',role:applied?'status':undefined},applied?'已更新弹窗草稿。点击下方“保存”提交；点击“取消”放弃。':existing.hasSaved?'已有配置可直接保存；填写新链接后可生成替换预览。取消弹窗不会提交本次草稿。':'先生成预览并确认使用，再点击下方“保存”。取消弹窗不会提交本次草稿。'));
}
