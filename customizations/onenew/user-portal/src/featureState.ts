export interface SubscriptionLink {path:string;needs_recovery:boolean}
export function subscriptionState(){return {path:'',needsRecovery:false,async load(read:()=>Promise<SubscriptionLink>){const data=await read();this.path=data.path||'';this.needsRecovery=data.needs_recovery}}}
export function inviteCode(search:string){return new URLSearchParams(search).get('invite')?.trim()||null}
export function cycleStamp(value:string,zone:string){return new Date(value).toLocaleString('zh-CN',{hour12:false,timeZone:zone})}
export interface Probe {health_state?:string;stage_error?:string;stages?:{version?:number;entry?:{source?:string;state?:string;primary?:{state:string;error_code?:string};fallback?:{state:string;error_code?:string}};landings:{id:string;name:string;state:string;error_code:string}[]};id?:string;state:string;latency_ms?:number|null;error_code?:string;created_at?:string;finished_at?:string|null;source?:string;ping_ms?:number|null;ping_error_code?:string}
export function probeLabel(probe?:Probe|null){
 if(!probe)return '尚未检测'
 if(probe.error_code==='machine_unavailable')return '服务器未连接'
 if(probe.error_code==='configuration_unavailable')return '配置不可用'
 if(probe.health_state==='online'&&probe.stages?.version===2){const e=probe.stages.entry;return e?.source==='cdn_roundtrip'?(e.primary?.state==='succeeded'?'原CDN正常':'备用入口正常'):'本地监听正常'}
 if(['succeeded','failed'].includes(probe.state))return ({online:'连通正常',node_fault:'节点故障',landing_fault:'落地故障',partial_landing_fault:'部分落地故障'} as Record<string,string>)[probe.health_state||'']||'检测异常'
 return ({queued:'排队中',running:'检测中',failed:'请求失败',unsupported:'不支持检测',expired:'检测超时',pending:'待检测'} as Record<string,string>)[probe.state]||'未知状态'
}

export function probeEntryDetail(probe?:Probe|null){
 const e=probe?.stages?.entry;if(probe?.stages?.version!==2||!e)return ''
 const executed=(r:any)=>r?.state==='failed'&&['transport_failed','proxy_failed','target_failed','timeout'].includes(r.error_code)
 if(e.source==='local_listener_roundtrip')return `本地监听${e.primary?.state==='succeeded'?'正常':executed(e.primary)?'异常':'未完成检测'}`
 if(e.primary?.state==='succeeded')return '原CDN正常'
 if(e.fallback)return `原CDN异常，备用 ip.sb:443 ${e.fallback.state==='succeeded'?'正常':executed(e.fallback)?'异常':'未完成检测'}`
 return executed(e.primary)?'原CDN异常，备用入口未完成检测':'原CDN未完成检测'
}
export function probeLandingDetail(landing:{state:string;error_code?:string}){
  return landing.state==='succeeded'?'落地正常':landing.state==='failed'&&['transport_failed','proxy_failed','target_failed','timeout'].includes(landing.error_code||'')?'落地故障':'落地未完成检测'
}

export function probeLandingSummary(probe?:Probe|null){
 const landings=probe?.stages?.landings||[]
 if(!landings.length)return ''
 const states=landings.map(probeLandingDetail)
 if(states.includes('落地故障'))return '落地故障'
 return states.every(state=>state==='落地正常')?'落地正常':'落地未完成检测'
}
