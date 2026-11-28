const root='/api/onenew/admin/self-steal';
const live=new Set(['pending','leased','running']);
const actionLabels={install:'部署 / 更新伪装站',restart:'重启伪装站',uninstall:'卸载清理'};
const stateLabels={pending:'等待服务器认领',leased:'已领取',running:'执行中',succeeded:'已完成',failed:'执行失败',cancelled:'已取消',expired:'已过期'};
const errorLabels={download_failed:'Caddy 二进制下载失败',install_failed:'伪装站配置写入失败',service_failed:'伪装站服务未能正常启动（请检查端口冲突或 80 端口连通性）',uninstall_failed:'卸载失败',invalid_domain:'伪装域名格式无效',invalid_port:'本地回落端口无效（需为 1–65535 且不能为 80）',port_80_in_use:'本机 80 端口已被其他 Web 服务（如 OpenResty/Nginx）占用，无法自动签证书；请先在现有 Web 服务中为该域名配置 HTTPS 并开启 TLS 1.3 + HTTP/2，然后重试即可自动识别复用',cert_invalid:'检测到本机 443 已有现有网站，但该域名的 TLS 证书无效或不匹配，请在 OpenResty/Nginx 中检查证书后重试',missing_tls13:'检测到本机 443 已有现有网站，但未开启 TLS 1.3；请在 OpenResty/Nginx 中配置 ssl_protocols TLSv1.2 TLSv1.3; 后重试',missing_http2:'检测到本机 443 已有现有网站，但未开启 HTTP/2（当前仅 HTTP/1.1）；请在 OpenResty/Nginx 中开启 http2 后重试',invalid_task:'任务或服务器绑定已变化',unsupported:'当前系统服务管理器暂不支持（需 systemd）',expired:'任务已过期',internal_error:'助手执行异常'};
const domainRE=/^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?(\.[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)+$/;

export function validSelfStealDomain(raw){
  const d=String(raw||'').trim().toLowerCase();
  return d.length>=3&&d.length<=253&&domainRE.test(d)?d:'';
}

export function selfStealPayload(action,rawDomain='',rawPort=8443){
  if(!['install','restart','uninstall'].includes(action))throw new Error('无效伪装站操作');
  if(action==='install'){
    const domain=validSelfStealDomain(rawDomain);
    if(!domain)throw new Error('请输入有效的伪装域名（如 blog.example.com，需已解析到此服务器 IP）');
    const port=Number(rawPort||8443);
    if(!Number.isInteger(port)||port<1||port>65535||port===80)throw new Error('本地回落端口须在 1–65535 之间且不能为 80');
    return {action,domain,port};
  }
  return {action};
}

const progressMeta={pending:{pct:25,tone:'active',step:'1/3 等待服务器助手认领…'},leased:{pct:50,tone:'active',step:'2/3 助手已领取任务，准备执行…'},running:{pct:78,tone:'active',step:'3/3 正在服务器上执行中…'},succeeded:{pct:100,tone:'ok',step:'已完成'},failed:{pct:100,tone:'err',step:'执行失败'},expired:{pct:100,tone:'err',step:'任务已过期'},cancelled:{pct:100,tone:'muted',step:'已取消'}};

function renderJobProgress(h,job,nowSec){
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

let activeSiteMachineId=null;
const siteDrafts=new Map();

export function SelfStealSite({react:R,get,post,machineId,machineName,siteActive=false}){
  const h=R.createElement;
  const mid=Number(machineId);
  const savedDraft=siteDrafts.get(mid);
  const [open,setOpen]=R.useState(activeSiteMachineId===mid);
  const [data,setData]=R.useState(null);
  const [domain,setDomain]=R.useState(savedDraft?.domain??'');
  const [port,setPort]=R.useState(savedDraft?.port??'8443');
  const [touched,setTouched]=R.useState(!!savedDraft?.touched);
  const [busy,setBusy]=R.useState(false);
  const [error,setError]=R.useState('');
  const [notice,setNotice]=R.useState('');
  const [nowSec,setNowSec]=R.useState(Math.floor(Date.now()/1000));
  const dialog=R.useRef(null);
  const unmounting=R.useRef(false);

  const openModal=()=>{activeSiteMachineId=mid;setOpen(true);setError('');setNotice('')};
  const closeModal=()=>{if(activeSiteMachineId===mid)activeSiteMachineId=null;siteDrafts.delete(mid);setTouched(false);setOpen(false)};

  const updateDraft=(nextDomain,nextPort,nextTouched)=>{
    siteDrafts.set(mid,{domain:nextDomain,port:nextPort,touched:nextTouched});
  };

  const load=async(isCurrent=()=>true)=>{
    const res=await get(`${root}/${mid}`,{baseURL:'/'});
    if(!isCurrent())return null;
    const payload=res?.data??res;
    setData(payload);
    if(payload?.job&&!live.has(payload.job.state))setNotice('');
    if(!touched&&payload?.domain){
      setDomain(payload.domain);
      if(payload.port)setPort(String(payload.port));
    }
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
      }catch{if(active)setError('无法读取伪装站状态，请重试')}
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
    try{payload=selfStealPayload(action,domain,port)}catch(e){setError(e.message);return}
    setBusy(true);
    try{
      await post(`${root}/${Number(machineId)}`,payload,{baseURL:'/'});
      setNotice(`已下发「${actionLabels[action]}」任务，等待服务器助手执行。`);
      await load();
    }catch(e){
      setError(e?.response?.data?.message||'任务下发失败：请确认服务器已更新支持伪装站的升级助手且当前无进行中任务');
    }finally{setBusy(false)}
  };

  const cancel=async id=>{
    setBusy(true);setError('');setNotice('');
    try{await post(`${root}/jobs/${id}/cancel`,{},{baseURL:'/'});setNotice('已取消等待中的任务');await load()}
    catch{setError('任务已开始或状态已变化，无法取消')}
    finally{setBusy(false)}
  };

  const stop=e=>{e?.stopPropagation?.()};
  const hasSite=data?!!(data.installed||data.running):!!siteActive;
  const trigger=h('button',{type:'button',className:hasSite?'onenew-resource-button onenew-tunnel-active':'onenew-resource-button',onClick:e=>{e?.stopPropagation?.();openModal()}},'伪装站');

  const title=machineName?`${machineName} (SID:${machineId})`:`服务器 #${machineId}`;
  const isExisting=data?.version==='existing-h2-tls13';
  const statusText=!data?'读取中…':!data.capable?'需更新助手':data.running?(isExisting?'复用本机站点 · H2/TLS1.3':'运行中 · H2/TLS1.3'):data.installed?(isExisting?'本机站点检测未通过':'已停止'):'未部署';
  const statusTone=!data?'muted':!data.capable?'warn':data.running?'ok':data.installed?'err':'muted';

  return h(R.Fragment,null,trigger,
    open&&h('dialog',{ref:dialog,className:'onenew-tunnel-dialog',onCancel:e=>{if(busy){e?.preventDefault?.();return}closeModal()},onClose:()=>{if(!unmounting.current)closeModal()},onClick:stop,onMouseDown:stop,onPointerDown:stop,'aria-label':`服务器 #${machineId} 偷自己伪装站`},
      h('header',{className:'onenew-up-header'},
        h('div',{className:'onenew-up-title-group'},
          h('strong',{className:'onenew-up-title'},`偷自己伪装站 · ${title}`),
          data&&h('span',{className:`onenew-up-pill onenew-up-pill-${statusTone}`},statusText)),
        h('button',{type:'button',className:'onenew-up-btn onenew-up-btn-ghost',disabled:busy,onClick:closeModal},'关闭')),
      h('div',{className:'onenew-up-body'},
        h('p',{className:'onenew-up-tip'},'通过升级助手自动在目标机部署独立的 Caddy 伪装站（onenew-disguise），自动签发并续期 TLS 1.3 + HTTP/2 证书。默认回落端口 8443 仅监听 127.0.0.1（不暴露公网端口），80 端口用于自动签证书。'),
        error&&h('p',{role:'alert',className:'onenew-up-alert'},error),
        notice&&!job&&h('p',{role:'status',className:'onenew-up-tip'},notice),
        !data?h('p',{className:'onenew-up-loading'},'正在读取伪装站状态…'):h(R.Fragment,null,
          !data.capable&&h('p',{className:'onenew-up-alert'},'该服务器尚未上报伪装站助手状态。请先在「远程升级」或服务器终端更新升级助手后即可一键部署。'),
          h('div',{className:'onenew-tunnel-cards'},
            h('div',{className:'onenew-tunnel-card'},
              h('span',{className:'onenew-tunnel-card-label'},'伪装站状态'),
              h('span',{className:'onenew-tunnel-card-val'},statusText)),
            h('div',{className:'onenew-tunnel-card'},
              h('span',{className:'onenew-tunnel-card-label'},'本机回落地址'),
              h('span',{className:'onenew-tunnel-card-val'},data.installed&&data.port?`127.0.0.1:${data.port}`:'—')),
            h('div',{className:'onenew-tunnel-card'},
              h('span',{className:'onenew-tunnel-card-label'},'已绑定域名'),
              h('span',{className:'onenew-tunnel-card-val'},data.domain||'—'))),
          renderJobProgress(h,job,nowSec),
          h('div',{className:'onenew-row'},
            h('label',{className:'onenew-field'},
              h('span',null,'伪装域名（需已解析到此服务器 IP，关闭小黄云）'),
              h('input',{type:'text',autoComplete:'off',spellCheck:false,placeholder:'blog.example.com',value:domain,disabled:busy||!data.capable||activeJob,onChange:e=>{setTouched(true);setDomain(e.target.value);updateDraft(e.target.value,port,true)}})),
            h('label',{className:'onenew-field'},
              h('span',null,'本地回落端口（默认 8443）'),
              h('input',{type:'number',min:1,max:65535,value:port,disabled:busy||!data.capable||activeJob,onChange:e=>{setTouched(true);setPort(e.target.value);updateDraft(domain,e.target.value,true)}}))),
          h('div',{className:'onenew-tunnel-actions'},
            h('button',{type:'button',className:'onenew-up-btn onenew-up-btn-primary',disabled:busy||!data.capable||activeJob||!domain.trim(),onClick:()=>submit('install')},busy?'正在下发…':'部署 / 更新伪装站'),
            h('button',{type:'button',className:'onenew-up-btn',disabled:busy||!data.capable||activeJob||!data.installed,onClick:()=>submit('restart')},'重启'),
            h('button',{type:'button',className:'onenew-up-btn onenew-up-btn-danger',disabled:busy||!data.capable||activeJob||!data.installed,onClick:()=>submit('uninstall')},'卸载清理'),
            job?.state==='pending'&&h('button',{type:'button',className:'onenew-up-btn onenew-up-btn-ghost',disabled:busy,onClick:()=>cancel(job.id)},'取消等待'))))));
}

export function RealitySelfSteal({react:R,form,get}){
  const h=R.createElement;
  const tls=Number(form?.watch?.('tls')||0);
  const selfSteal=!!form?.watch?.('reality_settings.self_steal');
  const sni=String(form?.watch?.('reality_settings.server_name')||'').trim();
  const port=Number(form?.watch?.('reality_settings.server_port')||443)||443;
  const machineId=Number(form?.watch?.('machine_id')||0);
  const [sites,setSites]=R.useState([]);

  R.useEffect(()=>{
    if(tls!==2||!get)return;
    let active=true;
    get(root,{baseURL:'/'}).then(res=>{
      if(!active)return;
      const list=Array.isArray(res?.data)?res.data:Array.isArray(res)?res:[];
      setSites(list);
    }).catch(()=>{});
    return()=>{active=false};
  },[tls,get]);

  if(tls!==2)return null;

  const matched=machineId>0?sites.find(s=>Number(s.machine_id)===machineId):null;

  const applySite=site=>{
    form.setValue('reality_settings.server_name',site.domain,{shouldDirty:true,shouldValidate:true});
    form.setValue('reality_settings.server_port',Number(site.port||8443),{shouldDirty:true,shouldValidate:true});
    form.setValue('reality_settings.self_steal',true,{shouldDirty:true,shouldValidate:true});
  };

  return h('div',{className:'onenew-self-steal-box'},
    h('div',{className:'onenew-self-steal-head'},
      h('label',{className:'onenew-check',style:{cursor:'pointer'}},
        h('input',{type:'checkbox',checked:selfSteal,onChange:e=>form.setValue('reality_settings.self_steal',!!e.target.checked,{shouldDirty:true,shouldValidate:true})}),
        h('span',{style:{fontWeight:600}},'偷自己（本机回落 127.0.0.1）')),
      matched&&h('button',{type:'button',className:'onenew-up-btn onenew-tunnel-active',onClick:()=>applySite(matched)},`⚡ 填入本机伪装站 (${matched.domain}:${matched.port})`)),
    h('p',{className:'onenew-help',style:{margin:0}},'开启后节点端回落自动连接 127.0.0.1:端口（无需改 /etc/hosts，兼容本机 OpenResty / Nginx / 一键伪装站），客户端 SNI 仍保持上方域名。'),
    selfSteal&&h('div',{className:'onenew-self-steal-preview'},
      h('div',null,'客户端 SNI：',h('strong',null,sni||'（请先填写伪装站点域名）')),
      h('div',null,'节点端回落：',h('strong',null,`127.0.0.1:${port}`))));
}
