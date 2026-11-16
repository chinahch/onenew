import catalog from './catalog.json' with {type:'json'};
export const serviceLabels={a:'AI 服务',1:'Gemini',4:'YouTube',5:'Google Services',6:'Twitter/X',7:'Meta/Instagram',8:'Telegram',9:'Netflix',10:'Disney+',11:'Spotify',12:'TikTok',13:'巴哈姆特',14:'Steam',15:'Pixiv',20:'Binance 国际站',21:'Binance.US',b:'ITP'};
const fail=message=>{throw new Error(message)};
const clone=value=>JSON.parse(JSON.stringify(value));
const object=value=>value&&typeof value==='object'&&!Array.isArray(value);
function decode(value){try{return decodeURIComponent(value)}catch{fail('链接包含无效百分号编码')}}
function b64(value){
  const s=decode(value).replace(/-/g,'+').replace(/_/g,'/');
  if(!/^[A-Za-z0-9+/]*={0,2}$/.test(s)||s.replace(/=/g,'').length%4===1)fail('Base64 凭据无效');
  try{return new TextDecoder('utf-8',{fatal:true}).decode(Uint8Array.from(atob(s.replace(/=+$/,'')+'='.repeat((4-s.replace(/=+$/,'').length%4)%4)),c=>c.charCodeAt(0)))}catch{fail('Base64 凭据无效')}
}
function address(text){
  const m=/^(?:\[([^\]]+)\]|([^:\s/?#]+)):(\d+)\/?$/.exec(text);
  if(!m||+m[3]<1||+m[3]>65535)fail('服务器地址或端口无效（端口 1–65535，IPv6 请用方括号）');
  return {server:m[1]||m[2],server_port:+m[3]};
}
function params(query,allowed){
  const result={};
  for(const [key,value] of new URLSearchParams(query)){
    if(!allowed.includes(key))fail(`不支持参数 ${key}，为避免丢失配置已拒绝生成`);
    if(Object.hasOwn(result,key))fail(`重复参数 ${key}`);
    result[key]=value;
  }
  return result;
}
function bool(value){if(value===undefined)return false;if(!['0','1','true','false'].includes(value))fail('布尔参数必须为 0/1/true/false');return value==='1'||value==='true'}
function transport(p){
  const net=p.type||'tcp';
  if(net==='tcp'){if(p.path||p.host||p.serviceName)fail('TCP 链接包含不兼容传输参数');return undefined}
  if(net==='ws'){if(p.serviceName)fail('WebSocket 不支持 serviceName');return {type:'ws',path:p.path||'/',...(p.host?{headers:{Host:p.host}}:{})}}
  if(net==='grpc'){if(p.path||p.host)fail('gRPC 包含不兼容参数');return {type:'grpc',service_name:p.serviceName||''}}
  fail(`暂不支持 ${net} 传输，已拒绝生成`);
}
function tls(p,server,defaultOn=false){
  const security=p.security||(defaultOn?'tls':'none');
  if(!['none','tls','reality'].includes(security))fail('不支持的 TLS security');
  if(security==='none'){
    if(['sni','fp','pbk','sid','alpn','allowInsecure','insecure'].some(k=>p[k]!==undefined))fail('无 TLS 链接包含 TLS 参数');
    return undefined;
  }
  const t={enabled:true,server_name:p.sni||server};
  if(p.alpn)t.alpn=p.alpn.split(',');
  if(bool(p.allowInsecure)||bool(p.insecure))t.insecure=true;
  if(p.fp)t.utls={enabled:true,fingerprint:p.fp};
  if(security==='reality'){
    if(!/^[A-Za-z0-9_-]{43}$/.test(p.pbk||'')||!/^(?:[a-fA-F0-9]{2}){0,8}$/.test(p.sid||''))fail('REALITY public key 或 short id 无效');
    if(t.insecure)fail('REALITY 不支持 insecure');
    t.utls={enabled:true,fingerprint:p.fp||'chrome'};t.reality={enabled:true,public_key:p.pbk,short_id:p.sid||''};
  }else if(p.pbk!==undefined||p.sid!==undefined)fail('非 REALITY 链接包含 REALITY 参数');
  return t;
}
const tlsKeys=['security','sni','fp','pbk','sid','alpn','allowInsecure','insecure'];
const transKeys=['type','path','host','serviceName'];
const xrayFamily=core=>core==='xray'||core==='xray-caddy';
function nativeEncryption(value='none'){
  if(!value||value==='none')return 'none';
  const parts=value.split('.');
  if(parts.length<4||parts[0]!=='mlkem768x25519plus'||!['native','xorpub','random'].includes(parts[1])||!['0rtt','1rtt'].includes(parts[2]))fail('不支持的 VLESS 原生加密格式');
  for(const part of parts.slice(3)){
    if(!/^[A-Za-z0-9_-]+$/.test(part))fail('VLESS 原生加密参数无效');
    if(part.length<20)continue;
    try{const bytes=atob(part.replace(/-/g,'+').replace(/_/g,'/')+'='.repeat((4-part.length%4)%4));if(![32,1184].includes(bytes.length))fail('VLESS 原生加密密钥长度无效')}catch{fail('VLESS 原生加密密钥无效')}
  }
  return value;
}
// Mirrors Profile.Outbound in Luma and the pinned Xray f47935539965 native JSON fields.
// Only the explicit caddy capability can promote this stream_settings envelope.
function caddyStream(p,server,protocol){
  const network=({raw:'tcp',splithttp:'xhttp'})[p.type]||p.type||'tcp';
  if(!['tcp','ws','grpc','xhttp'].includes(network))fail('不支持的 Xray 传输类型');
  if(p.headerType!==undefined&&(p.headerType!=='none'||protocol!=='vless'||network!=='tcp'))fail('不支持的 headerType');
  if(network==='tcp'&&(p.path!==undefined||p.host!==undefined||p.serviceName!==undefined||p.mode!==undefined))fail('TCP 包含未支持的传输参数');
  if(network==='ws'&&(p.serviceName!==undefined||p.mode!==undefined))fail('WebSocket 包含未支持的传输参数');
  if(network==='grpc'&&(p.path!==undefined||p.host!==undefined||p.mode!==undefined))fail('gRPC 包含未支持的传输参数');
  if(network==='xhttp'&&p.serviceName!==undefined)fail('XHTTP 不支持 serviceName');
  if(p.mode!==undefined&&!['auto','packet-up','stream-up','stream-one'].includes(p.mode))fail('不支持的 XHTTP mode');
  if(p.sni!==undefined&&p.peer!==undefined&&p.sni!==p.peer)fail('sni 与 peer 不一致');
  if(bool(p.insecure)||bool(p.allowInsecure))fail('当前固定 Xray 已移除 allowInsecure；请使用有效证书或 pcs 证书固定');
  const security=p.security||(protocol==='trojan'?'tls':'none');
  if(!['none','tls','reality'].includes(security))fail('不支持的 Xray security');
  if(protocol==='trojan'&&security==='none')fail('Trojan 必须使用 TLS/REALITY');
  const stream={network,security},name=p.sni||p.peer||server;
  if(security==='none'){
    if(['sni','peer','fp','pbk','sid','alpn','spx','pcs','vcn'].some(k=>p[k]!==undefined))fail('无 TLS 链接包含 TLS 参数');
  }else if(security==='reality'){
    if(p.alpn!==undefined||p.pcs!==undefined||p.vcn!==undefined)fail('REALITY 无法承载 alpn/pcs/vcn，已拒绝丢弃参数');
    if(!/^[A-Za-z0-9_-]{43}$/.test(p.pbk||'')||!/^(?:[a-fA-F0-9]{2}){0,8}$/.test(p.sid||''))fail('REALITY public key 或 short id 无效');
    stream.realitySettings={serverName:name,publicKey:p.pbk,shortId:p.sid||'',fingerprint:p.fp||'chrome'};
    if(p.spx!==undefined){
      if(!p.spx.startsWith('/')||p.spx.length>4096||/[\r\n\x00]/.test(p.spx)||/%(?![0-9a-f]{2})/i.test(p.spx))fail('REALITY spider path 无效');
      stream.realitySettings.spiderX=p.spx;
    }
  }else{
    if(['pbk','sid','spx'].some(k=>p[k]!==undefined))fail('普通 TLS 包含 REALITY 参数');
    const t={serverName:name};
    if(p.fp!==undefined)t.fingerprint=p.fp;
    if(p.alpn!==undefined){if(!p.alpn||p.alpn.split(',').some(x=>!x))fail('ALPN 无效');t.alpn=p.alpn.split(',')}
    if(p.pcs!==undefined){if(!p.pcs||p.pcs.split(',').some(pin=>!/^[a-fA-F0-9]{64}$/.test(pin.trim().replace(/:/g,''))))fail('证书 SHA256 pin 无效');t.pinnedPeerCertSha256=p.pcs}
    if(p.vcn!==undefined){if(!p.vcn||p.vcn.length>1024||/[\r\n\x00]/.test(p.vcn))fail('证书校验名称无效');t.verifyPeerCertByName=p.vcn}
    stream.tlsSettings=t;
  }
  if(network==='ws')stream.wsSettings={path:p.path||'',...(p.host!==undefined?{headers:{Host:p.host}}:{})};
  if(network==='grpc')stream.grpcSettings={serviceName:p.serviceName||''};
  if(network==='xhttp')stream.xhttpSettings={path:p.path||'',...(p.host!==undefined?{host:p.host}:{}),...(p.mode!==undefined?{mode:p.mode}:{})};
  return stream;
}
export function parseLink(raw,core,tag){
  if(!['singbox','xray','xray-caddy'].includes(core))fail('请选择目标内核');
  if(!/^[A-Za-z0-9_-]{1,80}$/.test(tag))fail('出口名称仅支持 1–80 位英文字母、数字、下划线或短横线');
  if(['direct','block','dns-out','__xboard_ipfamily_deny'].includes(tag.toLowerCase()))fail('该出口名称为内核保留名称，请使用其他名称');
  const input=String(raw||'').trim(); if(input.length>16384)fail('链接过长');
  const m=/^([a-z0-9]+):\/\/(.*)$/i.exec(input);if(!m)fail('请输入有效代理链接');
  let protocol=m[1].toLowerCase(),body=m[2].split('#')[0],settings,stream;
  if(protocol==='vmess'){
    let v;try{v=JSON.parse(b64(body))}catch{fail('VMess Base64 JSON 无效')}
    if(!object(v))fail('VMess 配置无效');
    const allowed=['v','ps','add','port','id','aid','scy','net','type','host','path','tls','sni','alpn','fp'];
    if(Object.keys(v).some(k=>!allowed.includes(k)))fail('VMess 包含未支持字段');
    if(v.type&&v.type!=='none')fail('不支持 VMess 伪装类型');
    if(v.tls&&!['tls','none'].includes(v.tls))fail('VMess TLS 无效');
    settings={...address(`${String(v.add).includes(':')?'['+v.add+']':v.add}:${v.port}`),uuid:v.id,security:v.scy||'auto',alter_id:Number(v.aid||0)};
    if(!Number.isInteger(settings.alter_id)||settings.alter_id<0)fail('VMess alterId 无效');
    const p={type:v.net||'tcp',...(v.path?{path:v.path}:{}),...(v.host?{host:v.host}:{}),...(v.tls==='tls'?{security:'tls'}:{}),...(v.sni?{sni:v.sni}:{}),...(v.alpn?{alpn:v.alpn}:{}),...(v.fp?{fp:v.fp}:{})};
    if(core==='xray-caddy')stream=caddyStream(p,settings.server,protocol);
    else{const tr=transport(p),t=tls(p,settings.server);if(tr)settings.transport=tr;if(t)settings.tls=t}
  }else{
    // Split at @ before parsing URL delimiters: standard Base64 auth may contain '/'.
    const at=body.lastIndexOf('@');let auth=at<0?'':body.slice(0,at),destination=at<0?body:body.slice(at+1);
    const q=destination.indexOf('?'),query=q<0?'':destination.slice(q+1);destination=q<0?destination:destination.slice(0,q);
    if(protocol==='ss'&&at<0){const decoded=b64(body);const sep=decoded.lastIndexOf('@');if(sep<0)fail('SS 链接无效');auth=decoded.slice(0,sep);destination=decoded.slice(sep+1)}
    settings=address(destination);
    if(['socks','socks5'].includes(protocol)){
      protocol='socks';params(query,[]);settings.version='5';
      if(auth){
        const rawSeparator=auth.indexOf(':');
        if(rawSeparator>=0){settings.username=decode(auth.slice(0,rawSeparator));settings.password=decode(auth.slice(rawSeparator+1))}
        else {auth=decode(auth);if(!auth.includes(':'))auth=b64(auth);const sep=auth.indexOf(':');if(sep<1)fail('SOCKS 认证必须包含用户名和密码');settings.username=auth.slice(0,sep);settings.password=auth.slice(sep+1)}
        if(!settings.username)fail('SOCKS 用户名不能为空');
      }
    }else if(protocol==='ss'){
      protocol='shadowsocks';params(query,[]);auth=decode(auth);if(!auth.includes(':'))auth=b64(auth);const sep=auth.indexOf(':');if(sep<1||!auth.slice(sep+1))fail('SS 方法或密码为空');settings.method=auth.slice(0,sep);settings.password=auth.slice(sep+1);
      if(!['aes-128-gcm','aes-256-gcm','chacha20-ietf-poly1305','2022-blake3-aes-128-gcm','2022-blake3-aes-256-gcm','2022-blake3-chacha20-poly1305'].includes(settings.method))fail('暂不支持此 Shadowsocks 加密方法');
    }else if(protocol==='vless'||protocol==='trojan'){
      const p=params(query,[...tlsKeys,...transKeys,...(protocol==='vless'?['encryption','flow','headerType']:[]),...(core==='xray-caddy'?['peer','spx','mode','pcs','vcn']:[])]);
      // An explicit TCP "none" header is identical to the omitted default.
      // Other values require an actual transport implementation, not omission.
      if(p.headerType!==undefined&&(p.headerType!=='none'||!['tcp',...(core==='xray-caddy'?['raw']:[])].includes(p.type||'tcp')))fail('不支持的 headerType');
      if(protocol==='vless'){
        if(core==='xray-caddy')settings.encryption=nativeEncryption(p.encryption);
        else if(p.encryption&&p.encryption!=='none')fail('当前标准 XB-node 无法承载 VLESS ENC 加密，已拒绝生成');
        settings.uuid=decode(auth);if(p.flow){if(p.flow!=='xtls-rprx-vision')fail('不支持的 VLESS flow');settings.flow=p.flow}
      }else {settings.password=decode(auth);if(!settings.password)fail('Trojan 密码为空')}
      if(core==='xray-caddy'){
        stream=caddyStream(p,settings.server,protocol);
        if(settings.flow&&settings.encryption==='none'&&(stream.network!=='tcp'||stream.security==='none'))fail('Vision flow 需要 TLS/REALITY TCP 或 VLESS 原生加密');
      }else{
        const tr=transport(p),t=tls(p,settings.server,protocol==='trojan');if(tr)settings.transport=tr;if(t)settings.tls=t;
        if(settings.flow&&(!t||t.insecure||tr))fail('Vision flow 需要 TLS/REALITY TCP');
      }
    }else if(['hysteria2','hy2','tuic'].includes(protocol)){
      const p=params(query,['sni','alpn','insecure','allowInsecure','obfs','obfs-password','upmbps','downmbps','congestion_control','udp_relay_mode']);
      settings.tls=tls({...p,security:'tls'},settings.server);
      if(protocol==='tuic'){
        const credentials=decode(auth),sep=credentials.indexOf(':');if(sep<0)fail('TUIC 认证无效');settings.uuid=credentials.slice(0,sep);settings.password=credentials.slice(sep+1);if(!settings.password)fail('TUIC 密码为空');
        if(p.obfs||p['obfs-password']||p.upmbps||p.downmbps)fail('TUIC 包含 Hysteria2 参数');
        if(p.congestion_control){if(!['cubic','new_reno','bbr'].includes(p.congestion_control))fail('TUIC 拥塞参数无效');settings.congestion_control=p.congestion_control}
        if(p.udp_relay_mode){if(!['native','quic'].includes(p.udp_relay_mode))fail('TUIC UDP 参数无效');settings.udp_relay_mode=p.udp_relay_mode}
      }else{
        protocol='hysteria2';settings.password=decode(auth);if(!settings.password)fail('Hysteria2 密码为空');if(p.congestion_control||p.udp_relay_mode)fail('Hysteria2 包含 TUIC 参数');
        for(const k of ['upmbps','downmbps'])if(p[k]!==undefined){if(!/^\d+$/.test(p[k]))fail('速率参数无效');settings[k==='upmbps'?'up_mbps':'down_mbps']=+p[k]}
        if(p.obfs){if(p.obfs!=='salamander'||!p['obfs-password'])fail('仅支持带密码的 salamander 混淆');settings.obfs={type:'salamander',password:p['obfs-password']}}else if(p['obfs-password'])fail('缺少混淆类型');
      }
    }else fail(`不支持 ${protocol} 链接`);
  }
  if(['vless','vmess','tuic'].includes(protocol)&&!(/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i.test(settings.uuid||'')))fail('UUID 格式无效');
  if(xrayFamily(core)){
    if(core==='xray'&&(!['socks','shadowsocks','vless','vmess'].includes(protocol)||settings.tls||settings.transport||settings.flow))fail('标准 XB-node Xray custom_outbounds 不支持 TLS/REALITY/WS/gRPC/flow；请改用 Sing-box 或无 TLS 的 TCP 链接');
    if(core==='xray-caddy'&&!['socks','shadowsocks','vless','vmess','trojan'].includes(protocol))fail('此协议仅支持 Sing-box');
    const server={address:settings.server,port:settings.server_port};
    if(protocol==='socks'){if(settings.username)server.users=[{user:settings.username,pass:settings.password}];settings={servers:[server]}}
    else if(protocol==='shadowsocks'||protocol==='trojan')settings={servers:[{...server,...(protocol==='shadowsocks'?{method:settings.method}:{}),password:settings.password}]};
    else settings={vnext:[{...server,users:[protocol==='vless'?{id:settings.uuid,encryption:settings.encryption||'none',...(settings.flow?{flow:settings.flow}:{})}:{id:settings.uuid,alterId:settings.alter_id,security:settings.security}]}]};
  }
  return {tag,protocol,settings,...(core==='xray-caddy'?{stream_settings:stream||{network:'tcp',security:'none'}}:{})};
}
function domain(value){
  let s=String(value).trim().toLowerCase().replace(/^domain:/,'').replace(/^\*\./,'').replace(/^\./,'');
  if(!/^(?=.{3,253}$)[a-z0-9_-]+(?:\.[a-z0-9_-]+)+$/.test(s))fail(`域名无效：${value}`);return s;
}
const ref=r=>r.outboundTag||r.outbound;
function safeDomains(r,core){
  const keys=Object.keys(r).sort().join(',');
  const key=core==='xray'?'domain':'domain_suffix';
  const safe=core==='xray'?keys==='domain,outboundTag,type'&&r.type==='field':keys==='domain_suffix,outbound';
  return safe&&typeof ref(r)==='string'&&Array.isArray(r[key])&&r[key].length&&r[key].every(v=>typeof v==='string'&&(core!=='xray'||v.startsWith('domain:')))?key:null;
}
export function planRules({link,core,tag,services,customDomains='',mode='replace',oldOutbounds=[],oldRoutes=[]}){
  if(!['replace','preserve'].includes(mode))fail('无效覆盖模式');
  if(!Array.isArray(oldOutbounds)||!Array.isArray(oldRoutes)||[...oldOutbounds,...oldRoutes].some(x=>!object(x)))fail('现有 JSON 必须为对象数组');
  if(!Array.isArray(services)||!services.length||services.some(k=>!Object.hasOwn(catalog,k)&&!['g','c'].includes(k))||services.includes('g')&&services.length!==1)fail('请选择服务；全局模式不能与分流混用');
  const out=parseLink(link,core,tag),global=services.includes('g'),selected=[];
  // Both Xray variants share native routes; capability selection only changes outbound encoding.
  core=xrayFamily(core)?'xray':core;
  for(const k of new Set(services)){
    if(k==='g')continue;
    const ds=k==='c'?customDomains.split(/[,，\s]+/).filter(Boolean).map(domain):catalog[k];
    if(!ds.length)fail('自定义域名不能为空');selected.push(...ds);
  }
  const domains=[...new Set(selected)],field=core==='xray'?'outboundTag':'outbound';
  const rule=core==='xray'?{type:'field',...(global?{network:'tcp,udp'}:{domain:domains.map(d=>'domain:'+d)}),outboundTag:tag}:{outbound:tag,...(!global?{domain_suffix:domains}:{})};
  let routes=[rule],outbounds=[out];
  if(mode==='preserve'){
    if(global)fail('全局落地必须使用整节点覆盖，避免保留规则抢先匹配');
    const seenTags=new Set();
    for(const o of oldOutbounds){
      if(typeof o.tag!=='string'||!o.tag)fail('现有出口缺少 tag');
      if(seenTags.has(o.tag))fail('现有出口存在重复 tag，无法安全保留');seenTags.add(o.tag);
      if(object(o.settings)&&(['socks','shadowsocks','vless','vmess'].includes(o.protocol))){
        if(core==='singbox'&&('servers'in o.settings||'vnext'in o.settings)||core==='xray'&&('server'in o.settings||'server_port'in o.settings))fail('现有出口与目标内核不同，无法安全保留');
      }
    }
    const target=new Set(domains),changedTags=new Set(),remaining=[];
    const selectedDomain=value=>{const d=domain(value);return target.has(d)||services.some(k=>['20','21'].includes(k)&&catalog[k].some(anchor=>d.endsWith('.'+anchor)))};
    for(const [index,r] of oldRoutes.entries()){
      if(core==='xray'&&'outbound'in r||core==='singbox'&&'outboundTag'in r)fail('现有规则内核不同，无法安全保留');
      const dk=core==='xray'?'domain':'domain_suffix',ds=r[dk];
      // Any non-domain rule could shadow the selected service; selective editing is fail closed.
      const safe=safeDomains(r,core);
      if(!safe)fail(`第 ${index+1} 条为复杂、全局或未知规则，无法安全选择性合并`);
      if(!ds.some(selectedDomain)){remaining.push(clone(r));continue}
      changedTags.add(ref(r));const kept=ds.filter(d=>!selectedDomain(d));if(kept.length)remaining.push({...clone(r),[dk]:kept});
    }
    const existing=oldOutbounds.find(o=>o.tag===tag);
    if(existing&&JSON.stringify(existing)!==JSON.stringify(out)&&remaining.some(r=>ref(r)===tag))fail('出口名称被保留服务共用；请更换出口名称，避免改变其他服务');
    routes=[rule,...remaining];const used=new Set(routes.map(ref));
    outbounds=oldOutbounds.filter(o=>o.tag!==tag&&(!changedTags.has(o.tag)||used.has(o.tag))).map(clone);outbounds.push(out);
  }
  if(routes.length>500||outbounds.length>300)fail('规则或出口数量超过安全上限');
  return {outbounds,routes,summary:`${mode==='replace'?'整节点覆盖：清除全部旧出口和规则':'仅替换选定服务：保留其他规则'}；出口 ${oldOutbounds.length} → ${outbounds.length}，规则 ${oldRoutes.length} → ${routes.length}。`};
}
export function healthLabel(node,now=Date.now()){
  const h=node?.proxy_health,t=Date.parse(h?.finished_at||h?.created_at||''),age=now-t;
  if(Number.isFinite(t)&&age>=0&&age<600000&&h?.state==='running')return {label:'检测中',tone:'pending',detail:'服务器正在执行真实代理自测'};
  if(h?.state==='unsupported')return {label:'不支持检测',tone:'pending',detail:'当前节点或落地协议不支持自动检测'};
  if(h?.error_code==='configuration_unavailable')return {label:'配置不可用',tone:'pending',detail:'未能构建完整检测配置，尚未归因故障'};
  if(h?.state==='expired')return {label:'检测超时',tone:'pending',detail:'上次自测任务已过期，等待服务器重新领取检测任务'};
  if(!Number.isFinite(t)||age<0||age>=600000||!['succeeded','failed'].includes(h?.state))return {label:'待检测',tone:'pending',detail:'尚无十分钟内的真实代理检测结果'};
  const labels={online:'连通在线',node_fault:'节点故障',landing_fault:'落地故障',partial_landing_fault:'部分落地故障',unknown:'检测异常'};
  const health=h.health_state||'unknown';
  const errors={transport_failed:'连接失败，无法确定入口/目标',proxy_failed:'代理连接失败',target_failed:'检测目标异常',timeout:'检测超时',configuration_unavailable:'配置不可用',unsupported:'不支持检测',expired:'已过期'};
  const sources={public_roundtrip:'公开入口完整路径检测',cdn_roundtrip:'CDN入口完整路径检测',local_listener_roundtrip:h.state==='succeeded'?'本地监听自测通过，未验证公网回程':'本地监听自测失败，未验证公网回程'};
  const source=sources[h.stages?.entry?.source]||'入口自测来源未确认';
  const detail=(h.stages?.landings||[]).map(l=>`${l.name}：${l.state==='succeeded'?'正常':errors[l.error_code]||'检测异常'}`).join('；');
  return {label:labels[health]||'检测异常',tone:health==='online'?'success':health==='unknown'?'pending':'failed',detail:`${source}；${h.stage_error==='upgrade_required'?'请升级节点以支持分阶段检测':detail||errors[h.error_code]||'未配置落地'}${health==='unknown'?'；证据不足，未归因节点或落地':''}`};
}
