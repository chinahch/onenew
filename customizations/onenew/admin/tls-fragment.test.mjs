import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import {fileURLToPath} from 'node:url';
import {TlsFragment,validFragmentRange} from './src/tls-fragment.mjs';
import {patchBundle} from './patch.mjs';

test('fragment length and interval accept only bounded ordered ranges',()=>{
 for(const value of ['1','100-200','16384'])assert.equal(validFragmentRange(value,1,16384),true);
 for(const value of ['0','200-100','16385','1,20','1-20\n','-1','1;exit','1-999999'])assert.equal(validFragmentRange(value,1,16384),false);
 assert.equal(validFragmentRange('0',0,1000),true);
 assert.equal(validFragmentRange('1001',0,1000),false);
});
test('the switch is off by default, writes client settings only and rejects non-TLS transport',()=>{
 const state={tls:1,network:'tcp',tls_settings:{server_name:'tls.example'}};
 const writes=[];
 const R={createElement:(type,props,...children)=>({type,props:props||{},children}),useState:init=>[init(),()=>{}],useEffect:()=>{}};
 const form={getValues:()=>state,setValue:(...args)=>writes.push(args)};
 const walk=node=>!node||typeof node!=='object'?[]:[node,...(node.children||[]).flat(Infinity).flatMap(walk)];
 let tree=TlsFragment({react:R,form});
 let toggle=walk(tree).find(node=>node.props.role==='switch');
 assert.equal(toggle.props.checked,false);
 toggle.props.onChange({target:{checked:true}});
 assert.deepEqual(writes.map(([key,value])=>[key,value]),[
  ['tls_settings.fragment.length','40-60'],['tls_settings.fragment.interval','30-50'],['tls_settings.fragment.enabled',true],
 ]);
 assert.equal(state.tls_settings.server_name,'tls.example');
 state.network='quic';tree=TlsFragment({react:R,form});
 assert.equal(walk(tree).find(node=>node.props.role==='switch').props.disabled,true);
 state.tls=0;assert.equal(TlsFragment({react:R,form}),null);
});
test('real protocol forms retain fragment settings in validation and render the control for three TLS protocols only',()=>{
 const here=path.dirname(fileURLToPath(import.meta.url));
 const upstream=process.env.ONENEW_ADMIN_UPSTREAM||path.resolve(here,'../../../../xboard-admin-dist');
 const source=fs.readFileSync(path.join(upstream,'assets/index-CEIYH7i8.js'),'utf8');
 const patched=patchBundle(source,'');
 assert.equal((patched.match(/Q\.jsx\(OnenewAdmin\.TlsFragment,/g)||[]).length,3);
 for(const schema of ['C4t','A4t','W4t']){
  assert.match(patched,new RegExp(`${schema}=\\(e,t\\)=>py\\(\\{tls:yy\\(\\)\\.default\\([01]\\),tls_settings:py\\(\\{fragment:py`));
 }
 assert.ok(patched.includes('OnenewAdmin.validFragmentRange(e,1,16384)'));
 assert.ok(patched.includes('OnenewAdmin.validFragmentRange(e,0,1000)'));
});
