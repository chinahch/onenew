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

const progressMeta={pending:{pct:25,tone:'active',step:'1/3 等待服务器助手认领…'},leased:{pct:50,tone:'active',step:'2/3 助手已领取任务，准备执行…'},running:{pct:78,tone:'active',step:'3/3 正在服务器上执行中…'},succeeded:{pct:100,tone:'ok',step:'已完成'},failed:{pct:100,tone:'err',step:'执行失败'},expired:{pct:100,tone:'err',step:'任务已过期'},cancelled:{pct:100,tone:'muted',step:'已取消'}};

function renderTunnelProgress(h,job,nowSec){
  if(!job)return null;
  const meta=progressMeta[job.state]||{pct:100,tone:'muted',step:stateLabels[job.state]||job.state};
  const active=live.has(job.state);
  const elapsed=active&&job.created_at?Math.max(0,nowSec-Number(job.created_at)):null;
  return h('div',{className:`onenew-job-box onenew-job-${meta.tone}`},
    h('div',{className:'onenew-job-head'},
      h('span',{className:'onenew-job-title'},`${actionLabels[job.action]||job.action} · ${meta.step}`),
      h('span',{className:'onenew-job-pct'},elapsed!==null?`${meta.pct}% · ${elapsed}s`:`${meta.pct}%`)),
    h('div',{className:'onenew-job-track',role:'progressbar','aria-valuenow':meta.pct,'aria-valuemin':0,'aria-valuemax':100},
      h('div',{className:`onenew-job-fill onenew-job-fill-${meta.tone}`,style:{width:`${meta.pct}%`}})),
    job.error_code&&h('p',{role:'alert',className:'onenew-job-err-msg'},errorLabels[job.error_code]||job.error_code));
}

let activeTunnelMachineId=null;
const tunnelDrafts=new Map();

export function CloudflaredTunnel({react:R,get,post,machineId,machineName,tunnelActive=false}){
  const h=R.createElement;
  const mid=Number(machineId);
  const [open,setOpen]=R.useState(activeTunnelMachineId===mid);
  const [data,setData]=R.useState(null);
  const [token,setToken]=R.useState(tunnelDrafts.get(mid)??'');
  const [busy,setBusy]=R.useState(false);
  const [error,setError]=R.useState('');
  const [notice,setNotice]=R.useState('');
  const [nowSec,setNowSec]=R.useState(Math.floor(Date.now()/1000));
  const dialog=R.useRef(null);
  const unmounting=R.useRef(false);

  const openModal=()=>{activeTunnelMachineId=mid;setOpen(true);setError('');setNotice('')};
  const closeModal=()=>{if(activeTunnelMachineId===mid)activeTunnelMachineId=null;tunnelDrafts.delete(mid);setOpen(false)};

  const load=async(isCurrent=()=>true)=>{
    const res=await get(`${root}/${mid}`,{baseURL:'/'});
    if(!isCurrent())return null;
    const payload=res?.data??res;
    setData(payload);
    if(payload?.job&&!live.has(payload.job.state))setNotice('');
    return payload;
  };

  R.useEffect(()=>{
    if(!open)return;
    unmounting.current=false;
    if(dialog.current&&!dialog.current.open)dialog.current.showModal?.();
    return()=>{unmounting.current=true;dialog.current?.close?.()};
  },[open]);

  R.useEffect(()=>{
    if(!open)return;
    let active=true,timer;
    const poll=async()=>{
      let nextDelay=5000;
      try{
        const payload=await load(()=>active);
        if(payload?.job&&live.has(payload.job.state))nextDelay=2000;
      }catch{if(active)setError('无法读取隧道状态，请重试')}
      if(active)timer=setTimeout(poll,nextDelay);
    };
    poll();
    return()=>{active=false;clearTimeout(timer)};
  },[open,machineId]);

  const job=data?.job||null;
  const activeJob=!!(job&&live.has(job.state));

  R.useEffect(()=>{
    if(!open||!activeJob)return;
    setNowSec(Math.floor(Date.now()/1000));
    const iv=setInterval(()=>setNowSec(Math.floor(Date.now()/1000)),1000);
    return()=>clearInterval(iv);
  },[open,activeJob]);

  const submit=async action=>{
    setError('');setNotice('');
    let payload;
    try{payload=tunnelPayload(action,token)}catch(e){setError(e.message);return}
    setBusy(true);
    try{
      await post(`${root}/${Number(machineId)}`,payload,{baseURL:'/'});
      if(action==='install'){setToken('');tunnelDrafts.delete(mid)}
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
  const hasTunnel=data?!!(data.installed||data.running):!!tunnelActive;
  const trigger=h('button',{type:'button',className:hasTunnel?'onenew-resource-button onenew-tunnel-active':'onenew-resource-button',onClick:e=>{e?.stopPropagation?.();openModal()}},'隧道');

  const title=machineName?`${machineName} (SID:${machineId})`:`服务器 #${machineId}`;
  const statusText=!data?'读取中…':!data.capable?'需更新助手':data.running?'运行中 (active)':data.installed?'已停止':'未安装';
  const statusTone=!data?'muted':!data.capable?'warn':data.running?'ok':data.installed?'err':'muted';

  return h(R.Fragment,null,trigger,
    open&&h('dialog',{ref:dialog,className:'onenew-tunnel-dialog',onCancel:e=>{if(busy){e?.preventDefault?.();return}closeModal()},onClose:()=>{if(!unmounting.current)closeModal()},onClick:stop,onMouseDown:stop,onPointerDown:stop,'aria-label':`服务器 #${machineId} Argo 隧道`},
      h('header',{className:'onenew-up-header'},
        h('div',{className:'onenew-up-title-group'},
          h('strong',{className:'onenew-up-title'},`Argo 隧道 · ${title}`),
          data&&h('span',{className:`onenew-up-pill onenew-up-pill-${statusTone}`},statusText)),
        h('button',{type:'button',className:'onenew-up-btn onenew-up-btn-ghost',disabled:busy,onClick:closeModal},'关闭')),
      h('div',{className:'onenew-up-body'},
        h('p',{className:'onenew-up-tip'},'通过服务器上的升级助手安全下发 cloudflared 连接器（仅校验并传递 Tunnel Token，不执行任意 Shell 命令）。支持无公网 SSH 端口的服务器。'),
        error&&h('p',{role:'alert',className:'onenew-up-alert'},error),
        notice&&!job&&h('p',{role:'status',className:'onenew-up-tip'},notice),
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
          renderTunnelProgress(h,job,nowSec),
          h('label',{className:'onenew-field'},
            h('span',null,'Cloudflare Tunnel Token（支持直接粘贴 cloudflared service install 命令）'),
            h('input',{type:'password',autoComplete:'off',spellCheck:false,'aria-label':'Cloudflare Tunnel Token',placeholder:'eyJhIjoi...',value:token,disabled:busy||!data.capable||activeJob,onChange:e=>{setToken(e.target.value);tunnelDrafts.set(mid,e.target.value)}})),
          h('div',{className:'onenew-tunnel-actions'},
            h('button',{type:'button',className:'onenew-up-btn onenew-up-btn-primary',disabled:busy||!data.capable||activeJob||!token.trim(),onClick:()=>submit('install')},busy?'正在下发…':'安装 / 更新 Token'),
            h('button',{type:'button',className:'onenew-up-btn',disabled:busy||!data.capable||activeJob||!data.installed,onClick:()=>submit('restart')},'重启服务'),
            h('button',{type:'button',className:'onenew-up-btn onenew-up-btn-danger',disabled:busy||!data.capable||activeJob||!data.installed,onClick:()=>submit('uninstall')},'卸载清理'),
            job?.state==='pending'&&h('button',{type:'button',className:'onenew-up-btn onenew-up-btn-ghost',disabled:busy,onClick:()=>cancel(job.id)},'取消等待'))))));
}
