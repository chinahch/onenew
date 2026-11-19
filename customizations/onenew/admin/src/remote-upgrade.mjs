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
  const button=(label,onClick,disabled=false)=>h('button',{type:'button',className:'rounded-md border px-3 py-1.5 text-sm disabled:opacity-50',onClick,disabled},label);
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
  if(!open)return button(machineId?'远程升级':'服务器升级',show);
  return h('section',{className:'w-full rounded-xl border bg-card p-4 space-y-3','aria-label':'服务器远程升级'},
    h('div',{className:'flex flex-wrap items-center justify-between gap-2'},h('strong',null,'服务器升级'),button('收起',()=>{setOpen(false);setConfirm(false)},busy)),
    h('p',{className:'text-sm text-muted-foreground'},'旧服务器需先运行一次服务器详情中的升级命令，安装独立升级助手。助手上线后可在此升级，无需再次输入 Token。离线服务器等待上线，等待任务 24 小时过期；全局最多同时升级 5 台，空出名额后自动处理下一台。'),
    error&&h('p',{role:'alert',className:'text-sm text-red-600'},error),
    !data?h('p',null,'正在读取升级状态…'):h(R.Fragment,null,
      h('label',{className:'flex items-center gap-2 text-sm'},'官方目标版本',h('select',{value:target,onChange:e=>{setTarget(e.target.value);setConfirm(false)},disabled:busy,className:'rounded border bg-background p-1'},(data.versions||[]).map(version=>h('option',{key:version,value:version},version)))),
      !target&&h('p',{className:'text-sm'},'暂无已验证的官方升级版本。'),
      h('div',{className:'flex flex-wrap items-center gap-3 text-sm'},
        h('span',{'aria-live':'polite'},`已选 ${chosen.length} 台 · 可升级 ${selectable.length} 台`),
        button('清空选择',()=>toggleAll(false),busy||chosen.length===0),
        selectable.length>100&&h('span',{className:'text-muted-foreground'},'每批最多 100 台；全选将选择列表中前 100 台可升级服务器。')),
      h('div',{className:'overflow-x-auto'},h('table',{className:'w-full text-left text-sm'},
        h('thead',null,h('tr',null,
          h('th',{className:'p-2'},h('label',{className:'inline-flex items-center gap-2'},
            h('input',{type:'checkbox','aria-label':'全选可升级服务器',checked:allSelected,
              'aria-checked':partiallySelected?'mixed':allSelected,
              ref:element=>{if(element)element.indeterminate=partiallySelected},
              disabled:busy||bulkRows.length===0,onChange:event=>toggleAll(event.target.checked)}),'全选')),
          ...['服务器','当前版本','目标版本','助手 / 升级状态','操作'].map(label=>h('th',{key:label,className:'p-2'},label)))),
        h('tbody',null,rows.map(row=>h('tr',{key:row.machine_id,className:'border-t'},
          h('td',{className:'p-2'},h('input',{type:'checkbox','aria-label':`选择服务器 ${row.machine_id}`,checked:selected.includes(row.machine_id),disabled:busy||(!selected.includes(row.machine_id)&&(!upgradeEligible(row,target)||chosen.length>=100)),onChange:e=>{setConfirm(false);setSelected(old=>e.target.checked?[...new Set([...old,row.machine_id])]:old.filter(id=>id!==row.machine_id))}})),
          h('td',{className:'p-2'},`${row.name} (#${row.machine_id})`),h('td',{className:'p-2'},row.version||'尚未上报'),
          h('td',{className:'p-2'},row.job?.target||target||'—'),
          h('td',{className:'p-2'},!row.capable?'需先安装助手':`${row.online?'助手在线':'助手离线'} · ${row.job?(states[row.job.state]||'未知状态'):(upgradeEligible(row,target)?'可升级':'已是当前或更新版本')}`,
            row.job?.reported_version&&h('div',null,`实际结果版本 ${row.job.reported_version}`),row.job?.error_code&&h('div',{className:'text-red-600'},errors[row.job.error_code]||'升级失败')),
          h('td',{className:'p-2'},row.job?.state==='pending'?button('取消等待',()=>cancel(row.job.id),busy):upgradeEligible(row,target)?button('升级此服务器',()=>{setSelected([row.machine_id]);setConfirm(true)},busy):null)))))),
      confirm?h('div',{className:'rounded border border-amber-500 p-3 space-y-2',role:'alertdialog','aria-label':'确认服务器升级'},
        h('p',null,`确认将 ${chosen.length} 台服务器升级到 ${target}？`),
        h('p',{className:'text-sm'},chosen.map(row=>`${row.name} (#${row.machine_id})`).join('、')),
        h('p',{className:'text-sm'},'升级会短暂中断节点连接。助手会保留配置和 Token，检查运行状态；健康检查失败时尝试回滚。'),
        button(busy?'正在创建…':'确认升级',run,busy||!eligible),button('返回',()=>setConfirm(false),busy)):
        button(`升级已选 ${chosen.length} 台`,()=>setConfirm(true),busy||!eligible)));
}
