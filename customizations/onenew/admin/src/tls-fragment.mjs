export function validFragmentRange(value,minimum,maximum){
 if(typeof value!=='string'||value.trim()!==value||!/^\d{1,5}(?:-\d{1,5})?$/.test(value))return false;
 const parts=value.split('-').map(Number);
 return parts[0]>=minimum&&parts.at(-1)<=maximum&&parts[0]<=parts.at(-1);
}
export function TlsFragment({react:R,form}){
 const h=R.createElement,[values,setValues]=R.useState(()=>form.getValues());
 R.useEffect(()=>{const subscription=form.watch(value=>setValues({...value}));return()=>subscription.unsubscribe()},[form]);
 const set=(field,value)=>form.setValue(`tls_settings.fragment.${field}`,value,{shouldDirty:true,shouldValidate:true});
 const fragment=values.tls_settings?.fragment||{},enabled=fragment.enabled===true;
 const length=fragment.length??'40-60',interval=fragment.interval??'30-50';
 const tcp=['tcp','ws','grpc','h2','httpupgrade','xhttp'].includes(values.network||'tcp');
 if(![1,2].includes(Number(values.tls||0)))return null;
 const toggle=value=>{set('length',validFragmentRange(length,1,16384)?length:'40-60');set('interval',validFragmentRange(interval,0,1000)?interval:'30-50');set('enabled',value)};
 const field=(label,name,value,minimum,maximum)=>h('label',{className:'space-y-1 text-xs'},h('span',null,label),
  h('input',{className:'h-9 w-full rounded-md border bg-background px-3 font-mono text-xs',type:'text',value,
   'aria-invalid':!validFragmentRange(value,minimum,maximum),onChange:event=>set(name,event.target.value)}),
  !validFragmentRange(value,minimum,maximum)&&h('span',{className:'block text-destructive',role:'alert'},`请输入 ${minimum}–${maximum} 内的整数或递增区间，例如 10-20`));
 return h('section',{className:'space-y-3 rounded-lg border p-4','aria-label':'客户端 TLS 分片'},
  h('label',{className:'flex items-center justify-between gap-3'},
   h('span',null,h('strong',{className:'text-sm'},'TLS 分片'),h('span',{className:'block text-xs text-muted-foreground'},'Shadowrocket · 对应客户端 TLS 中的“片段”')),
   h('input',{type:'checkbox',role:'switch','aria-label':'启用 Shadowrocket TLS 分片',checked:enabled,disabled:!tcp,onChange:event=>toggle(event.target.checked)})),
  h('p',{className:'text-xs text-muted-foreground'},'仅写入小火箭专用订阅；不改变服务端 TLS。其他客户端订阅保持原样，UDP / QUIC 传输不适用。'),
  !tcp&&h('p',{className:'text-xs text-muted-foreground'},'当前传输方式不支持 TCP TLS 分片。'),
  enabled&&tcp&&h('div',{className:'grid grid-cols-1 gap-3 sm:grid-cols-2'},
   field('片段长度（字节）','length',length,1,16384),field('片段间隔（毫秒）','interval',interval,0,1000)));
}
