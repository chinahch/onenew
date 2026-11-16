import test from 'node:test';import assert from 'node:assert/strict';
import {backupSettingsPayload,backupTime,backupDriveUrl,CloudBackup} from './src/cloud-backup.mjs';
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
