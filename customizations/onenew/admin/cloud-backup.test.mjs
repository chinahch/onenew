import test from 'node:test';import assert from 'node:assert/strict';
import {backupSettingsPayload,backupTime,backupDriveUrl,backupBytes,BackupStorageUsage,CloudBackup} from './src/cloud-backup.mjs';
test('daily time and optional blank secrets are preserved in save payload',()=>{
 const p=backupSettingsPayload({enabled:true,daily_time:'23:45',client_id:'own-client',folder_id:'folder',client_secret:'',refresh_token:'',password:'',password_confirmation:''});
 assert.equal(p.daily_time,'23:45');assert.equal(p.client_secret,'');assert.equal(p.password,'');assert.throws(()=>backupSettingsPayload({...p,daily_time:'24:00'}));assert.throws(()=>backupSettingsPayload({...p,password:'long-password',password_confirmation:'different'}));
});
test('backup dates always use Beijing time and file links stay on Drive',()=>{
 assert.ok(backupTime(Date.UTC(2026,10,16,19,0)/1000).includes('03:00'));assert.equal(backupDriveUrl('https://evil.test/file'),null);assert.equal(backupDriveUrl('javascript:alert(1)'),null);assert.equal(backupDriveUrl('https://drive.google.com/file/d/fixture-id/view'),'https://drive.google.com/file/d/fixture-id/view');
});
test('opening the component never posts credentials or starts an upload',()=>{
 const effects=[],calls=[];const R={createElement:(type,props,...children)=>({type,props:{...props,children}}),useState:v=>[typeof v==='function'?v():v,()=>{}],useRef:v=>({current:v}),useEffect:f=>effects.push(f)};
 const tree=CloudBackup({react:R,get:async()=>({config:{daily_time:'03:00'}}),post:(...v)=>calls.push(v)});
 assert.equal(calls.length,0);assert.equal(tree.type,'section');assert.ok(effects.length>0);
});
test('storage sizes distinguish zero and missing values and label an unavailable dbstat',()=>{
 assert.equal(backupBytes(0),'0.00 MiB');assert.equal(backupBytes(1048576),'1.00 MiB');assert.equal(backupBytes(null),'—');assert.equal(backupBytes(-1),'—');
 const R={createElement:(type,props,...children)=>({type,props,children})};
 const tree=BackupStorageUsage({react:R,usage:{checked_at:1,total_bytes:100,complete:false,plugins:{bytes:0},theme:{bytes:0},database:{main_bytes:100,wal_bytes:0,shm_bytes:0,free_bytes:0,table_sizes_available:false,tables:[]}}});
 const text=JSON.stringify(tree);assert.ok(text.includes('至少 '));assert.ok(text.includes('dbstat'));assert.ok(text.includes('不重复计入合计'));
});
test('storage checks run only on explicit clicks, use GET and do not overwrite unsaved settings',async()=>{
 const states=[],refs=[],calls=[],effects=[];let stateIndex=0,refIndex=0;
 const R={Fragment:'fragment',createElement:(type,props,...children)=>({type,props:{...props,children}}),useState:init=>{const index=stateIndex++;if(!(index in states))states[index]=typeof init==='function'?init():init;return [states[index],value=>{states[index]=typeof value==='function'?value(states[index]):value}]},useRef:value=>refs[refIndex++]??(refs[refIndex-1]={current:value}),useEffect:effect=>{if(!effects.length)effects.push(effect)}};
 const initial={config:{enabled:false,daily_time:'03:00',ready:false}};
 const get=async url=>{calls.push(url);return url.endsWith('/storage-usage')?{checked_at:1,total_bytes:123}:initial};
 const render=()=>{stateIndex=0;refIndex=0;return CloudBackup({react:R,get,post:()=>{throw new Error('size checks must not POST')}})};
 let tree=render();const cleanup=effects[0]();
 try{
  await new Promise(resolve=>setImmediate(resolve));tree=render();assert.deepEqual(calls,['/api/onenew/admin/cloud-backup']);
  function find(node,predicate){if(!node||typeof node!=='object')return null;if(predicate(node))return node;for(const child of node.props?.children?.flat(Infinity)||[]){const found=find(child,predicate);if(found)return found}return null}
  const time=find(tree,node=>node.type==='input'&&node.props.type==='time');time.props.onChange({target:{value:'04:30'}});tree=render();
  const button=find(tree,node=>node.type==='button'&&node.props.children.includes('检查当前数据大小'));assert.equal(button.props.disabled,false);await button.props.onClick();
  assert.equal(calls.filter(url=>url.endsWith('/storage-usage')).length,1);assert.equal(states[1].daily_time,'04:30');
  tree=render();const display=find(tree,node=>node.type===BackupStorageUsage);assert.equal(display.props.usage.total_bytes,123);
 }finally{cleanup()}
});
