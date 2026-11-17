import fs from 'node:fs/promises';
import path from 'node:path';
import {fileURLToPath,pathToFileURL} from 'node:url';
import {createHash} from 'node:crypto';
import {patchBundle} from './patch.mjs';
import {fingerprintAssets} from './fingerprint-assets.mjs';
import contract from './upstream.json' with {type:'json'};

const here=path.dirname(fileURLToPath(import.meta.url));
const argv=process.argv.slice(2),index=argv.indexOf('--upstream-dir');
if(argv.length&&!(argv.length===2&&index===0))throw new Error('用法：npm run build -- --upstream-dir /path/to/official-admin');
const upstream=path.resolve(index>=0?argv[index+1]:process.env.ONENEW_ADMIN_UPSTREAM||path.join(here,'../../../../xboard-admin-dist'));
const out=path.join(here,'dist');
if(upstream===out||upstream.startsWith(out+path.sep))throw new Error('upstream 目录不能位于 dist 内');
for(const [file,expected] of Object.entries(contract.files)){
  const bytes=await fs.readFile(path.join(upstream,file));
  if(createHash('sha256').update(bytes.toString('utf8').replace(/\r\n/g,'\n')).digest('hex')!==expected)throw new Error(`上游文件 SHA256 不匹配：${file}`);
}
let esbuild;
if(process.env.ONENEW_ESBUILD_MODULE)esbuild=await import(pathToFileURL(path.resolve(process.env.ONENEW_ESBUILD_MODULE)).href);
else esbuild=await import('esbuild');
if(esbuild.version!=='0.28.2')throw new Error('需要 esbuild 0.28.2');
const modules=new Map(await Promise.all(['component.mjs','ai-operations.mjs','attention.mjs','rules.mjs','traffic.mjs','landing-state.mjs','landing-probe.mjs','resources.mjs','migration.mjs','cloud-backup.mjs','web-restore.mjs','energy.mjs','unified-nodes.mjs','bandwidth.mjs','subscription-logs.mjs','landing-library.mjs','user-domains.mjs','upgrade-command.mjs','remote-upgrade.mjs','shadowsocks-transport.mjs','catalog.json'].map(async file=>[file,await fs.readFile(path.join(here,'src',file),'utf8')])));
modules.set('component.mjs',modules.get('component.mjs')+"\nexport * from './traffic.mjs';\nexport * from './resources.mjs';\nexport * from './migration.mjs';\nexport * from './energy.mjs';\nexport * from './unified-nodes.mjs';\nexport * from './bandwidth.mjs';\nexport * from './subscription-logs.mjs';\nexport * from './landing-library.mjs';\nexport * from './upgrade-command.mjs';\nexport * from './user-domains.mjs';\nexport * from './shadowsocks-transport.mjs';\n");
modules.set('component.mjs',modules.get('component.mjs')+"\nexport * from './attention.mjs';\n");
modules.set('component.mjs',modules.get('component.mjs')+"\nexport * from './ai-operations.mjs';\n");
// A virtual namespace prevents dependency/tsconfig walks outside the customization tree.
modules.set('component.mjs',modules.get('component.mjs')+"\nexport * from './remote-upgrade.mjs';\n");
const runtime=await esbuild.build({entryPoints:['component.mjs'],bundle:true,write:false,format:'iife',globalName:'OnenewAdmin',target:'es2022',minify:true,legalComments:'inline',tsconfigRaw:{},plugins:[{
  name:'onenew-local-source',setup(build){
    build.onResolve({filter:/.*/},args=>{const name=args.path.replace(/^\.\//,'');if(!modules.has(name))throw new Error(`意外依赖：${args.path}`);return {path:name,namespace:'onenew'}});
    build.onLoad({filter:/.*/,namespace:'onenew'},args=>({contents:modules.get(args.path),loader:args.path.endsWith('.json')?'json':'js'}));
  }
}]});
const bundlePath='assets/index-CEIYH7i8.js';
const patched=patchBundle(await fs.readFile(path.join(upstream,bundlePath),'utf8'),runtime.outputFiles[0].text);
await esbuild.transform(patched,{loader:'js',target:'es2022'}); // Parse the complete patched module before writing outputs.
await fs.mkdir(out,{recursive:true});
await fs.cp(upstream,out,{recursive:true,filter:p=>!p.split(path.sep).includes('.git')});
await fs.writeFile(path.join(out,bundlePath),patched);
const css='assets/index-DiYa-_z_.css';
await fs.writeFile(path.join(out,css),(await fs.readFile(path.join(upstream,css),'utf8'))+'\n'+await fs.readFile(path.join(here,'src/style.css'),'utf8')+'\n'+await fs.readFile(path.join(here,'src/resources.css'),'utf8')+'\n'+await fs.readFile(path.join(here,'src/landing-library.css'),'utf8')+'\n'+await fs.readFile(path.join(here,'src/ai-operations.css'),'utf8'));
const published=await fingerprintAssets(out);
await fs.writeFile(path.join(out,'onenew-build.json'),JSON.stringify({upstreamCommit:contract.commit,upstreamBundleSha256:contract.files[bundlePath],patchedBundleSha256:createHash('sha256').update(patched).digest('hex'),...published,esbuild:esbuild.version},null,2)+'\n');
console.log(`Onenew official-admin customization built: ${out}`);

