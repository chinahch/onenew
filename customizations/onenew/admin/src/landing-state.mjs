import catalog from './catalog.json' with {type:'json'};
const clone=value=>JSON.parse(JSON.stringify(value));
const object=value=>value&&typeof value==='object'&&!Array.isArray(value);
const fail=message=>{throw new Error(message)};
const family=core=>core.startsWith('xray')?'xray':core;
export const coreLabels={singbox:'Sing-box',xray:'Xray（标准 XB-node）','xray-caddy':'Xray（Onenew 扩展）'};
export function resolveLandingCore(runtimeMetrics={},network,now=Date.now()){
  const {kernel_type:reported,updated_at:updated}=runtimeMetrics||{};
  if(!['xray','singbox'].includes(reported))return '';
  if(updated!==undefined&&(!Number.isFinite(Number(updated))||now-Number(updated)*1000>600000||Number(updated)*1000-now>60000))return '';
  if(reported==='singbox'&&['xhttp','splithttp'].includes(network))return '';
  const kernel=reported;
  return kernel==='xray'&&runtimeMetrics.onenew_runtime===true?'xray-caddy':kernel;
}
export function landingArray(value){const parsed=typeof value==='string'?(value.trim()?JSON.parse(value):[]):value??[];if(!Array.isArray(parsed)||parsed.some(x=>!object(x)))fail('出口和规则必须为 JSON 对象数组');return parsed;}
export function savedCore(outbounds,routes){
 const kinds=new Set();for(const out of outbounds){const s=out.settings;if(!object(s))continue;if('server'in s||'server_port'in s)kinds.add('singbox');if('servers'in s||'vnext'in s)kinds.add('xray');}
 for(const r of routes){if('outboundTag'in r)kinds.add('xray');if('outbound'in r)kinds.add('singbox');}
 return kinds.size===1?[...kinds][0]:'';
}
function strictKeys(value,allowed){return object(value)&&Object.keys(value).every(key=>allowed.includes(key));}
function convertSocks(out,target){
 if(out.protocol!=='socks'||!strictKeys(out,['tag','protocol','settings']))fail('已有出口包含不能完整转换的协议或字段；原配置已保留，请在原生 JSON 中核对');
 const s=out.settings;
 if(target==='xray'){
  if(!strictKeys(s,['server','server_port','version','username','password'])||s.version!=='5'||typeof s.server!=='string'||!Number.isInteger(s.server_port)||s.server_port<1||s.server_port>65535||('password'in s&&!('username'in s)))fail('已有 SOCKS 字段无法完整转换为 Xray；原配置已保留');
  if('username'in s&&(typeof s.username!=='string'||!s.username||typeof s.password!=='string'))fail('已有 SOCKS 认证无法完整转换；原配置已保留');
  const server={address:s.server,port:s.server_port,...('username'in s?{users:[{user:s.username,pass:s.password}]}:{})};
  return{tag:out.tag,protocol:'socks',settings:{servers:[server]}};
 }
 if(!strictKeys(s,['servers'])||!Array.isArray(s.servers)||s.servers.length!==1)fail('已有 SOCKS 多服务器配置无法完整转换为 Sing-box；原配置已保留');
 const server=s.servers[0];if(!strictKeys(server,['address','port','users'])||typeof server.address!=='string'||!Number.isInteger(server.port)||server.port<1||server.port>65535||server.users&&(!Array.isArray(server.users)||server.users.length!==1||!strictKeys(server.users[0],['user','pass'])||typeof server.users[0].user!=='string'||!server.users[0].user||typeof server.users[0].pass!=='string'))fail('已有 SOCKS 字段无法完整转换为 Sing-box；原配置已保留');
 return{tag:out.tag,protocol:'socks',settings:{server:server.address,server_port:server.port,version:'5',...(server.users?{username:server.users[0].user,password:server.users[0].pass}:{})}};
}
function convertRoute(route,target){
 if(target==='xray'){
  if(!strictKeys(route,['outbound','domain_suffix'])||typeof route.outbound!=='string'||route.domain_suffix&&(!Array.isArray(route.domain_suffix)||!route.domain_suffix.length||route.domain_suffix.some(x=>typeof x!=='string'||!x)))fail('已有路由无法完整转换为 Xray；原配置已保留');
  return{type:'field',...(route.domain_suffix?{domain:route.domain_suffix.map(d=>'domain:'+d)}:{network:'tcp,udp'}),outboundTag:route.outbound};
 }
 if(!strictKeys(route,['type','outboundTag','network','domain'])||route.type!=='field'||typeof route.outboundTag!=='string'||(route.network&&route.network!=='tcp,udp')||Boolean(route.network)===Boolean(route.domain)||route.domain&&(!Array.isArray(route.domain)||!route.domain.length||route.domain.some(x=>typeof x!=='string'||!x.startsWith('domain:')||x.length<=7)))fail('已有路由无法完整转换为 Sing-box；原配置已保留');
 return{outbound:route.outboundTag,...(route.domain?{domain_suffix:route.domain.map(d=>d.slice(7))}:{})};
}
export function prepareLandingSave({outbounds,routes,runtimeMetrics,network}){
 const oldOut=landingArray(outbounds),oldRoutes=landingArray(routes),core=resolveLandingCore(runtimeMetrics,network);
 if(!core)return{outbounds:clone(oldOut),routes:clone(oldRoutes),migrated:false,core:''};
 const target=family(core);let migrated=false;
 const convertedOut=oldOut.map(out=>{
  const kind=savedCore([out],[]);
  if(kind&&kind!==target){migrated=true;return convertSocks(out,target)}
  if(core==='xray'&&out.stream_settings)fail('当前标准 Xray 不支持已保存的 stream_settings；原配置已保留');
  return clone(out);
 });
 const convertedRoutes=oldRoutes.map(route=>{const kind=savedCore([],[route]);if(kind&&kind!==target){migrated=true;return convertRoute(route,target)}return clone(route)});
 return{outbounds:convertedOut,routes:convertedRoutes,migrated,core};
}
export function hydrateLanding(outbounds,routes){
 const outs=landingArray(outbounds),rules=landingArray(routes),tag=outs[0]?.tag||'landing',saved=savedCore(outs,rules);
 const selected=rules.filter(r=>(r.outboundTag||r.outbound)===tag);
 let services=[],customDomains='';
 if(selected.length===1&&(Object.keys(selected[0]).length===1&&selected[0].outbound===tag||selected[0].type==='field'&&selected[0].network==='tcp,udp'&&Object.keys(selected[0]).length===3))services=['g'];
 else if(selected.length&&selected.every(r=>strictKeys(r,['outbound','domain_suffix'])&&Array.isArray(r.domain_suffix)||strictKeys(r,['type','outboundTag','domain'])&&r.type==='field'&&Array.isArray(r.domain)&&r.domain.every(d=>typeof d==='string'&&d.startsWith('domain:')))){
  const domains=new Set(selected.flatMap(r=>r.domain_suffix||r.domain.map(d=>d.slice(7))));
  const exact=Object.entries(catalog).find(([,ds])=>ds.length===domains.size&&ds.every(d=>domains.has(d)));
  if(exact)services=[exact[0]];
  else{services=['c'];customDomains=[...domains].join('\n');}
 }
 return{tag,services,customDomains,savedCore:saved,hasSaved:outs.length>0||rules.length>0};
}
