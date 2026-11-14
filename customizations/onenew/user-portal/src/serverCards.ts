import type {Probe} from './featureState.ts'
import {probeLabel} from './featureState.ts'
export function nodeAvailability(node:any,now=Date.now()):{text:string;tone:'success'|'error'|'warning'|'default';group:'online'|'abnormal'|'pending'}{
 const view=automaticHealthView(node.latest_probe,now)
 return {...view,text:view.tone==='success'?'在线':view.tone==='error'?'异常':view.text,group:view.tone==='success'?'online':view.tone==='error'?'abnormal':'pending'}
}
export function automaticHealthView(probe:Probe|null|undefined,now=Date.now()):{text:string;tone:'success'|'error'|'warning'|'default'}{
 if(!probe)return {text:'等待服务器自测',tone:'default'}
 const at=Date.parse(probe.finished_at||probe.created_at||'')
 if(!Number.isFinite(at)||now-at< -30000)return {text:'等待有效检测结果',tone:'default'}
 if(now-at>10*60000)return {text:'结果已过期',tone:'warning'}
 return {text:probeLabel(probe),tone:probe.state==='succeeded'?'success':probe.state==='failed'?'error':'default'}
}
