import fs from 'node:fs/promises';
import path from 'node:path';
import {createHash} from 'node:crypto';

// The patched bundle cannot retain the upstream hash: browsers/CDNs would use
// its old bytes after a successful deployment. Keep chunks in the same folder.
export async function fingerprintAssets(root){
  const manifest=JSON.parse(await fs.readFile(path.join(root,'manifest.json'),'utf8'));
  const entry=manifest['index.html'];
  if(!entry?.file||!Array.isArray(entry.css)||entry.css.length!==1)throw new Error('Unexpected pinned admin entry');
  const aliases=new Map();
  for(const file of [entry.file,...entry.css]){
    if(!/^assets\/[A-Za-z0-9_-]+\.(js|css)$/.test(file))throw new Error('Unexpected admin asset path');
    const bytes=await fs.readFile(path.join(root,file));
    const version=createHash('sha256').update(bytes).digest('hex').slice(0,24);
    const target=`assets/onenew-${version}${path.extname(file)}`;
    await fs.rename(path.join(root,file),path.join(root,target));
    aliases.set(file,target);
  }
  const rewrite=value=>typeof value==='string'?(aliases.get(value)||value):Array.isArray(value)?value.map(rewrite):
    value&&typeof value==='object'?Object.fromEntries(Object.entries(value).map(([key,item])=>[key,rewrite(item)])):value;
  await fs.writeFile(path.join(root,'manifest.json'),JSON.stringify(rewrite(manifest),null,2)+'\n');
  let html=await fs.readFile(path.join(root,'index.html'),'utf8');
  for(const [file,target] of aliases)html=html.replaceAll(file,target);
  await fs.writeFile(path.join(root,'index.html'),html);
  return {bundlePath:aliases.get(entry.file),cssPath:aliases.get(entry.css[0])};
}
