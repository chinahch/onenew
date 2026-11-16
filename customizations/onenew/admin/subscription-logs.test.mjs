import test from 'node:test';import assert from 'node:assert/strict';import fs from 'node:fs';import {subscriptionLogParams,SubscriptionLogsPage} from './src/subscription-logs.mjs';import {patchBundle} from './patch.mjs';
test('subscription logs require explicit user and preserve scoped pagination',()=>{assert.equal(subscriptionLogParams(null),null);assert.deepEqual(subscriptionLogParams(12,3,'v2rayN'),{user_id:12,page:3,search:'v2rayN'})});
test('the actual plugin logs URL opens user selection instead of the generic all-user CRUD page',()=>{const raw=fs.readFileSync(process.env.ONENEW_ADMIN_UPSTREAM?`${process.env.ONENEW_ADMIN_UPSTREAM}/assets/index-CEIYH7i8.js`:new URL('../../../../xboard-admin-dist/assets/index-CEIYH7i8.js',import.meta.url),'utf8');const out=patchBundle(raw,'');assert.ok(out.includes('path:"plugins/subscription_guard/logs",element:Q.jsx(OnenewAdmin.SubscriptionLogsPage'));assert.ok(out.includes('path:"plugins/:pluginCode/*"'))});

test('logs display a separate location column while preserving full IPv6 text',()=>{
 const values=[[],{id:12,email:'user@example.test'},'','',[{id:1,ip:'240e:3b7:3272:d8d0:db09:c067:8d59:539e',ip_region:'中国 · 广东省 · 深圳市',user_agent:'v2rayN/7',created_at:'2026-11-17 10:00:00'}],1,1,1,false,'',true];let i=0;
 const R={createElement:(type,props,...children)=>({type,props,children}),useState:()=>[values[i++],()=>{}],useRef:v=>({current:v}),useEffect:()=>{}};
 const tree=SubscriptionLogsPage({react:R,get:async()=>({})});const result=JSON.stringify(tree);
 assert.ok(result.includes('IP 归属地'));assert.ok(result.includes('中国 · 广东省 · 深圳市'));assert.ok(result.includes('240e:3b7:3272:d8d0:db09:c067:8d59:539e'));assert.ok(result.includes('onenew-log-ip'));
});
