export interface SubscriptionLink {path:string;needs_recovery:boolean}
export function subscriptionState(){return {path:'',needsRecovery:false,async load(read:()=>Promise<SubscriptionLink>){const data=await read();this.path=data.path||'';this.needsRecovery=data.needs_recovery}}}
export function inviteCode(search:string){return new URLSearchParams(search).get('invite')?.trim()||null}
export function cycleStamp(value:string,zone:string){return new Date(value).toLocaleString('zh-CN',{hour12:false,timeZone:zone})}
export interface Probe {health_state?:string;stage_error?:string;stages?:{landings:{id:string;name:string;state:string;error_code:string}[]};id?:string;state:string;latency_ms?:number|null;error_code?:string;created_at?:string;finished_at?:string|null;source?:string;ping_ms?:number|null;ping_error_code?:string}
export function probeLabel(probe?:Probe|null){
 if(!probe)return '尚未检测'
 if(probe.error_code==='machine_unavailable')return '服务器未连接'
 if(['succeeded','failed'].includes(probe.state))return ({online:'连通正常',node_fault:'节点故障',landing_fault:'落地故障',partial_landing_fault:'部分落地故障'} as Record<string,string>)[probe.health_state||'']||'检测异常'
 return ({queued:'排队中',running:'检测中',failed:'请求失败',unsupported:'不支持检测',expired:'检测超时',pending:'待检测'} as Record<string,string>)[probe.state]||'未知状态'
}
