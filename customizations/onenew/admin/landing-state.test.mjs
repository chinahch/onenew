import test from 'node:test';
import assert from 'node:assert/strict';
import * as api from './src/component.mjs';
const savedOut=[{tag:'landing',protocol:'socks',settings:{server:'ft-us.lazycat.cv',server_port:28000,version:'5',username:'fixture',password:'fixture'}}],savedRoutes=[{outbound:'landing'}];
function mount(props){let state=[],cursor=0,effects=[],previous=[];const React={createElement:(type,props,...children)=>({type,props:props||{},children:children.flat()}),useState(initial){const i=cursor++;if(!(i in state))state[i]=typeof initial==='function'?initial():initial;return[state[i],v=>state[i]=typeof v==='function'?v(state[i]):v]},useEffect(fn,deps){const i=cursor++;if(!previous[i]||deps.some((v,k)=>v!==previous[i][k])){previous[i]=deps;effects.push(fn)}},useMemo:fn=>fn()};const updates=[];const p={react:React,outbounds:JSON.stringify(savedOut),routes:JSON.stringify(savedRoutes),setOutbounds:v=>updates.push(['outbounds',v]),setRoutes:v=>updates.push(['routes',v]),...props};return{updates,render(){cursor=0;let tree=api.LandingEditor(p);for(const effect of effects.splice(0))effect();cursor=0;tree=api.LandingEditor(p);let nodes=[];function walk(n){if(!n||typeof n!=='object')return;nodes.push(n);for(const child of n.children||[])walk(child)}walk(tree);return nodes}};}
const text=nodes=>nodes.flatMap(n=>(n.children||[]).filter(c=>typeof c==='string')).join(' ');
test('saved global SOCKS draft reopens with tag and actual scope, no manual kernel selector',()=>{
 const e=mount({runtimeMetrics:{kernel_type:'singbox'}}),nodes=e.render();
 assert.equal(nodes.some(n=>n.type==='select'&&n.props['aria-label']==='目标内核'),false);
 assert.match(text(nodes),/Sing-box/);
 const tag=nodes.find(n=>n.props['aria-label']==='出口名称');assert.equal(tag.props.value,'landing');
 const checks=nodes.filter(n=>n.type==='input'&&n.props.type==='checkbox');assert.equal(checks[0].props.checked,true);assert.equal(checks[1].props.checked,false);
 assert.equal(nodes.find(n=>n.props['aria-label']==='已保存出口 JSON').props.value,JSON.stringify(savedOut));
 assert.match(text(nodes),/原生 JSON/);
});
test('runtime absence blocks generation even when saved outbound schema is known',()=>{
 const nodes=mount({}).render();assert.match(text(nodes),/等待.*上报/);
 const generate=nodes.find(n=>n.type==='button'&&n.children.includes('生成并预览'));assert.equal(generate.props.disabled,true);
});

test('a newly pasted link cannot accidentally test the previously saved landing',()=>{
 const editor=mount({runtimeMetrics:{kernel_type:'singbox'}});
 let nodes=editor.render();
 assert.equal(nodes.find(n=>n.type?.name==='LandingProbe').props.outbound.tag,'landing');
 nodes.find(n=>n.props['aria-label']==='落地链接').props.onChange({target:{value:'socks://new.example:1080'}});
 nodes=editor.render();
 assert.equal(nodes.find(n=>n.type?.name==='LandingProbe').props.outbound,null);
});
test('saving actual Xray migrates complete SOCKS credentials and global route without a replacement link',()=>{
 assert.equal(typeof api.prepareLandingSave,'function');
 const result=api.prepareLandingSave({outbounds:savedOut,routes:savedRoutes,runtimeMetrics:{kernel_type:'xray',onenew_runtime:true}});
 assert.deepEqual(result.outbounds,[{tag:'landing',protocol:'socks',settings:{servers:[{address:'ft-us.lazycat.cv',port:28000,users:[{user:'fixture',pass:'fixture'}]}]}}]);
 assert.deepEqual(result.routes,[{type:'field',network:'tcp,udp',outboundTag:'landing'}]);
 assert.deepEqual(savedRoutes,[{outbound:'landing'}]);
});
test('cross-kernel conversion refuses unexpressed fields and leaves originals intact',()=>{
 assert.equal(typeof api.prepareLandingSave,'function');
 const original=structuredClone(savedOut);original[0].settings.tls={enabled:true};
 assert.throws(()=>api.prepareLandingSave({outbounds:original,routes:savedRoutes,runtimeMetrics:{kernel_type:'xray'}}),/无法.*转换|不能.*转换/);
 assert.deepEqual(original[0].settings.tls,{enabled:true});
});

test('only trusted reported runtime enables Onenew features; transport resolution matches native',()=>{
 const save=(runtimeMetrics,network)=>api.prepareLandingSave({outbounds:[],routes:[],runtimeMetrics,network}).core;
 assert.equal(save({kernel_type:'xray'}),'xray');
 assert.equal(save({kernel_type:'xray',onenew_runtime:true}),'xray-caddy');
 assert.equal(save({kernel_type:'singbox',onenew_runtime:true},'xhttp'),'');
 assert.equal(save({kernel_type:'singbox'},'ws'),'singbox');
 assert.equal(save({kernel_type:'unknown',onenew_runtime:true}),'');
 assert.equal(save({kernel_type:'xray',updated_at:1}),'');
});
test('same-kernel unchanged JSON retains unknown fields and credentials verbatim',()=>{
 const out=structuredClone(savedOut);out[0].extension={unknown:['keep']};
 const routes=[{outbound:'landing',domain_suffix:['example.com'],unknown:{preserve:true}}];
 const actual=api.prepareLandingSave({outbounds:out,routes,runtimeMetrics:{kernel_type:'singbox'}});
 assert.deepEqual(actual.outbounds,out);assert.deepEqual(actual.routes,routes);assert.equal(actual.migrated,false);
});
test('cross-kernel domain suffix migration preserves domain order and refuses complex filters atomically',()=>{
 const args={outbounds:savedOut,routes:[{outbound:'landing',domain_suffix:['a.example','b.example']}],runtimeMetrics:{kernel_type:'xray'}};
 assert.deepEqual(api.prepareLandingSave(args).routes,[{type:'field',domain:['domain:a.example','domain:b.example'],outboundTag:'landing'}]);
 assert.throws(()=>api.prepareLandingSave({...args,routes:[{...args.routes[0],port:[443]}]}),/无法.*转换/);
 assert.deepEqual(args.routes,[{outbound:'landing',domain_suffix:['a.example','b.example']}]);
});

test('complex saved route scope stays explicit in JSON instead of pretending pure domain scope',()=>{
 const nodes=mount({runtimeMetrics:{kernel_type:'singbox'},routes:JSON.stringify([{outbound:'landing',domain_suffix:['example.com'],port:[443]}])}).render();
 const selected=nodes.filter(n=>n.type==='input'&&n.props.type==='checkbox'&&n.props.checked);assert.equal(selected.length,0);
 assert.match(text(nodes),/复杂.*原生 JSON/);
});
