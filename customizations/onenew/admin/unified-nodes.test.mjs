import test from 'node:test';import assert from 'node:assert/strict';
import {fetchManagedNodes,saveManagedOrder} from './src/unified-nodes.mjs';
test('external nodes join the actual table with separate IDs and no invented telemetry',async()=>{
 const rows=await fetchManagedNodes(async()=>({data:[{id:1,name:'native'}]}),async()=>({data:[{id:1,name:'external',type:'vless',host:'example.test',port:443,enabled:true,group_ids:[2],user_ids:[9]}],table_order:[-1,1]}));
 assert.equal(rows[0].id,-1);assert.equal(rows[0].onenew_external_id,1);assert.equal(rows[0].online,null);assert.equal(rows[1].id,1);assert.ok(!('password' in rows[0]));
});
test('mixed ordering uses its own validated endpoint instead of sending negative IDs to native bulk APIs',async()=>{const calls=[];await saveManagedOrder(async(...v)=>calls.push(v),[{id:-2,order:1},{id:1,order:2}]);assert.deepEqual(calls,[['/api/onenew/admin/external-nodes/table-order',{ids:[-2,1]},{baseURL:'/'}]])});
