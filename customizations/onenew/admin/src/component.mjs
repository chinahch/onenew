import {planRules,serviceLabels,healthLabel} from './rules.mjs';

export function HealthCell({react:React,node}){
  const [now,setNow]=React.useState(Date.now());
  React.useEffect(()=>{const timer=setInterval(()=>setNow(Date.now()),30000);return()=>clearInterval(timer)},[]);
  const h=healthLabel(node,now);
  return React.createElement('span',{className:`onenew-health onenew-health-${h.tone}`,title:h.detail},h.label);
}

// React is provided by the fixed official admin bundle, keeping a single React instance.
export function LandingEditor({react:React,outbounds,routes,setOutbounds,setRoutes}){
  const h=React.createElement;
  const [link,setLink]=React.useState(''),[core,setCore]=React.useState(''),[tag,setTag]=React.useState('landing');
  const [services,setServices]=React.useState(['a']),[customDomains,setCustom]=React.useState(''),[mode,setMode]=React.useState('replace');
  const [preview,setPreview]=React.useState(null),[error,setError]=React.useState(''),[applied,setApplied]=React.useState(false);
  const edit=setter=>event=>{setter(event.target.value);setPreview(null);setError('');setApplied(false)};
  const array=text=>{if(!text.trim())return [];const value=JSON.parse(text);if(!Array.isArray(value))throw new Error('原有出口和规则必须为 JSON 数组');return value};
  function generate(){try{setPreview(planRules({link,core,tag,services,customDomains,mode,oldOutbounds:array(outbounds),oldRoutes:array(routes)}));setError('');setApplied(false)}catch(e){setPreview(null);setError(e.message)}}
  function stage(){if(!preview)return;setOutbounds(JSON.stringify(preview.outbounds,null,2));setRoutes(JSON.stringify(preview.routes,null,2));setApplied(true)}
  function selectService(key,checked){setServices(previous=>key==='g'?(checked?['g']:[]):checked?[...previous.filter(x=>x!=='g'),key]:previous.filter(x=>x!==key));setPreview(null);setApplied(false);setError('')}
  const input=(label,element)=>h('label',{className:'onenew-field'},h('span',null,label),element);
  const current=()=>{try{return `当前草稿：${array(outbounds).length} 个出口 / ${array(routes).length} 条规则`}catch{return '当前草稿 JSON 无效，请取消并检查原配置'}};
  return h('section',{className:'onenew-landing','aria-label':'落地链接解析'},
    h('p',{className:'onenew-help'},'在本地解析链接并生成目标内核的原生配置。请核对实际节点内核。'),
    h('div',{className:'onenew-row'},
      input('目标内核',h('select',{value:core,onChange:edit(setCore),'aria-label':'目标内核'},h('option',{value:''},'请选择实际内核'),h('option',{value:'singbox'},'Sing-box'),h('option',{value:'xray'},'Xray（标准 XB-node）'),h('option',{value:'xray-caddy'},'Xray（caddy 扩展）'))),
      input('出口名称',h('input',{value:tag,onChange:edit(setTag),spellCheck:false,autoComplete:'off','aria-label':'出口名称'}))),
    input('落地链接',h('textarea',{value:link,onChange:edit(setLink),rows:3,spellCheck:false,autoComplete:'off','aria-label':'落地链接',placeholder:'socks://… / ss://… / vless://… / vmess://… / trojan://… / hy2://… / tuic://…'})),
    core==='xray'&&h('p',{className:'onenew-notice'},'标准 XB-node 的 Xray 自定义出口仅支持无 TLS 的 TCP SOCKS、SS、VLESS、VMess。TLS / REALITY / WS / gRPC / flow 和 VLESS ENC 会明确拒绝。'),
    core==='xray-caddy'&&h('p',{className:'onenew-notice'},'仅适用于已支持 stream_settings 的定制 caddy 节点。可生成 TLS / REALITY / WS / gRPC / XHTTP 和 VLESS ENC；原版 XB-node 不兼容。'),
    h('fieldset',null,h('legend',null,'分流范围'),h('div',{className:'onenew-services'},
      ...[['g','全局流量'],...Object.entries(serviceLabels),['c','自定义域名']].map(([key,label])=>h('label',{key,className:'onenew-check'},h('input',{type:'checkbox',checked:services.includes(key),onChange:e=>selectService(key,e.target.checked)}),label)))),
    services.includes('c')&&input('域名后缀（逗号或换行分隔）',h('textarea',{value:customDomains,onChange:edit(setCustom),rows:2,'aria-label':'自定义域名',placeholder:'example.com, example.org',spellCheck:false})),
    input('旧配置处理',h('select',{value:mode,onChange:edit(setMode),'aria-label':'旧配置处理'},h('option',{value:'replace'},'整节点覆盖：清除全部旧规则和旧出口（默认）'),h('option',{value:'preserve'},'仅替换选定服务，其余规则和出口保留'))),
    h('p',{className:'onenew-help'},current(),'。保留模式仅接受纯域名规则；复杂、全局或未知规则需人工核对。'),
    h('button',{type:'button',className:'onenew-button',onClick:generate},'生成并预览'),
    error&&h('p',{role:'alert',className:'onenew-error'},error),
    preview&&h('div',{className:'onenew-preview'},h('p',{className:'onenew-notice'},preview.summary),
      h('p',{className:'onenew-help'},'预览含代理凭据，仅在当前浏览器生成。尚未保存到节点。'),
      h('details',{open:true},h('summary',null,'出口与原生路由 JSON'),h('pre',null,JSON.stringify({custom_outbounds:preview.outbounds,custom_routes:preview.routes},null,2))),
      h('button',{type:'button',className:'onenew-button',onClick:stage,disabled:applied},applied?'已写入本次草稿':'确认使用此预览')),
    h('p',{className:'onenew-help',role:applied?'status':undefined},applied?'已更新弹窗草稿。点击下方“保存”提交；点击“取消”放弃。':'先生成预览并确认使用，再点击下方“保存”。取消弹窗不会提交本次草稿。'));
}
