import test from 'node:test';
import assert from 'node:assert/strict';
import {createExitProbe,fetchExitProbe} from './src/landing-probe.mjs';
test('draft test uses selected machine and parsed credentials only in POST body',async()=>{
  const calls=[];await createExitProbe({post:(...args)=>{calls.push(args)},machineId:12,core:'singbox',outbound:{tag:'landing',protocol:'socks',settings:{password:'private'}}});
  assert.equal(calls[0][0],'/api/onenew/admin/exit-probes');assert.equal(calls[0][1].machine_id,12);assert.equal(calls[0][1].outbound.settings.password,'private');assert.deepEqual(calls[0][2],{baseURL:'/'});
});
test('missing machine/node and unsupported kernel refuse without network',()=>{
  const post=()=>{throw Error('network called')};
  assert.throws(()=>createExitProbe({post,core:'singbox',outbound:{}}),/选择/);
  assert.throws(()=>createExitProbe({post,nodeId:1,core:'unknown',outbound:{}}),/预览/);
  assert.throws(()=>fetchExitProbe(post,'../credential'),/编号/);
});
