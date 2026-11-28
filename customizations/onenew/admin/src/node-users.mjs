const endpoint='/api/onenew/admin/traffic/node-users';

export function formatNodeBytes(value){
  const n=Number(value);
  if(!Number.isFinite(n)||n<0)return '—';
  if(n===0)return '0 B';
  const units=['B','KB','MB','GB','TB'];
  let idx=0,v=n;
  while(v>=1024&&idx<units.length-1){v/=1024;idx++}
  return `${idx===0?Math.round(v):v.toFixed(2)} ${units[idx]}`;
}

export function shiftIsoDate(isoDate,days){
  if(!/^\d{4}-\d{2}-\d{2}$/.test(String(isoDate||'')))return '';
  const [y,m,d]=isoDate.split('-').map(Number);
  const dt=new Date(Date.UTC(y,m-1,d+days));
  const pad=n=>String(n).padStart(2,'0');
  return `${dt.getUTCFullYear()}-${pad(dt.getUTCMonth()+1)}-${pad(dt.getUTCDate())}`;
}

export function fetchNodeUsers({get,serverId,date='',page=1,perPage=50}){
  const id=Number(serverId);
  if(!Number.isSafeInteger(id)||id<=0)throw new Error('节点 ID 无效');
  const params={server_id:id,page:Math.max(1,Number(page)||1),per_page:Math.min(100,Math.max(1,Number(perPage)||50))};
  if(date&&/^\d{4}-\d{2}-\d{2}$/.test(date))params.date=date;
  return get(endpoint,{baseURL:'/',params});
}

export function NodeUsersTrigger({react:R,get,node}){
  const h=R.createElement;
  const [open,setOpen]=R.useState(false);
  const [tab,setTab]=R.useState('online');
  const [date,setDate]=R.useState('');
  const [page,setPage]=R.useState(1);
  const [data,setData]=R.useState(null);
  const [busy,setBusy]=R.useState(false);
  const [error,setError]=R.useState('');
  const [tick,setTick]=R.useState(0);
  const dialog=R.useRef(null);

  const serverId=Number(node?.id||0);
  const reportedOnline=Number(node?.online||0);

  R.useEffect(()=>{
    if(!open)return;
    dialog.current?.showModal?.();
    return()=>{dialog.current?.close?.()};
  },[open]);

  R.useEffect(()=>{
    if(!open||!serverId)return;
    let active=true;
    setBusy(true);setError('');
    fetchNodeUsers({get,serverId,date,page})
      .then(res=>{
        if(!active)return;
        const payload=res?.data&&res?.node===undefined&&res?.online_users===undefined?res.data:res;
        setData(payload);
        if(!date&&payload?.date)setDate(payload.date);
      })
      .catch(e=>{if(active)setError(e?.response?.data?.message||e?.message||'无法读取节点使用用户，请重试')})
      .finally(()=>{if(active)setBusy(false)});
    return()=>{active=false};
  },[open,serverId,date,page,tick,get]);

  const stop=e=>{e?.stopPropagation?.()};
  const onlineUsers=Array.isArray(data?.online_users)?data.online_users:[];
  const onlineCount=data?onlineUsers.length:reportedOnline;
  const daily=data?.daily||null;
  const dailyRows=Array.isArray(daily?.data)?daily.data:[];
  const summary=daily?.summary||{active_users:0,u:0,d:0,total:0};
  const totalPages=Math.max(1,Math.ceil(Number(daily?.total||0)/Number(daily?.per_page||50)));
  const todayStr=data?.today||'';
  const yesterdayStr=todayStr?shiftIsoDate(todayStr,-1):'';
  const activeDate=date||data?.date||todayStr;

  const userIcon=h('svg',{viewBox:'0 0 24 24',fill:'none',stroke:'currentColor',strokeWidth:2,strokeLinecap:'round',strokeLinejoin:'round',className:'onenew-node-users-icon','aria-hidden':'true'},
    h('path',{d:'M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2'}),
    h('circle',{cx:12,cy:7,r:4}));

  const trigger=h('button',{
    type:'button',
    className:reportedOnline>0?'onenew-node-users-btn onenew-node-users-btn-active':'onenew-node-users-btn',
    title:'点击查看当前在线用户与按日使用明细',
    'aria-label':`查看节点 #${serverId} 使用用户（当前在线 ${reportedOnline} 人）`,
    onClick:e=>{e?.stopPropagation?.();setOpen(true);setError('')}
  },userIcon,h('span',{className:'onenew-node-users-num'},String(reportedOnline)));

  return h(R.Fragment,null,trigger,
    open&&h('dialog',{
      ref:dialog,
      className:'onenew-node-users-dialog',
      onCancel:()=>setOpen(false),
      onClose:()=>setOpen(false),
      onClick:stop,onMouseDown:stop,onPointerDown:stop,
      'aria-label':`节点 #${serverId} 使用用户`
    },
      h('header',{className:'onenew-up-header'},
        h('div',{className:'onenew-up-title-group'},
          h('strong',{className:'onenew-up-title'},node?.name||`节点 #${serverId}`),
          h('span',{className:'onenew-up-meta'},`#${serverId}`),
          h('span',{className:`onenew-up-pill ${onlineCount>0?'onenew-up-pill-ok':'onenew-up-pill-muted'}`},`在线 ${onlineCount} 人`)),
        h('div',{className:'onenew-tunnel-actions'},
          h('button',{type:'button',className:'onenew-up-btn',disabled:busy,onClick:()=>setTick(t=>t+1)},busy?'刷新中…':'刷新'),
          h('button',{type:'button',className:'onenew-up-btn onenew-up-btn-ghost',onClick:()=>setOpen(false)},'关闭'))),
      h('div',{className:'onenew-node-users-bar'},
        h('div',{role:'tablist',className:'onenew-node-users-tabs'},
          h('button',{type:'button',role:'tab','aria-selected':tab==='online',className:`onenew-node-users-tab ${tab==='online'?'is-active':''}`,onClick:()=>setTab('online')},`实时在线 (${onlineCount})`),
          h('button',{type:'button',role:'tab','aria-selected':tab==='daily',className:`onenew-node-users-tab ${tab==='daily'?'is-active':''}`,onClick:()=>setTab('daily')},'按日使用明细')),
        tab==='daily'&&h('div',{className:'onenew-node-users-dates'},
          todayStr&&h('button',{type:'button',className:`onenew-up-btn ${activeDate===todayStr?'onenew-up-btn-primary':''}`,onClick:()=>{setDate(todayStr);setPage(1)}},'今天'),
          yesterdayStr&&h('button',{type:'button',className:`onenew-up-btn ${activeDate===yesterdayStr?'onenew-up-btn-primary':''}`,onClick:()=>{setDate(yesterdayStr);setPage(1)}},'昨天'),
          h('input',{type:'date',className:'onenew-up-select',value:activeDate,max:todayStr||undefined,'aria-label':'选择日期',onChange:e=>{if(e.target.value){setDate(e.target.value);setPage(1)}}}))),
      h('div',{className:'onenew-up-body'},
        error&&h('p',{role:'alert',className:'onenew-up-alert'},error),
        busy&&!data&&h('p',{className:'onenew-up-loading'},'正在加载节点使用数据…'),
        data&&tab==='online'&&h(R.Fragment,null,
          onlineUsers.length?h('div',{className:'onenew-up-table-wrap'},
            h('table',{className:'onenew-up-table'},
              h('thead',null,h('tr',null,
                h('th',null,'用户'),
                h('th',null,'在线 IP'),
                h('th',{className:'onenew-up-col-act'},'设备数'),
                h('th',{className:'onenew-up-col-act'},'今日节点流量'))),
              h('tbody',null,...onlineUsers.map(u=>h('tr',{key:u.user_id},
                h('td',null,
                  h('div',{className:'onenew-up-name'},u.email),
                  h('div',{className:'onenew-up-sub'},`UID #${u.user_id}`)),
                h('td',null,u.ips?.length
                  ?h('div',{className:'onenew-node-users-ips'},...u.ips.map(ip=>h('span',{key:ip,className:'onenew-node-users-ip'},ip)))
                  :h('span',{className:'onenew-up-dash'},'未上报 IP')),
                h('td',{className:'onenew-up-col-act onenew-up-sub'},u.device_count>0?String(u.device_count):'—'),
                h('td',{className:'onenew-up-col-act onenew-up-sub'},formatNodeBytes(u.today_total)))))))
            :h('p',{className:'onenew-up-loading'},'当前暂无用户在线（可切换到「按日使用明细」查看今日或历史使用记录）'),
          h('p',{className:'onenew-up-tip'},'实时在线合并节点设备心跳与近期流量上报（约 1–5 分钟窗口）；未开启设备数限制的用户可能仅显示在线状态而不上报具体 IP。')),
        data&&tab==='daily'&&h(R.Fragment,null,
          h('div',{className:'onenew-tunnel-cards'},
            h('div',{className:'onenew-tunnel-card'},
              h('span',{className:'onenew-tunnel-card-label'},'当日使用人数'),
              h('span',{className:'onenew-tunnel-card-val'},`${summary.active_users} 人`)),
            h('div',{className:'onenew-tunnel-card'},
              h('span',{className:'onenew-tunnel-card-label'},'节点当日总流量'),
              h('span',{className:'onenew-tunnel-card-val'},formatNodeBytes(summary.total))),
            h('div',{className:'onenew-tunnel-card'},
              h('span',{className:'onenew-tunnel-card-label'},'上传 / 下载'),
              h('span',{className:'onenew-tunnel-card-val'},`${formatNodeBytes(summary.u)} / ${formatNodeBytes(summary.d)}`))),
          dailyRows.length?h('div',{className:'onenew-up-table-wrap'},
            h('table',{className:'onenew-up-table'},
              h('thead',null,h('tr',null,
                h('th',null,'用户'),
                h('th',{className:'onenew-up-col-act'},'上传'),
                h('th',{className:'onenew-up-col-act'},'下载'),
                h('th',{className:'onenew-up-col-act'},'合计'))),
              h('tbody',null,...dailyRows.map(u=>h('tr',{key:u.user_id},
                h('td',null,
                  h('div',{className:'onenew-up-status-row'},
                    u.is_online&&h('span',{className:'onenew-up-dot onenew-up-dot-on',title:'当前在线'}),
                    h('span',{className:'onenew-up-name'},u.email),
                    h('span',{className:'onenew-up-sid'},`#${u.user_id}`))),
                h('td',{className:'onenew-up-col-act onenew-up-sub'},formatNodeBytes(u.u)),
                h('td',{className:'onenew-up-col-act onenew-up-sub'},formatNodeBytes(u.d)),
                h('td',{className:'onenew-up-col-act onenew-up-name'},formatNodeBytes(u.total)))))))
            :h('p',{className:'onenew-up-loading'},`${activeDate||'当日'} 暂无该节点的使用记录`),
          totalPages>1&&h('div',{className:'onenew-up-toolbar'},
            h('span',{className:'onenew-up-count'},`共 ${daily?.total||0} 人 · 第 ${page} / ${totalPages} 页`),
            h('div',{className:'onenew-up-toolbar-right'},
              h('button',{type:'button',className:'onenew-up-btn',disabled:page<=1||busy,onClick:()=>setPage(p=>Math.max(1,p-1))},'上一页'),
              h('button',{type:'button',className:'onenew-up-btn',disabled:page>=totalPages||busy,onClick:()=>setPage(p=>Math.min(totalPages,p+1))},'下一页')))))));
}
