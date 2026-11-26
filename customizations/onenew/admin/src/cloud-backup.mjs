const api='/api/onenew/admin/cloud-backup';
const active=new Set(['queued','generating','uploading','verifying']);
const phases={queued:'等待执行',generating:'生成加密备份',uploading:'上传中',verifying:'核对云盘文件',success:'备份成功',failed:'备份失败',paused:'迁移后已暂停'};
export function backupSettingsPayload(form){
 if(!/^(?:[01]\d|2[0-3]):[0-5]\d$/.test(form.daily_time||''))throw new Error('请选择每天备份的时、分');
 if(form.password){const size=new TextEncoder().encode(form.password).length;if(size<12||size>128||/[\x00-\x1f\x7f]/.test(form.password))throw new Error('备份密码需12–128字节且不含控制字符');if(form.password!==form.password_confirmation)throw new Error('两次备份密码不一致')}
 return Object.fromEntries(['enabled','daily_time','client_id','client_secret','refresh_token','folder_id','password','password_confirmation'].map(k=>[k,k==='enabled'?!!form[k]:(form[k]||'')]));
}
export function backupTime(value){return value?new Intl.DateTimeFormat('zh-CN',{timeZone:'Asia/Shanghai',year:'numeric',month:'2-digit',day:'2-digit',hour:'2-digit',minute:'2-digit',second:'2-digit',hourCycle:'h23'}).format(new Date(Number(value)*1000)):'—'}
export function backupDriveUrl(value){return typeof value==='string'&&/^https:\/\/drive\.google\.com\/file\/d\/[A-Za-z0-9_-]+\/view$/.test(value)?value:null}
export function backupBytes(value){return Number.isFinite(value)&&value>=0?`${(value/1048576).toFixed(2)} MiB`:'—'}
const storageLabels={v2_onenew_domain_events:'域名访问明细',v2_onenew_domain_batches:'域名上报批次',onenew_runtime_log_entries:'运行日志正文',onenew_runtime_log_batches:'运行日志批次',v2_onenew_traffic_receipts:'流量去重凭据'};
export function BackupStorageUsage({react:React,usage,onCleanup,cleaning,disabled}){
 const h=React.createElement,db=usage.database;
 const metric=(label,value)=>h(React.Fragment,{key:label},h('dt',null,label),h('dd',null,backupBytes(value)));
 return h('div',{className:'onenew-backup-storage','aria-label':'当前数据大小'},
  h('div',{className:'onenew-backup-heading'},h('h4',null,'当前数据大小'),h('span',{className:'onenew-help'},'检查时间：'+backupTime(usage.checked_at)+'（北京时间）')),
  h('dl',{className:'onenew-backup-meta'},h('dt',null,'文件合计'),h('dd',null,h('strong',null,(usage.complete?'':'至少 ')+backupBytes(usage.total_bytes))),metric('数据库主文件',db.main_bytes),metric('数据库 WAL',db.wal_bytes),metric('数据库 SHM',db.shm_bytes),Number.isFinite(db.effective_bytes)&&metric('数据库有效数据',db.effective_bytes),metric('插件',usage.plugins.bytes),metric('主题',usage.theme.bytes),metric('可复用空闲页',db.free_bytes)),
  !usage.complete&&h('p',{className:'onenew-notice'},'部分目录无法完整统计，或包含未跟随的符号链接；显示的是已读取文件的大小。'),
  h('p',{className:'onenew-help'},'按检查时的文件大小统计，包含数据库 WAL/SHM，不含临时备份包和单独保存的本地备份。空闲页已包含在数据库中，不重复计入合计；最终 .onm 文件大小可能不同。'),
  db.table_sizes_available?h('details',null,h('summary',null,'数据库占用排行（表及索引合计，前 15 项）'),h('div',{className:'onenew-table-scroll'},h('table',{className:'onenew-resource-table'},h('thead',null,h('tr',null,...['数据表','表数据','索引','合计','操作'].map(label=>h('th',{key:label,scope:'col'},label)))),h('tbody',null,...db.tables.map(row=>{
   const days=usage.cleanup_days?.[row.name],label=storageLabels[row.name];
   return h('tr',{key:row.name},h('td',null,label&&h('strong',null,label),h('small',null,row.name)),h('td',null,backupBytes(row.table_bytes)),h('td',null,backupBytes(row.index_bytes)),h('td',null,backupBytes(row.total_bytes)),h('td',null,
    label&&Number.isInteger(days)&&days>0&&onCleanup?h('button',{type:'button',className:'onenew-resource-button onenew-storage-cleanup',disabled,'aria-label':`清理${label}：保留 ${days} 天`,onClick:()=>onCleanup(row.name,days)},cleaning===row.name?'清理中…':'清理过期'):row.name==='v2_onenew_traffic_receipts'?'不支持清理':'—'));
  }))))):h('p',{className:'onenew-help'},'当前无法读取表级占用（dbstat），仍可查看上方的文件大小。'));
}
const publicForm=c=>({enabled:!!c?.enabled,daily_time:c?.daily_time||'03:00',client_id:c?.client_id||'',folder_id:c?.folder_id||'',client_secret:'',refresh_token:'',password:'',password_confirmation:''});
export function CloudBackup({react:React,get,post}){
 const h=React.createElement,[data,setData]=React.useState(null),[form,setForm]=React.useState(()=>publicForm(null)),[busy,setBusy]=React.useState(''),[error,setError]=React.useState(''),[message,setMessage]=React.useState('');
  const [usage,setUsage]=React.useState(null),[usageBusy,setUsageBusy]=React.useState(false),[usageError,setUsageError]=React.useState(''),usageBusyRef=React.useRef(false);
  const [cleaning,setCleaning]=React.useState(''),[cleanupMessage,setCleanupMessage]=React.useState('');
  const mounted=React.useRef(false),sequence=React.useRef(0),baseline=React.useRef(publicForm(null)),currentForm=React.useRef(form),busyRef=React.useRef(false);currentForm.current=form;
 async function load(initialize=false){const seq=++sequence.current;try{const value=await get(api,{baseURL:'/'});if(!mounted.current||seq!==sequence.current)return;setData(value);if(initialize||JSON.stringify(currentForm.current)===JSON.stringify(baseline.current)){baseline.current=publicForm(value.config);currentForm.current=baseline.current;setForm(baseline.current);}setError('')}catch(e){if(mounted.current&&seq===sequence.current)setError(e?.response?.data?.message||'无法读取云盘备份设置，请刷新重试')}}
 React.useEffect(()=>{mounted.current=true;load(true);const timer=setInterval(()=>{if(!busyRef.current)load()},5000);return()=>{mounted.current=false;sequence.current++;clearInterval(timer)}},[get]);
 const dirty=data&&JSON.stringify(form)!==JSON.stringify(baseline.current);
  const update=(key,value)=>setForm(v=>({...v,[key]:value}));
  async function checkUsage(){
   if(usageBusyRef.current||busyRef.current)return;usageBusyRef.current=true;setUsageBusy(true);setUsageError('');
   try{const value=await get(api+'/storage-usage',{baseURL:'/',headers:{'Cache-Control':'no-cache'}});if(mounted.current)setUsage(value)}
   catch(e){if(mounted.current)setUsageError(e?.response?.data?.message||(e?.response?.status===429?'检查较频繁，请稍后再试':'无法检查当前数据大小，请稍后重试'))}
   finally{usageBusyRef.current=false;if(mounted.current)setUsageBusy(false)}
  }
  async function cleanupUsage(table,days){
   if(usageBusyRef.current||busyRef.current||usage?.cleanup_days?.[table]!==days)return;
   if(!globalThis.confirm(`清理「${storageLabels[table]}」中超过 ${days} 天的记录？此操作不可撤销。`))return;
   busyRef.current=true;setCleaning(table);setUsageError('');setCleanupMessage('');
   let refresh=false;
   try{
    const result=await post(api+'/storage-cleanup',{table,retention_days:days,confirm:true},{baseURL:'/'});
    if(mounted.current){setCleanupMessage(result.deleted?`已删除 ${result.deleted} 条过期记录。${result.remaining?'仍有过期记录，可再次清理。':''} SQLite 文件可能不会立即缩小。`:'没有符合保留规则的过期记录。');refresh=true;}
   }catch(e){if(mounted.current)setUsageError(e?.response?.data?.message||'未能确认清理结果，请重新检查后再试。')}
   finally{busyRef.current=false;if(mounted.current)setCleaning('')}
   if(refresh)await checkUsage();
  }
 async function action(kind){
  if(busyRef.current)return;busyRef.current=true;setBusy(kind);setError('');setMessage('');sequence.current++;
  try{
   if(kind==='settings'){
    const value=await post(api+'/settings',backupSettingsPayload(form),{baseURL:'/'});
    if(mounted.current){sequence.current++;setData({...data,...value});baseline.current=publicForm(value.config);currentForm.current=baseline.current;setForm(baseline.current);setMessage('设置已保存')}
   }else{
    await post(api+'/'+kind,{}, {baseURL:'/'});if(mounted.current){setMessage(kind==='test'?'Google 云盘连接正常':'备份已排队，将在后台生成并上传');await load(kind==='test')}
   }
  }catch(e){if(mounted.current)setError(e?.response?.data?.message||e.message||'操作失败，请稍后重试')}
  finally{busyRef.current=false;if(mounted.current)setBusy('')}
 }
 const field=(key,label,type='text',placeholder='')=>h('label',{className:'onenew-field'},h('span',null,label),h('input',{type,value:form[key],placeholder,disabled:!!busy,autoComplete:type==='password'?'new-password':'off',onChange:e=>update(key,e.target.value)}));
 const button=(kind,label,disabled=false)=>h('button',{type:'button',className:'onenew-resource-button',disabled:!!busy||!data||disabled,onClick:()=>action(kind)},busy===kind?'处理中…':label);
 const link=run=>backupDriveUrl(run?.drive_url)&&h('a',{href:backupDriveUrl(run.drive_url),target:'_blank',rel:'noopener noreferrer'},'在 Google 云盘查看');
 const run=data?.latest,last=data?.latest_success,configured=data?.config;
 return h('section',{className:'onenew-cloud-backup','aria-label':'Google 云盘自动备份'},
  h('div',{className:'onenew-backup-heading'},h('div',null,h('h3',null,'Google 云盘自动备份'),h('p',{className:'onenew-help'},'使用你自己的 Google OAuth 配置，上传加密迁移包。每天按北京时间执行。')),h('button',{type:'button',className:'onenew-resource-button',disabled:!!busy,onClick:()=>load()},'刷新记录')),
  error&&h('p',{role:'alert',className:'onenew-error'},error),message&&h('p',{role:'status'},message),
  !data&&h('p',null,'正在读取设置…'),
  data&&h(React.Fragment,null,
   configured.restored_paused&&h('p',{className:'onenew-notice'},'检测到面板已迁移，自动备份已暂停。确认新旧面板切换后，再保存设置并开启。'),
   h('div',{className:'onenew-backup-grid'},
    field('client_id','Google Client ID','text','你的 OAuth Client ID'),
    field('client_secret','Client Secret','password',configured.client_secret_configured?'已设置，留空保留':'填写 Client Secret'),
    field('refresh_token','Refresh Token','password',configured.refresh_token_configured?'已设置，留空保留':'填写可离线刷新的 Refresh Token'),
    field('folder_id','云盘文件夹 ID（可选）','text','留空自动创建专用备份文件夹'),
    field('password','备份加密密码','password',configured.password_configured?'已设置，留空保留':'至少12字节，请自行保管'),
    field('password_confirmation','确认新的备份密码','password','修改密码时填写')),
   h('p',{className:'onenew-help'},'备份密码用于恢复，请另外自行保管。密钥留空会保留原值；云盘文件夹 ID 留空则自动创建专用目录。'),
   h('div',{className:'onenew-backup-schedule'},h('label',{className:'onenew-backup-toggle'},h('input',{type:'checkbox',role:'switch',checked:form.enabled,disabled:!!busy||data.supported===false,onChange:e=>update('enabled',e.target.checked)}),'开启每天自动备份'),field('daily_time','每天备份时间（北京时间）','time')),
   h('p',{className:'onenew-help'},'下一次计划：'+(configured.enabled?backupTime(configured.next_run_at):'尚未开启')+'。修改后点击保存。'),
    h('div',{className:'onenew-resource-actions'},button('settings','保存设置'),button('test','测试已保存配置',!!dirty||!configured.ready),button('run','立即备份并上传',!!cleaning||!!dirty||!configured.ready||configured.restored_paused||data.supported===false||active.has(run?.state)),h('button',{type:'button',className:'onenew-resource-button',disabled:!!busy||!!cleaning||usageBusy,'aria-busy':usageBusy,onClick:checkUsage},usageBusy?'检查中…':'检查当前数据大小')),
    usageBusy&&h('p',{role:'status',className:'onenew-help'},'正在读取当前数据大小…'),usageError&&h('p',{role:'alert',className:'onenew-error'},usageError),cleanupMessage&&h('p',{role:'status'},cleanupMessage),usage&&h(BackupStorageUsage,{react:React,usage,onCleanup:cleanupUsage,cleaning,disabled:!!busy||!!cleaning||usageBusy||active.has(run?.state)}),
   h('details',{className:'onenew-backup-guide'},h('summary',null,'如何配置 Google OAuth'),h('ol',null,
    h('li',null,'在自己的 Google Cloud 项目启用 Google Drive API，并创建 OAuth Client ID 和 Client Secret。'),
    h('li',null,'通过 Google OAuth Playground 使用自己的 OAuth 凭据，授权 drive.file 范围并取得 Refresh Token；再将这三项填写到上方。'),
    h('li',null,'Google OAuth 应用若处于 External / Testing 模式，Refresh Token 可能在7天后失效；长期定时备份需使用合适的正式授权配置。'),
    h('li',null,'建议留空文件夹 ID，由应用创建专用目录。已有文件夹必须在当前授权范围内。保存后先测试连接，再立即备份一次。')),
    h('a',{href:'https://developers.google.com/oauthplayground/',target:'_blank',rel:'noopener noreferrer'},'打开 Google OAuth Playground')),
   h('div',{className:'onenew-backup-recent'},h('h4',null,'最近一次备份'),
    !run?h('p',{className:'onenew-help'},'尚无备份记录'):h(React.Fragment,null,
     h('div',{className:'onenew-backup-heading'},h('strong',null,phases[run.state]||'等待状态更新'),h('span',{className:'onenew-help'},run.trigger==='scheduled'?'定时备份':'手动备份')),
     h('dl',{className:'onenew-backup-meta'},h('dt',null,'开始时间'),h('dd',null,backupTime(run.started_at||run.created_at)),h('dt',null,'完成时间'),h('dd',null,backupTime(run.finished_at)),h('dt',null,'文件大小'),h('dd',null,run.bytes?`${(run.bytes/1048576).toFixed(2)} MiB`:'—'),h('dt',null,'文件名'),h('dd',null,run.filename)),
     run.state==='uploading'&&run.bytes>0&&h('progress',{max:run.bytes,value:run.uploaded_bytes||0,'aria-label':'云盘上传进度'}),
     run.error&&h('p',{className:'onenew-error'},run.error),link(run)),
    last&&last.id!==run?.id&&h('div',{className:'onenew-notice'},h('strong',null,'上次成功：'),backupTime(last.finished_at),' · ',link(last)))));
}
