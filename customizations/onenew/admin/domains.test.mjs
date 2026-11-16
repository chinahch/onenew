import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import {normalizeFrontendDomains} from './src/user-domains.mjs';
import {patchBundle} from './patch.mjs';
test('origins normalize and deduplicate, empty value clears list',()=>{
 assert.deepEqual(normalizeFrontendDomains('https://A.example/\nhttps://a.example:443\nhttp://b.example:80'),['https://a.example','http://b.example']);
 assert.deepEqual(normalizeFrontendDomains('  \n'),[]);
});
test('invalid origin cannot be saved',()=>{
 for(const value of ['https://a.example/path','https://a.example/a/..','https://a.example?','https://a.example#x','https://u:p@a.example','https://@a.example','https://a.example\\path','javascript:alert(1)','https://*.example.com',Array(21).fill('https://a.example').join('\n')]) assert.throws(()=>normalizeFrontendDomains(value));
});
test('native site form retains the field in schema and places it after site URL',()=>{
 const source=fs.readFileSync(path.join(process.env.ONENEW_ADMIN_UPSTREAM,'assets/index-CEIYH7i8.js'),'utf8');
 const result=patchBundle(source,'');
 assert.match(result,/user_frontend_urls:gy\(cy\(\)\)\.default\(\[\]\)/);
 const site=result.slice(result.indexOf('function cZt('),result.indexOf('function cZt(')+10000);
 assert.ok(site.indexOf('name:"app_url"')<site.indexOf('OnenewAdmin.UserDomainsField'));
 assert.ok(site.indexOf('OnenewAdmin.UserDomainsField')<site.indexOf('name:"force_https"'));
});
