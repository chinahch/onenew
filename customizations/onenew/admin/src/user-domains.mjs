export function normalizeFrontendDomains(value) {
 const rows=value.split(/\r?\n/).map(x=>x.trim()).filter(Boolean);
 if(rows.length>20) throw new Error('用户端域名最多20个');
 const result=[];
 for(const row of rows) {
  if(row.length>255 || !/^https?:\/\/[^/?#@\\\s]+\/?$/i.test(row)) throw new Error('请输入完整 HTTP(S) 域名，不带路径、参数或片段');
  let url; try {url=new URL(row)} catch {throw new Error('用户端域名格式不正确')}
  if(url.username || url.password || url.hostname.includes('*')) throw new Error('域名不能包含账号、密码或通配符');
  if(!result.includes(url.origin)) result.push(url.origin);
 }
 return result;
}

export function UserDomainsField({react:React,form}) {
 const h=React.createElement,saved=form.watch('user_frontend_urls')||[];
 const [text,setText]=React.useState(saved.join('\n')),[error,setError]=React.useState('');
 React.useEffect(()=>{setText(saved.join('\n'))},[JSON.stringify(saved)]);
 function save() {
  try {const urls=normalizeFrontendDomains(text);setError('');setText(urls.join('\n'));form.setValue('user_frontend_urls',urls,{shouldDirty:true,shouldValidate:true})}
  catch(e) {setError(e.message)}
 }
 return h('div',{className:'space-y-2'},
  h('label',{htmlFor:'onenew-user-domains',className:'text-base font-medium'},'用户端域名'),
  h('textarea',{id:'onenew-user-domains',className:'flex min-h-[96px] w-full rounded-md border border-input bg-background px-3 py-2 text-sm shadow-sm focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring',value:text,placeholder:'https://user.example.com\nhttps://user2.example.com',onChange:e=>setText(e.target.value),onBlur:save,'aria-invalid':!!error,'aria-describedby':'onenew-user-domains-help'}),
  h('p',{id:'onenew-user-domains-help',className:'text-sm text-muted-foreground'},'每行一个，最多20个；离开输入框后自动保存。共用用户数据，邀请链接沿用当前域名，订阅 URL 独立配置。域名需另外配置 DNS、HTTPS 和反向代理或隧道。'),
  error&&h('p',{role:'alert',className:'text-sm text-red-600'},error));
}
