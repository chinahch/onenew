import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';
import {patchBundle} from './patch.mjs';
const source=fs.readFileSync(process.env.ONENEW_ADMIN_UPSTREAM?`${process.env.ONENEW_ADMIN_UPSTREAM}/assets/index-CEIYH7i8.js`:new URL('../../../../xboard-admin-dist/assets/index-CEIYH7i8.js',import.meta.url),'utf8');
const h3={host:'example.com',path:'/',mode:'auto',alpn:'h3',extra:{headers:{},xPaddingBytes:'100-1000',noGRPCHeader:false,noSSEHeader:false}};
const xhttp={host:'example.com',path:'/',mode:'packet-up',alpn:'h2',extra:{headers:{},xPaddingBytes:'100-1000',noGRPCHeader:false,noSSEHeader:false}};
function editor(value,network='xhttp'){
 const patched=patchBundle(source,''),a=patched.indexOf('function m4t('),b=patched.indexOf('const f4t=',a);
 const state=[true,JSON.stringify(value),null],saved=[];let cursor=0;
 const ctx={H:{useState:initial=>{const i=cursor++;return [state[i],v=>state[i]=v]},useEffect:()=>{}},Q:{jsx:(type,props)=>({type,props}),jsxs:(type,props)=>({type,props})},jy:()=>({t:(key,args)=>key==='network_settings.use_template'?`使用${args.template}模板`:key}),gE:{error:message=>assert.fail(message)}};
 for(const name of ['htt','gtt','xtt','ptt','mtt','_tt','aZt','ftt'])ctx[name]=name;
 const render=vm.runInNewContext(`(${patched.slice(a,b)})`,ctx);
 return {state,saved,render(){cursor=0;const tree=render({value,setValue:v=>saved.push(v),templateType:network});const nodes=[];function walk(n){if(!n||typeof n!=='object')return;nodes.push(n);for(const x of [].concat(n.props?.children||[]))walk(x)}walk(tree);return nodes;}};
}
test('XHTTP editor exposes H3 and XHTTP templates and saves edited H3 JSON',()=>{
 const e=editor({path:'/original'});let nodes=e.render();
 assert.deepEqual(nodes.filter(n=>n.type==='xtt'&&n.props.variant==='outline'&&n.props.size==='sm').map(n=>n.props.children),['使用XHTTP模板','使用H3模板']);
 nodes.find(n=>n.props.children==='使用H3模板').props.onClick();
 assert.deepEqual(JSON.parse(e.state[1]),h3);
 nodes=e.render();const input=nodes.find(n=>n.type==='aZt');const edited={...h3,host:'edited.example',path:'/custom'};input.props.onChange({target:{value:JSON.stringify(edited)}});
 nodes=e.render();nodes.find(n=>n.props.children==='common.confirm').props.onClick();
 assert.deepEqual(JSON.parse(JSON.stringify(e.saved)),[edited]);
 assert.equal(e.state[0],false);
});
test('H3 template is confined to XHTTP transport',()=>{
 const nodes=editor({},'ws').render();
 assert.equal(nodes.some(n=>n.props.children==='使用H3模板'),false);
});

test('XHTTP template uses packet-up and h2 and remains editable before save',()=>{
 const e=editor({path:'/old'});let nodes=e.render();
 nodes.find(n=>n.props.children==='使用XHTTP模板').props.onClick();
 assert.deepEqual(JSON.parse(e.state[1]),xhttp);
 nodes=e.render();const edited={...xhttp,host:'custom.example',path:'/editable'};
 nodes.find(n=>n.type==='aZt').props.onChange({target:{value:JSON.stringify(edited)}});
 nodes=e.render();nodes.find(n=>n.props.children==='common.confirm').props.onClick();
 assert.deepEqual(JSON.parse(JSON.stringify(e.saved)),[edited]);
});
