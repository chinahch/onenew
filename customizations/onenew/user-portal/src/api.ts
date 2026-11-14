import {session,setSession} from './session.ts'
export class ApiError extends Error { status:number; constructor(status:number,message:string){super(message);this.status=status} }
export function endpoint(path:string):string{
 if(!path.startsWith('/api/')||path.startsWith('//')||path.includes('\\'))throw new Error('只允许同源 API 请求')
 if(path.startsWith('/api/v1/')||path.startsWith('/api/onenew/'))return path
 if(path.startsWith('/api/me/'))return path.replace('/api/me/','/api/onenew/user/')
 if(path==='/api/me')return '/api/onenew/user/me'
 if(path.startsWith('/api/invites/'))return path.replace('/api/invites/','/api/onenew/invites/')
 if(path==='/api/register')return '/api/onenew/register'
 if(path==='/api/logout')return '/api/onenew/user/logout'
 if(path.startsWith('/api/usage'))return path.replace('/api/usage','/api/onenew/user/usage')
 throw new Error('此功能未接入 onenew')
}
export async function api<T=any>(path:string,options:RequestInit={}):Promise<T>{
 const before=session(),headers=new Headers(options.headers)
 headers.set('Accept','application/json')
 if(options.body)headers.set('Content-Type','application/json')
 if(before.bearer)headers.set('Authorization',before.bearer)
 const response=await fetch(endpoint(path),{...options,credentials:'omit',cache:'no-store',redirect:'error',headers})
 const data=await response.json().catch(()=>({message:'服务返回了无效响应'}))
 if(before.generation!==session().generation)throw new ApiError(409,'账户已切换，请重新加载')
 if(!response.ok){
  if((response.status===401||response.status===403)&&before.bearer)setSession('')
  const validation=data.errors&&Object.values(data.errors).flat().join('；')
  throw new ApiError(response.status,data.message||data.error||validation||'请求失败')
 }
 return (path.startsWith('/api/v1/')?data.data:data) as T
}
export async function login(email:string,password:string){
 setSession('')
 const data=await api<{auth_data:string}>('/api/v1/passport/auth/login',{method:'POST',body:JSON.stringify({email,password})})
 if(!data?.auth_data)throw new Error('登录响应缺少有效凭据')
 setSession(data.auth_data)
}
export async function logout(){try{await api('/api/logout',{method:'POST'})}finally{setSession('')}}
export function bytes(value:number|string|undefined):string{let n=Number(value||0);if(!Number.isFinite(n))return '—';if(n===0)return '0 B';const units=['B','KiB','MiB','GiB','TiB','PiB'];let i=0;while(n>=1024&&i<units.length-1){n/=1024;i++}return `${n.toLocaleString('zh-CN',{maximumFractionDigits:i?2:0})} ${units[i]}`}
export function stamp(value:string|null|undefined):string{return value?new Date(value).toLocaleString('zh-CN',{hour12:false}):'尚无上报'}
