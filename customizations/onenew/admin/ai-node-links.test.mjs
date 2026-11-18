import test from 'node:test';
import assert from 'node:assert/strict';
import {nodeLinkIntent,createAiSession,chatSnapshot,exportChat} from './src/ai-operations.mjs';
test('direct request resolves exact node and explicit user without guessing',()=>{
 assert.deepEqual(nodeLinkIntent('请根据节点45的参数，给我节点链接，用户 a@example.com'),{node_id:45,email:'a@example.com'});
 assert.deepEqual(nodeLinkIntent('给我节点 45 的链接，用户ID 12'),{node_id:45,user_id:12});
 assert.deepEqual(nodeLinkIntent('请根据节点45的参数，给我节点链接'),{node_id:45});
 assert.deepEqual(nodeLinkIntent('a@example.com',{node_id:45}),{node_id:45,email:'a@example.com'});
 for(const q of ['解释节点45为什么没有链接','不要生成节点45链接','修改节点45地址','a@example.com','给我节点45链接 a@example.com b@example.com','给我节点45链接 用户ID12 用户ID13'])assert.equal(nodeLinkIntent(q),null);
});
test('native credential results and form inputs never persist export or become model history',()=>{
 const s=createAiSession();s.linkResults=[{uri:'vless://private-uuid@host:443',user_id:1}];s.linkDraft={node_id:45,user:'private@example.com'};
 assert.ok(!JSON.stringify(chatSnapshot(s)).includes('private'));assert.ok(!exportChat(s).includes('private'));
});

import * as ai from './src/ai-operations.mjs';
function harness(){const state=[];let cursor=0;const R={Fragment:'fragment',createElement:(type,props,...children)=>({type,props:props||{},children}),useState:v=>{const i=cursor++;if(!(i in state))state[i]=v;return[state[i],v=>state[i]=typeof v==='function'?v(state[i]):v]},useRef:()=>({current:null}),useEffect:()=>{}};return {render:props=>{cursor=0;return ai.AiConversation({react:R,Link:'a',...props})}}}
function all(t){return[t,...(t?.children||[]).flat(Infinity).filter(v=>v&&typeof v==='object').flatMap(all)]}
function button(t,label){return all(t).find(v=>v.type==='button'&&(v.props['aria-label']===label||v.children[0]===label))}
function input(t,label){return all(t).find(v=>v.props['aria-label']===label)}
function ready(backend='opencode'){ai.activateAccount('node-links-fixture-'+backend,backend);const h=harness();button(h.render({}),'清空').props.onClick()}
const result={node_id:45,node_name:'Fixture node',user_id:12,generated_at:'2026-11-19T00:00:00Z',source:'native_subscription',uri:'vless://fixture-user-uuid@[2001:db8::1]:443?security=reality#fixture'};
test('two turn request bypasses model and displays verified native result without persisting credentials',async()=>{
 const store=new Map();Object.defineProperty(globalThis,'localStorage',{configurable:true,value:{getItem:k=>store.get(k),setItem:(k,v)=>store.set(k,v)}});ready();const h=harness(),calls=[];const props={post:async(url,body)=>{calls.push({url,body});return{node_link:result}}};
 input(h.render(props),'消息').props.onChange({target:{value:'请根据节点45的参数，给我节点链接'}});await button(h.render(props),'发送消息').props.onClick();assert.equal(calls.length,0);assert.ok(JSON.stringify(h.render(props)).includes('请选择使用哪个用户'));
 input(h.render(props),'消息').props.onChange({target:{value:'private@example.com'}});await button(h.render(props),'发送消息').props.onClick();assert.equal(calls.length,1);assert.ok(calls[0].url.endsWith('/node-links'));assert.deepEqual(calls[0].body,{node_id:45,email:'private@example.com'});let tree=h.render(props);assert.equal(input(tree,'完整节点链接').props.value,result.uri);assert.ok(JSON.stringify(tree).includes('未验证实际连通性'));
 for(const saved of store.values()){assert.ok(!saved.includes(result.uri));assert.ok(!saved.includes('private@example.com'))}
 await button(tree,'复制链接').props.onClick();tree=h.render(props);assert.ok(JSON.stringify(tree).includes('自动复制失败'));
 input(tree,'消息').props.onChange({target:{value:'解释套餐功能'}});props.post=async(url,body)=>{assert.ok(!JSON.stringify(body).includes(result.uri));return{id:'later',state:'done',result:{answer:'ok'}}};await button(h.render(props),'发送消息').props.onClick();
});
test('form works in chat backend and clear prevents late native result reappearing',async()=>{
 ready('chat_completions');const h=harness();let resolve;const props={post:()=>new Promise(r=>resolve=r)};button(h.render(props),'生成节点链接').props.onClick();input(h.render(props),'链接节点编号').props.onChange({target:{value:'45'}});input(h.render(props),'链接用户编号或邮箱').props.onChange({target:{value:'12'}});const pending=button(h.render(props),'查询并生成链接').props.onClick();button(h.render(props),'清空').props.onClick();resolve({node_link:result});await pending;assert.ok(!JSON.stringify(h.render(props)).includes(result.uri));
});
test('invalid native evidence is rejected instead of displaying an invented link',async()=>{
 ready();const h=harness(),props={post:async()=>({node_link:{...result,source:'model_answer'}})};input(h.render(props),'消息').props.onChange({target:{value:'给我节点45链接，用户ID12'}});await button(h.render(props),'发送消息').props.onClick();const t=h.render(props);assert.ok(JSON.stringify(t).includes('没有生成可用链接'));assert.equal(input(t,'完整节点链接'),undefined)
});

test('account switch discards credential result and blocks stale copy and in-flight requests',async()=>{
 ready();const h=harness(),calls=[];const props={post:async(url,body)=>{calls.push({url,body});return{node_link:result}}};input(h.render(props),'消息').props.onChange({target:{value:'给我节点45链接，用户ID12'}});await button(h.render(props),'发送消息').props.onClick();const oldTree=h.render(props);assert.equal(input(oldTree,'完整节点链接').props.value,result.uri);ai.activateAccount('different-admin-link-scope','opencode');assert.equal(input(h.render(props),'完整节点链接'),undefined);let copied=false;Object.defineProperty(globalThis,'navigator',{configurable:true,value:{clipboard:{writeText:async()=>{copied=true}}}});await button(oldTree,'复制链接').props.onClick();assert.equal(copied,false);await button(oldTree,'查询并生成链接').props.onClick();assert.equal(calls.length,1);
 ready();let resolve;props.post=()=>new Promise(r=>resolve=r);input(h.render(props),'消息').props.onChange({target:{value:'给我节点45链接，用户ID12'}});const pending=button(h.render(props),'发送消息').props.onClick();ai.activateAccount('another-admin-link-scope','opencode');resolve({node_link:result});await pending;assert.equal(input(h.render(props),'完整节点链接'),undefined);
});

test('editing either target removes old credential result and disables stale copy callbacks',async()=>{
 for(const [field,next] of [['链接节点编号','46'],['链接用户编号或邮箱','13']]){
  ready();const h=harness(),props={post:async()=>({node_link:result})};input(h.render(props),'消息').props.onChange({target:{value:'给我节点45链接，用户ID12'}});await button(h.render(props),'发送消息').props.onClick();const oldCopy=button(h.render(props),'复制链接').props.onClick;assert.equal(input(h.render(props),'完整节点链接').props.value,result.uri);input(h.render(props),field).props.onChange({target:{value:next}});assert.equal(input(h.render(props),'完整节点链接'),undefined);let copied=false;Object.defineProperty(globalThis,'navigator',{configurable:true,value:{clipboard:{writeText:async()=>{copied=true}}}});await oldCopy();assert.equal(copied,false);
 }
 ready();const h=harness(),props={post:async()=>{throw Error('fixture')}};input(h.render(props),'消息').props.onChange({target:{value:'给我节点45链接，用户ID12'}});await button(h.render(props),'发送消息').props.onClick();assert.ok(JSON.stringify(h.render(props)).includes('没有生成可用链接'));input(h.render(props),'链接节点编号').props.onChange({target:{value:'46'}});assert.ok(!JSON.stringify(h.render(props)).includes('没有生成可用链接'));
});
test('native SOCKS and HTTPS proxy links are displayed with the same evidence boundary',async()=>{
 for(const uri of ['socks5://fixture:credential@host.example:1080#proxy','https://fixture:credential@host.example:443#proxy']){
  ready();const h=harness(),props={post:async()=>({node_link:{...result,uri}})};input(h.render(props),'消息').props.onChange({target:{value:'给我节点45链接，用户ID12'}});await button(h.render(props),'发送消息').props.onClick();assert.equal(input(h.render(props),'完整节点链接').props.value,uri)
 }
});
