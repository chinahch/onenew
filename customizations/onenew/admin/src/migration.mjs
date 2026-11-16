const root='/api/onenew/admin/migration';
const getApi=(get,url)=>get(url,{baseURL:'/'});
const postApi=(post,url,data)=>post(url,data,{baseURL:'/'});
export function restoreCommand(revision,filename){
 if(!/^[a-f0-9]{40}$/.test(revision)||!/^onenew-migration-[a-f0-9-]{36}\.onm$/.test(filename))throw new Error('迁移版本或文件名无效');
 return `bash <(curl -fsSL 'https://get.timi.baby/panel/restore' || printf '\\nexit 1\\n') --revision '${revision}' --package '/root/${filename}' --target '/opt/onenew'`;
}
export const stopOldServerCommand = `cd /opt/onenew && env -u COMPOSE_PROJECT_NAME -u COMPOSE_FILE docker compose --env-file /dev/null -f /opt/onenew/compose.yaml down --remove-orphans`;
export const cleanOldServerCommand = `# 仅在旧服务器执行；先确认新站登录、订阅及节点正常，并保存迁移包。
(
  set -eu
  target=/opt/onenew
  [ "$(realpath -- "$target")" = /opt/onenew ] && [ ! -L "$target" ] || { echo '目录校验失败，停止'; exit 1; }
  [ -f "$target/compose.yaml" ] || { echo '未找到旧面板 compose.yaml，停止'; exit 1; }
  printf '当前服务器: '; hostname
  read -r -p '永久删除旧面板全部数据？输入 DELETE-OLD-ONENEW: ' answer </dev/tty
  [ "$answer" = DELETE-OLD-ONENEW ] || exit 1
  cd "$target"
  env -u COMPOSE_PROJECT_NAME -u COMPOSE_FILE docker compose --env-file /dev/null -f /opt/onenew/compose.yaml down --remove-orphans
  cd /opt
  rm -rf --one-file-system -- /opt/onenew
)`;
export function startMigration(post,password,confirmation){
 const bytes=new TextEncoder().encode(password).length;
 if(bytes<12||bytes>128||/[\x00-\x1f\x7f]/.test(password)||password!==confirmation)throw new Error('请填写两次相同的迁移密码（12–128 字节，不含控制字符）');
 return postApi(post,root+'/exports',{password,password_confirmation:confirmation});
}
export function downloadMigration(ticket){
 if(!/^[a-f0-9]{64}$/.test(ticket))throw new Error('下载凭据无效');
 const form=document.createElement('form');form.method='POST';form.action='/api/onenew/migration/download';form.target='_blank';form.style.display='none';
 const field=document.createElement('input');field.type='hidden';field.name='ticket';field.value=ticket;form.appendChild(field);document.body.appendChild(form);form.submit();form.remove();
}
const phases={queued:'等待后台任务',database:'生成数据库快照',files:'复制插件和主题',encrypting:'加密迁移包',ready:'迁移包已就绪',failed:'生成失败',expired:'迁移包已过期'};
export function MigrationPage({react:React,get,post}){
 const h=React.createElement,[info,setInfo]=React.useState(null),[task,setTask]=React.useState(null),[password,setPassword]=React.useState(''),[confirmation,setConfirmation]=React.useState(''),[busy,setBusy]=React.useState(false),[error,setError]=React.useState(''),[copied,setCopied]=React.useState(false);
 React.useEffect(()=>{let active=true;getApi(get,root).then(v=>{if(active){setInfo(v);if(v.latest)setTask(v.latest)}}).catch(()=>{if(active)setError('无法读取迁移设置，请刷新重试')});return()=>{active=false}},[get]);
 React.useEffect(()=>{
  if(!task||['ready','failed','expired'].includes(task.state))return;let active=true;
  const timer=setTimeout(async()=>{if(task.expires_at&&Date.now()>=task.expires_at*1000){setTask({...task,state:'expired'});return}try{const value=await getApi(get,root+'/exports/'+task.id);if(active){setTask(value);setError('')}}catch{if(active){setError('暂时无法读取进度，正在重试…');setTask({...task})}}},3000);
  return()=>{active=false;clearTimeout(timer)};
 },[task,get]);
 async function generate(){setBusy(true);setError('');setCopied(false);try{const value=await startMigration(post,password,confirmation);setPassword('');setConfirmation('');setTask(value)}catch(e){setError(e?.response?.data?.message||e.message||'迁移任务创建失败')}finally{setBusy(false)}}
 async function download(){setBusy(true);setError('');try{const value=await postApi(post,root+'/exports/'+task.id+'/ticket',{});downloadMigration(value.ticket)}catch{setError('无法下载迁移包，可能已经过期')}finally{setBusy(false)}}
 const inProgress=task&&!['ready','failed','expired'].includes(task.state);
 const filename=task?`onenew-migration-${task.id}.onm`:'';
 const command=info?.supported&&task?restoreCommand(task.revision||info.revision,filename):'';
 return h('section',{className:'onenew-migration onenew-landing','aria-label':'一键迁移'},
  h('h2',null,'一键迁移'),h('p',{className:'onenew-help'},'源码仓库私有化后，新面板服务器需先将仅可读取源码的 GitHub 凭据保存到 /root/.config/onenew/github.token，权限600。不要使用节点 Token；此凭据不包含在迁移包内。'),h('p',{className:'onenew-help'},'生成加密迁移包，在新服务器执行一条恢复命令。保留账号、订阅链接、节点凭据、插件及站点设置。'),
  error&&h('p',{role:'alert',className:'onenew-error'},error),!info&&!error&&h('p',null,'正在检查部署…'),
  info&&!info.supported&&h('p',{className:'onenew-notice'},info.reason),
  info?.supported&&h(React.Fragment,null,
   h('label',{className:'onenew-field'},h('span',null,'迁移密码'),h('input',{type:'password',autoComplete:'new-password',value:password,disabled:busy||!!inProgress,onChange:e=>setPassword(e.target.value)})),
   h('label',{className:'onenew-field'},h('span',null,'确认迁移密码'),h('input',{type:'password',autoComplete:'new-password',value:confirmation,disabled:busy||!!inProgress,onChange:e=>setConfirmation(e.target.value)})),
   h('p',{className:'onenew-help'},'密码请自行保管，恢复时需要输入。迁移包在服务器保留 1 小时，下载后请妥善保存。'),
   h('button',{type:'button',className:'onenew-button',disabled:busy||!!inProgress||!password||!confirmation,onClick:generate},inProgress?'正在生成…':'生成加密迁移包')),
  task&&h('div',{className:'onenew-notice',role:'status'},h('strong',null,phases[task.state]||'正在生成'),task.error&&h('p',null,task.error),task.state==='ready'&&h('div',{className:'onenew-resource-actions'},h('span',null,`${(task.bytes/1048576).toFixed(1)} MiB`),h('button',{type:'button',className:'onenew-resource-button',disabled:busy,onClick:download},'下载迁移包'))),
  command&&h('div',{className:'onenew-preview'},h('h3',null,'在新服务器恢复'),h('p',{className:'onenew-help'},`先将 ${filename} 上传到新服务器 /root，安装好 Docker Compose，再在终端执行：`),h('pre',null,command),
   h('button',{type:'button',className:'onenew-resource-button',onClick:async()=>{try{await navigator.clipboard.writeText(command);setCopied(true)}catch{setError('复制失败，请手动复制命令')}}},copied?'已复制':'复制恢复命令')),
  h('div',{className:'onenew-notice'},h('strong',null,'切换步骤'),h('ol',null,
   h('li',null,'安排维护窗口，生成最终迁移包；包只包含生成时的数据，之后的新写入不会自动同步。'),
   h('li',null,'新服务器恢复到新的 /opt/onenew 目录，自动开启公网 7001 端口并显示用户及管理后台地址；请放行安全组端口。目录非空时停止。'),
   h('li',null,'确认新站登录、订阅和节点凭据正常，再切换域名解析、反向代理或 CF Tunnel。沿用原域名时节点机无需重新安装。'),
   h('li',null,'缓存、登录会话与运行中的队列任务不迁移。保留旧服务器，以便回退。'))),
  h('div',{className:'onenew-preview'},h('h3',null,'旧服务器卸载与清理'),h('p',{className:'onenew-help'},'确认域名已切换，新站登录、订阅、节点正常后，仅在旧服务器执行。先停止旧面板，保留数据供回退：'),h('pre',null,stopOldServerCommand),h('p',{className:'onenew-help'},'确认无需回退后，再执行永久清理。将删除 /opt/onenew 内数据库、配置、插件及本地迁移包；提前下载备份。不会卸载 Docker、清理其他项目或停止节点机 caddy。自定义安装目录请手工核对处理。'),h('pre',null,cleanOldServerCommand)));
}
