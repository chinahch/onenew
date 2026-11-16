import test from 'node:test';
import assert from 'node:assert/strict';
import {ShadowsocksTransport} from './src/shadowsocks-transport.mjs';
test('SS TLS switch selects WS and persists actual protocol fields',()=>{
 const writes=[];
 const React={createElement:(type,props,...children)=>({type,props:props||{},children}),useState:init=>[init(),()=>{}],useEffect:()=>{}};
 const form={getValues:()=>({cipher:'aes-128-gcm',network:'tcp',tls:0}),setValue:(...args)=>writes.push(args)};
 const tree=ShadowsocksTransport({react:React,form});
 const nodes=[];const walk=n=>{if(!n||typeof n!=='object')return;nodes.push(n);n.children?.flat().forEach(walk)};walk(tree);
 const toggle=nodes.find(n=>n.props.role==='switch');assert.ok(toggle);
 toggle.props.onChange({target:{checked:true}});
 assert.ok(writes.some(([key,value])=>key==='network'&&value==='ws'));
 assert.ok(writes.some(([key,value])=>key==='tls'&&value===1));
});
