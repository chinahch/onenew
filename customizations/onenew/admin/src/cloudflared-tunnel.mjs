const root='/api/onenew/admin/tunnels';
const live=new Set(['pending','leased','running']);
const actionLabels={install:'安装 / 更新 Token',restart:'重启服务',uninstall:'卸载清理'};
const stateLabels={pending:'等待服务器认领',leased:'已领取',running:'执行中',succeeded:'已完成',failed:'执行失败',cancelled:'已取消',expired:'已过期'};
const errorLabels={download_failed:'cloudflared 下载失败',install_failed:'cloudflared 服务安装失败',service_failed:'cloudflared 服务未能正常运行',uninstall_failed:'卸载失败',invalid_token:'Token 格式无效',invalid_task:'任务或服务器绑定已变化',unsupported:'当前系统服务管理器暂不支持（需 systemd）',expired:'任务已过期',internal_error:'助手执行异常'};

export function extractTunnelToken(raw){
  const text=String(raw||'').trim();
  const match=text.match(/(?:--token\s+|service\s+install\s+)?([A-Za-z0-9_-]{32,1024}={0,2})$/);
  return match?match[1]:'';
}

export function tunnelPayload(action,rawToken=''){
  if(!['install','restart','uninstall'].includes(action))throw new Error('无效隧道操作');
  if(action==='install'){
    const token=extractTunnelToken(rawToken);
    if(!token)throw new Error('请输入有效的 Cloudflare Tunnel Token（可粘贴完整 cloudflared service install 命令，将自动提取 Token）');
    return {action,token};
  }
  return {action};
}

export function CloudflaredTunnel({react:R,get,post,machineId,machineName}){
  const h=R.createElement;
  const [open,setOpen]=R.useState(false);
  const [data,setData]=R.useState(null);
  const [token,setToken]=R.useState('');
  const [busy,setBusy]=R.useState(false);
  const [error,setError]=R.useState('');
  const [notice,setNotice]=R.useState('');
  const dialog=R.useRef(null);

  const load=async(isCurrent=()=>true)=>{
    const res=await get(`${root}/${Number(machineId)}`,{baseURL:'/'});
    if(!isCurrent())return;
    setData(res?.data??res);
  };

  R.useEffect(()=>{
    if(!open)return;
    dialog.current?.showModal?.();
    return()=>{dialog.current?.close?.()};
  },[open]);

  R.useEffect(()=>{
    if(!open)return;
    let active=true,timer;
    const poll=async()=>{
      try{await load(()=>active)}catch{if(active)setError('无法读取隧道状态，请重试')}
      if(active)timer=setTimeout(poll,5000);
    };
    poll();
    return()=>{active=false;clearTimeout(timer)};
  },[open,machineId]);

  const submit=async action=>{
    setError('');setNotice('');
    let payload;
    try{payload=tunnelPayload(action,token)}catch(e){setError(e.message);return}
    setBusy(true);
    try{
      await post(`${root}/${Number(machineId)}`,payload,{baseURL:'/'});
      if(action==='install')setToken('');
      setNotice(`已下发「${actionLabels[action]}」任务，等待服务器助手执行。`);
      await load();
    }catch(e){
      setError(e?.response?.data?.message||'任务下发失败：请确认服务器已更新支持隧道功能的升级助手且当前无进行中任务');
    }finally{setBusy(false)}
  };

  const cancel=async id=>{
    setBusy(true);setError('');setNotice('');
    try{await post(`${root}/jobs/${id}/cancel`,{},{baseURL:'/'});setNotice('已取消等待中的任务');await load()}
    catch{setError('任务已开始或状态已变化，无法取消')}
    finally{setBusy(false)}
  };

  const stop=e=>{e?.stopPropagation?.()};
  const trigger=h('button',{type:'button',className:'onenew-resource-button',onClick:e=>{e?.stopPropagation?.();setOpen(true);setError('');setNotice('')}},'隧道');

  const job=data?.job||null;
  const activeJob=!!(job&&live.has(job.state));
  const title=machineName?`${machineName} (SID:${machineId})`:`服务器 #${machineId}`;
  const statusText=!data?'读取中…':!data.capable?'需更新助手':data.running?'运行中 (active)':data.installed?'已停止':'未安装';
  const statusTone=!data?'muted':!data.capable?'warn':data.running?'ok':data.installed?'err':'muted';

  return h(R.Fragment,null,trigger,
    open&&h('dialog',{ref:dialog,className:'onenew-tunnel-dialog',onCancel:e=>{if(busy){e?.preventDefault?.();return}setOpen(false)},onClose:()=>setOpen(false),onClick:stop,onMouseDown:stop,onPointerDown:stop,'aria-label':`服务器 #${machineId} Argo 隧道`},
      h('header',{className:'onenew-up-header'},
        h('div',{className:'onenew-up-title-group'},
          h('strong',{className:'onenew-up-title'},`Argo 隧道 · ${title}`),
          data&&h('span',{className:`onenew-up-pill onenew-up-pill-${statusTone}`},statusText)),
        h('button',{type:'button',className:'onenew-up-btn onenew-up-btn-ghost',disabled:busy,onClick:()=>setOpen(false)},'关闭')),
      h('div',{className:'onenew-up-body'},
        h('p',{className:'onenew-up-tip'},'通过服务器上的升级助手安全下发 cloudflared 连接器（仅校验并传递 Tunnel Token，不执行任意 Shell 命令）。支持无公网 SSH 端口的服务器。'),
        error&&h('p',{role:'alert',className:'onenew-up-alert'},error),
        notice&&h('p',{role:'status',className:'onenew-up-tip'},notice),
        !data?h('p',{className:'onenew-up-loading'},'正在读取隧道状态…'):h(R.Fragment,null,
          !data.capable&&h('p',{className:'onenew-up-alert'},'该服务器尚未上报隧道助手状态。请先在服务器执行最新升级助手刷新命令（--refresh-assistant）后即可远程下发。'),
          h('div',{className:'onenew-tunnel-cards'},
            h('div',{className:'onenew-tunnel-card'},
              h('span',{className:'onenew-tunnel-card-label'},'连接器状态'),
              h('span',{className:'onenew-tunnel-card-val'},statusText)),
            h('div',{className:'onenew-tunnel-card'},
              h('span',{className:'onenew-tunnel-card-label'},'版本 / 架构'),
              h('span',{className:'onenew-tunnel-card-val'},data.version?`${data.version} · ${data.arch||''}`:(data.arch||'—'))),
            h('div',{className:'onenew-tunnel-card'},
              h('span',{className:'onenew-tunnel-card-label'},'最近任务'),
              h('span',{className:'onenew-tunnel-card-val'},job?`${actionLabels[job.action]||job.action} · ${stateLabels[job.state]||job.state}`:'无'))),
          job?.error_code&&h('p',{role:'alert',className:'onenew-up-alert'},`最近错误：${errorLabels[job.error_code]||job.error_code}`),
          h('label',{className:'onenew-field'},
            h('span',null,'Cloudflare Tunnel Token（支持直接粘贴 cloudflared service install 命令）'),
            h('input',{type:'password',autoComplete:'off',spellCheck:false,'aria-label':'Cloudflare Tunnel Token',placeholder:'eyJhIjoi...',value:token,disabled:busy||!data.capable||activeJob,onChange:e=>setToken(e.target.value)})),
          h('div',{className:'onenew-tunnel-actions'},
            h('button',{type:'button',className:'onenew-up-btn onenew-up-btn-primary',disabled:busy||!data.capable||activeJob||!token.trim(),onClick:()=>submit('install')},busy?'正在下发…':'安装 / 更新 Token'),
            h('button',{type:'button',className:'onenew-up-btn',disabled:busy||!data.capable||activeJob||!data.installed,onClick:()=>submit('restart')},'重启服务'),
            h('button',{type:'button',className:'onenew-up-btn onenew-up-btn-danger',disabled:busy||!data.capable||activeJob||!data.installed,onClick:()=>submit('uninstall')},'卸载清理'),
            job?.state==='pending'&&h('button',{type:'button',className:'onenew-up-btn onenew-up-btn-ghost',disabled:busy,onClick:()=>cancel(job.id)},'取消等待'))))));
}
