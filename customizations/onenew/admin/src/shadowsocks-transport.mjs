export function ShadowsocksTransport({react:React,form}){
 const h=React.createElement,[state,setState]=React.useState(()=>form.getValues());
 React.useEffect(()=>{const sub=form.watch(value=>setState({...value}));return()=>sub.unsubscribe()},[form]);
 const set=(key,value)=>form.setValue(key,value,{shouldDirty:true,shouldValidate:true});
 const ws=state.network==='ws',tls=Number(state.tls||0)===1;
 const setNetwork=value=>{set('network',value);if(value==='tcp'){set('tls',0);set('plugin','');set('plugin_opts','');set('onenew_transport_managed',false);}if(value==='ws'&&!['aes-128-gcm','aes-256-gcm','chacha20-ietf-poly1305','2022-blake3-aes-128-gcm','2022-blake3-aes-256-gcm'].includes(state.cipher))set('cipher','aes-128-gcm')};
 const field=(label,value,change)=>h('label',{className:'onenew-field'},h('span',{className:'font-mono text-[12px] font-medium text-foreground/80'},label),h('input',{className:'h-9 w-full rounded-md border bg-background px-3 font-mono text-xs',value:value||'',onChange:e=>change(e.target.value)}));
 return h('section',{className:'space-y-4 rounded-lg border p-4 font-mono','aria-label':'Shadowsocks 传输与 TLS'},
  h('label',{className:'onenew-field'},h('span',{className:'font-mono text-[12px] font-medium text-foreground/80'},'传输协议'),h('select',{className:'h-9 w-full rounded-md border bg-background px-3 font-mono text-xs',value:ws?'ws':'tcp',onChange:e=>setNetwork(e.target.value)},h('option',{value:'tcp'},'TCP'),h('option',{value:'ws'},'WebSocket'))),
  h('label',{className:'flex items-center justify-between gap-3 font-mono text-[12px] font-medium text-foreground/80'},'启用 TLS',h('input',{type:'checkbox',role:'switch',checked:tls,onChange:e=>{if(e.target.checked)setNetwork('ws');set('tls',e.target.checked?1:0)}})),
  ws&&h('div',{className:'space-y-3'},
   field('TLS 域名 / WebSocket Host',state.tls_settings?.server_name||state.network_settings?.headers?.Host||'',value=>{set('tls_settings.server_name',value);set('network_settings.headers.Host',value)}),
   field('WebSocket 路径',state.network_settings?.path||'/',value=>set('network_settings.path',value)),
   h('p',{className:'font-mono text-[11px] text-muted-foreground'},'使用 Xray 内核，订阅自动生成 v2ray-plugin 参数。证书在高级设置配置；Argo 在入口终止 TLS 时，证书模式选 none。')));}
