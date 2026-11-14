import fs from 'node:fs/promises';
import path from 'node:path';
import {fileURLToPath} from 'node:url';
import {spawnSync} from 'node:child_process';
import {parseLink} from './src/rules.mjs';

const here=path.dirname(fileURLToPath(import.meta.url));
const index=process.argv.indexOf('--module-dir');
if(index<0||!process.argv[index+1])throw new Error('Usage: node native-check.mjs --module-dir /pinned/caddy/source');
const moduleDir=path.resolve(process.argv[index+1]),cache=path.join(here,'.native-cache');
const mod=await fs.readFile(path.join(moduleDir,'go.mod'),'utf8');
if(!mod.includes('github.com/cedar2025/Xray-core v0.0.0-20260409213332-f47935539965'))throw new Error('Unexpected Xray core pin');
await fs.mkdir(cache,{recursive:true});
await fs.writeFile(path.join(cache,'go.mod'),mod);
await fs.copyFile(path.join(moduleDir,'go.sum'),path.join(cache,'go.sum'));
const prefix='vless://00000000-0000-4000-8000-000000000001@exit.example:20200?';
const enc='mlkem768x25519plus.native.0rtt.AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA';
const fixtures=[
  prefix+`encryption=${enc}&flow=xtls-rprx-vision&security=reality&sni=addons.mozilla.org&fp=chrome&pbk=AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA&sid=7d460428&spx=%2Fprobe%3Fx%3D1&type=tcp&headerType=none`,
  prefix+'type=xhttp&mode=stream-up&path=%2Fedge&host=cdn.example&security=tls&sni=tls.example&fp=chrome&alpn=h2',
  prefix+'type=xhttp&path=%2Fedge&security=reality&pbk=AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA&sid=abcd&sni=tls.example&fp=chrome',
  prefix+'type=ws&path=%2Fedge&host=cdn.example&security=tls&sni=tls.example',
  prefix+'type=grpc&serviceName=edge&security=tls&sni=tls.example',
  prefix+'security=tls&pcs='+('ab'.repeat(32))+'&vcn=tls.example',
  'trojan://example-password@exit.example:443?security=tls&sni=tls.example'
].map(link=>parseLink(link,'xray-caddy','landing'));
const result=spawnSync('go',['run','-mod=mod','-modfile='+path.join(cache,'go.mod'),path.join(here,'native-check/main.go')],{
  cwd:moduleDir,env:{...process.env,GOWORK:'off',GOPROXY:'off',GOSUMDB:'off'},input:JSON.stringify(fixtures),encoding:'utf8',maxBuffer:1024*1024
});
if(result.status!==0){process.stderr.write(result.stderr||result.error?.message||'Native compile failed');process.exit(1)}
process.stdout.write(result.stdout);
