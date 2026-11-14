import test from 'node:test'
import assert from 'node:assert/strict'
const storage=new Map<string,string>()
Object.defineProperty(globalThis,'sessionStorage',{value:{getItem:(key:string)=>storage.get(key)||null,setItem:(key:string,value:string)=>storage.set(key,value),removeItem:(key:string)=>storage.delete(key)}})
Object.defineProperty(globalThis,'window',{value:new EventTarget()})
const {api,endpoint,login,logout,bytes}=await import('./api.ts')
const {session,setSession}=await import('./session.ts')
const reply=(data:unknown,status=200)=>new Response(JSON.stringify(data),{status,headers:{'Content-Type':'application/json'}})

test('endpoint mapping never permits an external URL or old admin route',()=>{
 assert.equal(endpoint('/api/me/subscription'),'/api/onenew/user/subscription')
 assert.equal(endpoint('/api/me/nodes'),'/api/onenew/user/nodes')
 for(const path of ['https://example.invalid/api','//example.invalid/api','/api/admin/users','/api/\\evil'])assert.throws(()=>endpoint(path))
})
test('native bearer is the single session credential and is attached only via header',async()=>{
 let calls=0
 globalThis.fetch=async(url,options)=>{
  calls++;assert.equal(options?.credentials,'omit');assert.equal(options?.redirect,'error')
  if(calls===1){assert.equal(url,'/api/v1/passport/auth/login');assert.equal(new Headers(options?.headers).get('Authorization'),null);return reply({data:{auth_data:'Bearer test_only_session',token:'unused_subscription_token'}})}
  assert.equal(new Headers(options?.headers).get('Authorization'),'Bearer test_only_session');return reply({user:{email:'qa@example.invalid'}})
 }
 await login('qa@example.invalid','test-only-placeholder')
 assert.equal(session().bearer,'Bearer test_only_session');assert.deepEqual([...storage.keys()],['onenew.auth'])
 await api('/api/me');assert.equal(calls,2)
})
test('responses from an earlier account cannot overwrite current identity',async()=>{
 setSession('Bearer test_only_a')
 let finish!:(value:Response)=>void
 globalThis.fetch=()=>new Promise(resolve=>{finish=resolve})
 const request=api('/api/me')
 setSession('Bearer test_only_b')
 finish(reply({user:{id:1}}))
 await assert.rejects(request,/账户已切换/)
 assert.equal(session().bearer,'Bearer test_only_b')
})
test('native auth expiry clears session and logout revokes using the active bearer',async()=>{
 setSession('Bearer test_only_b')
 globalThis.fetch=async()=>reply({message:'expired'},403)
 await assert.rejects(api('/api/me'),/expired/);assert.equal(session().bearer,'')
 setSession('Bearer test_only_c')
 globalThis.fetch=async(url,options)=>{assert.equal(url,'/api/onenew/user/logout');assert.equal(options?.method,'POST');assert.equal(new Headers(options?.headers).get('Authorization'),'Bearer test_only_c');return reply({success:true})}
 await logout();assert.equal(storage.size,0)
})
test('logout drops local credential even if server revoke fails',async()=>{
 setSession('Bearer test_only_d');globalThis.fetch=async()=>reply({message:'unavailable'},503)
 await assert.rejects(logout(),/unavailable/);assert.equal(session().bearer,'')
})
test('a malformed native login response cannot create a partial session',async()=>{
 globalThis.fetch=async()=>reply({data:{token:'unused_subscription_token'}})
 await assert.rejects(login('qa@example.invalid','test-only-placeholder'),/有效凭据/)
 assert.equal(session().bearer,'');assert.equal(storage.size,0)
})
test('format byte quotas without interpreting plan GB as user bytes',()=>{assert.equal(bytes(1073741824),'1 GiB');assert.equal(bytes(0),'0 B')})
