import test from 'node:test';
import assert from 'node:assert/strict';
import {fetchNodeUsers,formatNodeBytes,shiftIsoDate,NodeUsersTrigger} from './src/node-users.mjs';
import {managedColumns} from './src/unified-nodes.mjs';

test('formatNodeBytes formats byte counts cleanly',()=>{
  assert.equal(formatNodeBytes(0),'0 B');
  assert.equal(formatNodeBytes(512),'512 B');
  assert.equal(formatNodeBytes(1536),'1.50 KB');
  assert.equal(formatNodeBytes(1073741824),'1.00 GB');
  assert.equal(formatNodeBytes(-1),'—');
});

test('shiftIsoDate shifts calendar dates safely in UTC',()=>{
  assert.equal(shiftIsoDate('2026-11-28',-1),'2026-11-27');
  assert.equal(shiftIsoDate('2026-03-01',-1),'2026-02-28');
  assert.equal(shiftIsoDate('invalid',-1),'');
});

test('fetchNodeUsers validates serverId and sends query parameters',async()=>{
  const calls=[];
  await fetchNodeUsers({get:async(...args)=>{calls.push(args);return {}},serverId:40,date:'2026-11-28',page:2,perPage:25});
  assert.deepEqual(calls,[['/api/onenew/admin/traffic/node-users',{baseURL:'/',params:{server_id:40,page:2,per_page:25,date:'2026-11-28'}}]]);
  assert.throws(()=>fetchNodeUsers({get:async()=>({}),serverId:0}));
});

test('managedColumns replaces native online cell with NodeUsersTrigger while preserving external nodes',()=>{
  const fakeReact={createElement:(type,props)=>({type,props})};
  const cols=managedColumns([{accessorKey:'online',cell:()=>'native-cell'}],fakeReact,()=>{},()=>{},async()=>({}));
  const nativeEl=cols[0].cell({row:{original:{id:40,name:'JP',online:2}}});
  assert.equal(nativeEl.type,NodeUsersTrigger);
  assert.equal(nativeEl.props.node.id,40);
  const extEl=cols[0].cell({row:{original:{id:-1,onenew_external_id:1,name:'Ext'}}});
  assert.notEqual(extEl.type,NodeUsersTrigger);
});
