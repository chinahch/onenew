import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs/promises';
import os from 'node:os';
import path from 'node:path';
import {fingerprintAssets} from './fingerprint-assets.mjs';

async function fixture(root,script){
  await fs.mkdir(path.join(root,'assets'),{recursive:true});
  await fs.writeFile(path.join(root,'assets/index-old.js'),script);
  await fs.writeFile(path.join(root,'assets/index-old.css'),'body{color:blue}');
  await fs.writeFile(path.join(root,'assets/worker.js'),'/* stable relative worker */');
  await fs.writeFile(path.join(root,'manifest.json'),JSON.stringify({'index.html':{isEntry:true,file:'assets/index-old.js',css:['assets/index-old.css']}}));
  await fs.writeFile(path.join(root,'index.html'),'<script src="./assets/index-old.js"></script><link href="./assets/index-old.css">');
}

test('changed admin code gets a fresh URL in both Laravel manifest and static entry',async()=>{
  const temp=await fs.mkdtemp(path.join(os.tmpdir(),'onenew-assets-'));
  try{
    const first=path.join(temp,'a'),second=path.join(temp,'b');
    await fixture(first,'const parser="old";');await fixture(second,'const parser="fixed";');
    const a=await fingerprintAssets(first),b=await fingerprintAssets(second);
    assert.notEqual(a.bundlePath,b.bundlePath);
    assert.equal(a.cssPath,b.cssPath);
    const manifest=JSON.parse(await fs.readFile(path.join(second,'manifest.json'),'utf8'));
    assert.equal(manifest['index.html'].file,b.bundlePath);
    assert.deepEqual(manifest['index.html'].css,[b.cssPath]);
    assert.match(await fs.readFile(path.join(second,'index.html'),'utf8'),new RegExp(b.bundlePath));
    assert.equal(await fs.readFile(path.join(second,b.bundlePath),'utf8'),'const parser="fixed";');
    assert.equal(await fs.readFile(path.join(second,'assets/worker.js'),'utf8'),'/* stable relative worker */');
    await assert.rejects(fs.stat(path.join(second,'assets/index-old.js')),{code:'ENOENT'});
  }finally{await fs.rm(temp,{recursive:true,force:true})}
});
