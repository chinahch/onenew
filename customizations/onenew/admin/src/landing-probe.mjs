const root='/api/onenew/admin/exit-probes';
export function createExitProbe({post,nodeId,machineId,core,outbound}){
  const machine=Number(machineId),node=Number(nodeId);
  if(!Number.isSafeInteger(machine)||machine<=0){if(!Number.isSafeInteger(node)||node<=0)throw new Error('请先选择节点归属服务器')}
  if(!['singbox','xray','xray-caddy'].includes(core)||!outbound||typeof outbound!=='object')throw new Error('请先生成有效落地预览');
  return post(root,{...(Number.isSafeInteger(machine)&&machine>0?{machine_id:machine}:{}),...(Number.isSafeInteger(node)&&node>0?{node_id:node}:{}),core,outbound},{baseURL:'/'});
}
export function fetchExitProbe(get,id){
  if(!/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i.test(id))throw new Error('测试编号无效');
  return get(`${root}/${id}`,{baseURL:'/'});
}
const errors={expired:'测试已超时，请重新测试',transport_failed:'连接失败，无法确定入口/目标',proxy_failed:'无法经落地完成请求',target_failed:'出口识别服务响应无效',timeout:'落地请求超时',configuration_unavailable:'当前内核无法加载该配置',unsupported:'当前内核不支持此落地',machine_unavailable:'节点归属服务器已变更或不可用'};
export function LandingProbe({react:React,nodeId,machineId,core,outbound,post,get}){
  const h=React.createElement,[task,setTask]=React.useState(null),[busy,setBusy]=React.useState(false),[error,setError]=React.useState(''),generation=React.useRef(0);
  const draft=JSON.stringify({nodeId,machineId,core,outbound});
  React.useEffect(()=>{generation.current++;setTask(null);setBusy(false);setError('');return()=>{generation.current++}},[draft]);
  React.useEffect(()=>{
    if(!task||!['pending','running'].includes(task.state))return;
    let active=true;const token=generation.current;
    const timer=setTimeout(async()=>{
      if(Date.parse(task.expires_at)<=Date.now()){if(active)setTask({...task,state:'expired',error_code:'expired'});return}
      try{const value=await fetchExitProbe(get,task.id);if(active&&token===generation.current)setTask(value)}
      catch{if(active&&token===generation.current){setTask(null);setError('测试状态读取失败，请重新测试')}}
    },1500);
    return()=>{active=false;clearTimeout(timer)};
  },[task,get]);
  async function test(){
    const token=++generation.current;setBusy(true);setError('');setTask(null);
    try{const value=await createExitProbe({post,nodeId,machineId,core,outbound});if(token===generation.current)setTask(value)}
    catch{if(token===generation.current)setError('测试未创建，请检查服务器选择、节点版本或稍后重试')}
    finally{if(token===generation.current)setBusy(false)}
  }
  const waiting=busy||['pending','running'].includes(task?.state);
  let country=task?.country;
  if(country){try{country=`${new Intl.DisplayNames(['zh-CN'],{type:'region'}).of(country)} (${country})`}catch{}}
  return h('section',{className:'onenew-exit-probe','aria-label':'真实落地测试'},
    h('p',{className:'onenew-help'},'从所选节点归属服务器，经当前预览落地请求 Cloudflare；无需先保存草稿。'),
    h('button',{type:'button',className:'onenew-button',disabled:waiting||!outbound||(!nodeId&&!machineId),onClick:test},waiting?'正在测试落地…':'测试解析落地'),
    error&&h('p',{role:'alert',className:'onenew-error'},error),
    task&&h('p',{role:'status',className:task.state==='succeeded'?'onenew-notice':'onenew-help'},
      task.state==='succeeded'?`出口 IP：${task.exit_ip} · 国家/地区：${country}`:
      task.state==='pending'?'已排队，等待节点服务器执行':task.state==='running'?'节点服务器正在经落地发起真实请求':errors[task.error_code]||'测试失败，请重新测试'));
}
