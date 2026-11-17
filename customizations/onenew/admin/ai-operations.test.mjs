import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import {patchBundle} from './patch.mjs';
import {createAiSession,previewPayload,confirmPayload} from './src/ai-operations.mjs';
test('preview preserves bounded conversation and confirmation references immutable snapshot only',()=>{
 const s=createAiSession();s.question='why';s.history=[{role:'assistant',content:'earlier'}];
 assert.deepEqual(previewPayload(s),{question:'why',scope:'panel',history:s.history});
 s.preview={preview_id:'snapshot',question:'sanitized'};s.requestId='id';s.question='changed';
 assert.deepEqual(confirmPayload(s),{preview_id:'snapshot',request_id:'id',confirmed:true});
 assert.notEqual(createAiSession().history,s.history);
});
test('actual pinned bundle mounts assistant inside authenticated shell and native routes',()=>{
 const source=fs.readFileSync(path.join(process.env.ONENEW_ADMIN_UPSTREAM,'assets/index-CEIYH7i8.js'),'utf8');const p=patchBundle(source,'');
 assert.ok(p.includes('{path:"ai-operations",element:Q.jsx(OnenewAdmin.AiPage'));
 assert.ok(p.includes('{path:"ai",element:Q.jsx(OnenewAdmin.AiSettings'));
 const a=p.indexOf('const sct='),b=p.indexOf('var oct=',a);assert.ok(p.slice(a,b).includes('OnenewAdmin.AiLauncher'));
 assert.equal(p.match(/OnenewAdmin.AiLauncher/g).length,1);
 assert.ok(p.includes('OnenewAdmin.AiPage,{react:H,get:IL,post:RL,Link:jg,useLocation:ig}'));assert.ok(p.includes('OnenewAdmin.AiLauncher,{react:H,get:IL,post:RL,Link:jg,useLocation:ig}'));
 assert.ok(p.includes('href:"/ai-operations"'));
});
import {AiConversation,AiSettings,AiLauncher} from './src/ai-operations.mjs';
function harness(){const effects=[];return {effects,R:{createElement:(type,props,...children)=>({type,props:props||{},children}),useState:v=>[v,()=>{}],useRef:()=>({current:null}),useEffect:fn=>effects.push(fn)}}}
function all(tree){return [tree,...(tree?.children||[]).flat(Infinity).filter(x=>x&&typeof x==='object').flatMap(all)]}
test('mount and settings edits never send external requests; preview and confirm are distinct clicks',async()=>{
 const {R,effects}=harness(),calls=[];const get=async()=>({nodes:[]});const post=async(url,body)=>{calls.push({url,body});return url.endsWith('/preview')?{preview_id:'immutable',question:'safe',history:[]}: {answer:'<script>alert(1)</script>',sources:[]}};
 let tree=AiConversation({react:R,get,post});effects.forEach(fn=>fn());assert.equal(calls.length,0);
 all(tree).find(n=>n.type==='textarea').props.onChange({target:{value:'question'}});
 tree=AiConversation({react:R,get,post});await all(tree).find(n=>n.type==='button'&&n.children[0]==='预览待发送内容').props.onClick();assert.equal(calls.length,1);assert.ok(calls[0].url.endsWith('/preview'));
 tree=AiConversation({react:R,get,post});const confirm=all(tree).find(n=>n.type==='button'&&n.children[0]==='确认发送 / 重试同一请求');assert.ok(confirm);await confirm.props.onClick();assert.equal(calls.length,2);assert.deepEqual(Object.keys(calls[1].body).sort(),['confirmed','preview_id','request_id']);assert.equal(calls[1].body.preview_id,'immutable');
 tree=AiConversation({react:R,get,post});assert.ok(all(tree).some(n=>n.type==='pre'&&n.children[0]==='<script>alert(1)</script>'));assert.ok(all(tree).every(n=>!n.props.dangerouslySetInnerHTML));
 const x=harness();AiSettings({react:x.R,get:async()=>({config:{}}),post});x.effects.forEach(fn=>fn());assert.equal(calls.length,2);
});
test('launcher exposes keyboard dialog semantics and no persistent conversation storage',()=>{
 const src=fs.readFileSync(new URL('./src/ai-operations.mjs',import.meta.url),'utf8');assert.ok(src.includes("e.key==='Escape'"));assert.ok(src.includes("e.key==='Tab'"));assert.ok(src.includes('before.focus()'));assert.ok(!/localStorage|sessionStorage|dangerouslySetInnerHTML/.test(src));
});
test('shared conversation resets when authenticated shell unmounts',()=>{
 const a=harness();AiLauncher({react:a.R,get:async()=>({}),post:async()=>({})});const cleanups=a.effects.map(fn=>fn()).filter(fn=>typeof fn==='function');assert.ok(cleanups.length>0);cleanups.forEach(fn=>fn());
 const b=harness(),tree=AiConversation({react:b.R,get:async()=>({nodes:[]}),post:async()=>({})});assert.equal(all(tree).find(n=>n.type==='textarea').props.value,'');
});
test('failed explicit send retains the same immutable preview and UUID for manual retry',async()=>{
 const x=harness(),calls=[];let fail=true;const post=async(url,body)=>{calls.push({url,body});if(url.endsWith('/preview'))return {preview_id:'retry-snapshot',question:'safe'};if(fail)throw Error('secret provider error');return {answer:'done'}};
 let tree=AiConversation({react:x.R,get:async()=>({nodes:[]}),post});all(tree).find(n=>n.type==='textarea').props.onChange({target:{value:'retry me'}});
 tree=AiConversation({react:x.R,get:async()=>({nodes:[]}),post});await all(tree).find(n=>n.children[0]==='预览待发送内容').props.onClick();
 tree=AiConversation({react:x.R,get:async()=>({nodes:[]}),post});await all(tree).find(n=>n.children[0]==='确认发送 / 重试同一请求').props.onClick();assert.equal(calls.length,2);
 fail=false;tree=AiConversation({react:x.R,get:async()=>({nodes:[]}),post});assert.ok(!JSON.stringify(tree).includes('secret provider error'));await all(tree).find(n=>n.children[0]==='确认发送 / 重试同一请求').props.onClick();assert.equal(calls.length,3);assert.deepEqual(calls[1].body,calls[2].body);assert.match(calls[2].body.request_id,/^[0-9a-f-]{36}$/);
});
import vm from 'node:vm';
test('assistant internal links use actual upstream Router Link with secure admin basename',()=>{
 const src=fs.readFileSync(path.join(process.env.ONENEW_ADMIN_UPSTREAM,'assets/index-CEIYH7i8.js'),'utf8'),start=src.indexOf('jg=H.forwardRef(')+3,end=src.indexOf(';var Fg',start);const expression=src.slice(start,end);
 const context={H:{forwardRef:f=>f,useContext:()=>({basename:'/secure-admin',navigator:{createHref:v=>v.pathname}}),useCallback:f=>f,createElement:(type,props)=>({type,props})},Qh:{},Cg:[],Pg:/^(?:[a-z][a-z0-9+.-]*:|\/\/)/i,Ag:false,ng:()=>true,hu:()=>{},ag:to=>({pathname:to}),Uu:xs=>xs.join('/').replace(/\/+/g,'/'),sg:()=>()=>{},ig:()=>({pathname:'/'}),xg:Object.assign,fu:()=>''};const Link=vm.runInNewContext(expression,context);
 const x=harness(),tree=AiConversation({react:x.R,get:async()=>({nodes:[]}),post:async()=>({}),Link,useLocation:()=>({pathname:'/server/manage'})});const links=all(tree).filter(n=>n.type===Link);assert.ok(links.length>=7);for(const node of links)assert.equal(Link(node.props).props.href,'/secure-admin'+node.props.to);assert.equal(all(tree).filter(n=>n.type==='a').length,0);
});
test('current page scope is inferred from router pathname without sending anything',async()=>{
 const {scopeForPath}=await import('./src/ai-operations.mjs');for(const [path,scope] of [['/server/manage','nodes'],['/server/machine','nodes'],['/user/manage','users'],['/plan/manage','users'],['/finance/order','orders'],['/config/system/energy','orders'],['/config/system/migration','backups'],['/server/upgrade','upgrades'],['/config/system','panel']])assert.equal(scopeForPath(path),scope);
 const x=harness();let sent=0;const tree=AiConversation({react:x.R,get:async()=>({nodes:[]}),post:async()=>{sent++},Link:()=>{},useLocation:()=>({pathname:'/finance/order'})});all(tree).find(n=>n.children[0]==='使用当前页面范围').props.onClick();assert.equal(sent,0);const next=AiConversation({react:x.R,get:async()=>({nodes:[]}),post:async()=>{},Link:()=>{},useLocation:()=>({pathname:'/finance/order'})});assert.equal(all(next).find(n=>n.type==='select').props.value,'orders');
});
