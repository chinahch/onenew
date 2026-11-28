const root='/api/onenew/admin/upgrades';
const live=new Set(['pending','leased','downloading','verifying','installing','checking']);
const states={pending:'等待服务器 / 排队',leased:'已领取',downloading:'下载中',verifying:'校验中',installing:'安装中',checking:'健康检查',succeeded:'升级成功',rolled_back:'已回滚',failed:'升级失败',expired:'已过期',cancelled:'已取消'};
const errors={download_failed:'官方下载失败',verification_failed:'校验失败',install_failed:'安装失败',health_failed:'健康检查失败',rollback_failed:'回滚失败，请检查服务器',interrupted:'升级被中断',journal_failed:'升级记录写入失败，请检查磁盘空间及权限',invalid_task:'任务或绑定已变化',expired:'任务已过期',unsupported:'运行环境不支持',internal_error:'升级助手错误'};
const parseVersion=version=>/^v\d+\.\d+\.\d+$/.test(version||'')?version.slice(1).split('.').map(Number):null;
function compareVersions(a,b){
  for(let i=0;i<3;i++)if(a[i]!==b[i])return a[i]-b[i];
  return 0;
}
function latestVersion(versions){
  return versions.filter(version=>parseVersion(version)).reduce((latest,version)=>
    !latest||compareVersions(parseVersion(version),parseVersion(latest))>0?version:latest,'');
}
export function upgradeEligible(machine,target){
  if(!machine?.is_active||!machine?.capable||!target||live.has(machine.job?.state))return false;
  const current=parseVersion(machine.version),next=parseVersion(target);
  return !!current&&!!next&&compareVersions(current,next)<0;
}
export function upgradePayload(ids,version){
  const machine_ids=[...new Set(ids.map(Number))];
  if(!machine_ids.length||machine_ids.length>100||machine_ids.some(id=>!Number.isSafeInteger(id)||id<1)||!/^v\d+\.\d+\.\d+$/.test(version))throw new Error('请选择 1–100 台服务器及官方版本');
  return {machine_ids,version,confirmed:true};
}
export function RemoteUpgrade({react:R,get,post,table,machineId}){
  const h=R.createElement,[open,setOpen]=R.useState(false),[data,setData]=R.useState(null),[selected,setSelected]=R.useState([]),[target,setTarget]=R.useState(''),[confirm,setConfirm]=R.useState(false),[busy,setBusy]=R.useState(false),[error,setError]=R.useState('');
  const button=(label,onClick,disabled=false,variant='default')=>h('button',{type:'button',className:`onenew-up-btn onenew-up-btn-${variant}`,onClick,disabled},label);
  const load=async(isCurrent=()=>true)=>{
    const result=await get(root,{baseURL:'/'});
    if(!isCurrent())return;
    const next=result?.data??result,versions=next.versions??[];
    setData(next);
    setTarget(old=>versions.includes(old)?old:latestVersion(versions));
  };
  R.useEffect(()=>{
    if(!open)return;
    let active=true,timer;
    const update=async()=>{
      try{await load(()=>active)}catch{if(active)setError('无法读取升级状态，请重试')}
      if(active)timer=setTimeout(update,5000);
    };
    update();
    return()=>{active=false;clearTimeout(timer)};
  },[open]);
  const show=()=>{setOpen(true);setConfirm(false);setError('');setSelected(machineId?[Number(machineId)]:(table?.getSelectedRowModel?.().rows||[]).map(row=>Number(row.original.id)))};
  const rows=(data?.machines||[]).filter(row=>!machineId||Number(row.machine_id)===Number(machineId));
  const chosen=rows.filter(row=>selected.includes(row.machine_id));
  const selectable=rows.filter(row=>upgradeEligible(row,target));
  const bulkRows=selectable.slice(0,100);
  const allSelected=bulkRows.length>0&&bulkRows.every(row=>selected.includes(row.machine_id));
  const partiallySelected=!allSelected&&chosen.length>0;
  const toggleAll=checked=>{setConfirm(false);setSelected(checked?bulkRows.map(row=>row.machine_id):[])};
  const eligible=chosen.length>0&&chosen.length<=100&&chosen.every(row=>upgradeEligible(row,target));
  const run=async()=>{setBusy(true);setError('');try{await post(root,upgradePayload(chosen.map(row=>row.machine_id),target),{baseURL:'/'});setConfirm(false);setSelected([]);await load()}catch{setError('创建失败：请刷新并确认服务器已有助手、版本可升级且没有进行中的任务')}finally{setBusy(false)}};
  const cancel=async id=>{setBusy(true);try{await post(`${root}/${id}/cancel`,{}, {baseURL:'/'});await load()}catch{setError('任务已开始或状态已变化，无法取消')}finally{setBusy(false)}};
  if(!open)return h('button',{type:'button',className:machineId?'onenew-resource-button':'onenew-up-trigger',onClick:show},machineId?'远程升级':'服务器升级');
  const onlineCount=rows.filter(r=>r.capable&&r.online).length;
  const statusBadge=row=>{
    if(!row.capable)return h('span',{className:'onenew-up-pill onenew-up-pill-warn'},'需先安装助手');
    const jobState=row.job?.state;
    const tone=jobState==='succeeded'?'ok':jobState==='failed'||jobState==='rolled_back'||jobState==='expired'?'err':live.has(jobState)?'info':upgradeEligible(row,target)?'info':'muted';
    const label=row.job?(states[jobState]||'未知状态'):(upgradeEligible(row,target)?'可升级':'已是当前或更新版本');
    return h('div',{className:'onenew-up-status-cell'},
      h('div',{className:'onenew-up-status-row'},
        h('span',{className:`onenew-up-dot ${row.online?'onenew-up-dot-on':'onenew-up-dot-off'}`}),
        h('span',{className:'onenew-up-agent'},row.online?'助手在线':'助手离线'),
        h('span',{className:`onenew-up-pill onenew-up-pill-${tone}`},label)),
      row.job?.reported_version&&h('div',{className:'onenew-up-sub'},`实际结果版本 ${row.job.reported_version}`),
      row.job?.error_code&&h('div',{className:'onenew-up-err'},errors[row.job.error_code]||'升级失败'));
  };
  return h('div',{className:'onenew-up-overlay',onClick:e=>{if(e.target===e.currentTarget&&!busy){setOpen(false);setConfirm(false)}}},
    h('section',{className:'onenew-up-modal','aria-label':'服务器远程升级'},
      h('header',{className:'onenew-up-header'},
        h('div',{className:'onenew-up-title-group'},
          h('strong',{className:'onenew-up-title'},'服务器升级'),
          data&&h('span',{className:'onenew-up-meta'},`在线助手 ${onlineCount}/${rows.length} · 可升级 ${selectable.length} 台`)),
        button('收起',()=>{setOpen(false);setConfirm(false)},busy,'ghost')),
      h('div',{className:'onenew-up-body'},
        h('p',{className:'onenew-up-tip'},'旧服务器需先运行一次服务器详情中的升级命令，安装独立升级助手。助手上线后可在此升级，无需再次输入 Token。离线服务器等待上线，等待任务 24 小时过期；全局最多同时升级 5 台，空出名额后自动处理下一台。'),
        error&&h('p',{role:'alert',className:'onenew-up-alert'},error),
        !data?h('p',{className:'onenew-up-loading'},'正在读取升级状态…'):h(R.Fragment,null,
          h('div',{className:'onenew-up-toolbar'},
            h('label',{className:'onenew-up-version-label'},'官方目标版本',
              h('select',{value:target,onChange:e=>{setTarget(e.target.value);setConfirm(false)},disabled:busy,className:'onenew-up-select'},(data.versions||[]).map(version=>h('option',{key:version,value:version},version)))),
            h('div',{className:'onenew-up-toolbar-right'},
              h('span',{className:'onenew-up-count','aria-live':'polite'},`已选 ${chosen.length} 台 · 可升级 ${selectable.length} 台`),
              button('清空选择',()=>toggleAll(false),busy||chosen.length===0,'ghost'),
              !confirm&&button(`升级已选 ${chosen.length} 台`,()=>setConfirm(true),busy||!eligible,'primary'))),
          !target&&h('p',{className:'onenew-up-tip'},'暂无已验证的官方升级版本。'),
          selectable.length>100&&h('p',{className:'onenew-up-tip'},'每批最多 100 台；全选将选择列表中前 100 台可升级服务器。'),
          confirm&&h('div',{className:'onenew-up-confirm',role:'alertdialog','aria-label':'确认服务器升级'},
            h('div',{className:'onenew-up-confirm-title'},`确认将 ${chosen.length} 台服务器升级到 ${target}？`),
            h('p',{className:'onenew-up-confirm-list'},chosen.map(row=>`${row.name} (#${row.machine_id})`).join('、')),
            h('p',{className:'onenew-up-confirm-note'},'升级会短暂中断节点连接。助手会保留配置和 Token，检查运行状态；健康检查失败时尝试回滚。'),
            h('div',{className:'onenew-up-confirm-actions'},
              button(busy?'正在创建…':'确认升级',run,busy||!eligible,'primary'),
              button('返回',()=>setConfirm(false),busy,'ghost'))),
          h('div',{className:'onenew-up-table-wrap'},
            h('table',{className:'onenew-up-table'},
              h('thead',null,h('tr',null,
                h('th',{className:'onenew-up-col-check'},h('label',{className:'onenew-up-check-all'},
                  h('input',{type:'checkbox','aria-label':'全选可升级服务器',checked:allSelected,
                    'aria-checked':partiallySelected?'mixed':allSelected,
                    ref:element=>{if(element)element.indeterminate=partiallySelected},
                    disabled:busy||bulkRows.length===0,onChange:event=>toggleAll(event.target.checked)}),'全选')),
                ...['服务器','当前版本','目标版本','助手 / 升级状态','操作'].map(label=>h('th',{key:label},label)))),
              h('tbody',null,rows.map(row=>h('tr',{key:row.machine_id,className:selected.includes(row.machine_id)?'onenew-up-row-selected':''},
                h('td',{className:'onenew-up-col-check'},h('input',{type:'checkbox','aria-label':`选择服务器 ${row.machine_id}`,checked:selected.includes(row.machine_id),disabled:busy||(!selected.includes(row.machine_id)&&(!upgradeEligible(row,target)||chosen.length>=100)),onChange:e=>{setConfirm(false);setSelected(old=>e.target.checked?[...new Set([...old,row.machine_id])]:old.filter(id=>id!==row.machine_id))}})),
                h('td',{className:'onenew-up-col-name'},h('span',{className:'onenew-up-name'},row.name),h('span',{className:'onenew-up-sid'},`#${row.machine_id}`)),
                h('td',null,h('span',{className:`onenew-up-ver ${row.version?'':'onenew-up-ver-empty'}`},row.version||'尚未上报')),
                h('td',null,h('span',{className:'onenew-up-ver'},row.job?.target||target||'—')),
                h('td',null,statusBadge(row)),
                h('td',{className:'onenew-up-col-act'},row.job?.state==='pending'?button('取消等待',()=>cancel(row.job.id),busy,'danger'):upgradeEligible(row,target)?button('升级此服务器',()=>{setSelected([row.machine_id]);setConfirm(true)},busy,'default'):h('span',{className:'onenew-up-dash'},'—')))))))))));
}
