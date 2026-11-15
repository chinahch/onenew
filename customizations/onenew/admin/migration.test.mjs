import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import {restoreCommand,startMigration,downloadMigration} from './src/migration.mjs';
import {patchBundle} from './patch.mjs';
test('migration password stays in POST body and invalid confirmations never enqueue',async()=>{
 const calls=[];await startMigration(async(...args)=>calls.push(args),'private-migration-pass','private-migration-pass');
 assert.deepEqual(calls,[['/api/onenew/admin/migration/exports',{password:'private-migration-pass',password_confirmation:'private-migration-pass'},{baseURL:'/'}]]);
 assert.throws(()=>startMigration(()=>assert.fail(),'short','short'));
 assert.throws(()=>startMigration(()=>assert.fail(),'private-migration-pass','wrong'));
 assert.throws(()=>startMigration(()=>assert.fail(),'private\nmigration-pass','private\nmigration-pass'));
});
test('restore command pins source revision and has no password',()=>{
 const command=restoreCommand('a'.repeat(40),'onenew-migration-11111111-2222-4333-8444-555555555555.onm');
 assert.ok(command.includes('/'+'a'.repeat(40)+'/customizations/onenew/migration/restore.sh'));
 assert.ok(command.includes('--target \'/opt/onenew\''));assert.ok(!command.includes('--password'));
 assert.throws(()=>restoreCommand('master','../evil'));assert.throws(()=>restoreCommand('a'.repeat(40),'file; rm.onm'));
});
test('download ticket goes through a form POST rather than URL or browser-sized blob',()=>{
 const before=globalThis.document,calls=[],form={style:{},appendChild(v){calls.push(v)},submit(){calls.push({method:this.method,action:this.action})},remove(){}};
 globalThis.document={createElement:type=>type==='form'?form:{},body:{appendChild(){}}};
 try{downloadMigration('b'.repeat(64));assert.equal(calls[0].name,'ticket');assert.equal(calls[0].value,'b'.repeat(64));assert.deepEqual(calls[1],{method:'POST',action:'/api/onenew/migration/download'})}finally{globalThis.document=before}
});
test('system settings includes a dedicated migration route and navigation entry',()=>{
 const original=fs.readFileSync(process.env.ONENEW_ADMIN_UPSTREAM?`${process.env.ONENEW_ADMIN_UPSTREAM}/assets/index-CEIYH7i8.js`:new URL('../../../../xboard-admin-dist/assets/index-CEIYH7i8.js',import.meta.url),'utf8');const result=patchBundle(original,'');
 assert.ok(result.includes('href:"/config/system/migration"'));assert.ok(result.includes('OnenewAdmin.MigrationPage,{react:H,get:IL,post:RL}'));
});
