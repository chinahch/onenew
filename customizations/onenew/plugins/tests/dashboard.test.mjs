import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';
test('dashboard renders hostile audit strings as text and never interprets markup',async()=>{
  class Element{
    constructor(tag){this.tag=tag;this.children=[];this.listeners={};this.value='';this.dataset={};this.textContent='';}
    append(...children){this.children.push(...children)}replaceChildren(...children){this.children=children}
    addEventListener(name,callback){this.listeners[name]=callback}
    set innerHTML(_){throw new Error('Untrusted HTML sink used')}
  }
  const nodes=Object.fromEntries(['app','rows','status','summary','view','search','previous','next','refresh'].map(id=>[id,new Element(id)]));
  nodes.app.dataset.api='/api/v2/test/subscription-guard';nodes.view.value='logs';
  const hostile='<img src=x onerror=alert(1)>';
  const context={document:{getElementById:id=>nodes[id],createElement:tag=>new Element(tag)},localStorage:{getItem:()=>JSON.stringify({value:'Bearer fake-admin-token'})},URL,URLSearchParams,window:{location:{origin:'https://example.invalid'},confirm:()=>false},fetch:async()=>({ok:true,json:async()=>({data:[{id:1,email:hostile,ip:'192.0.2.1',user_agent:hostile,created_at:'2026-11-14'}],total:1})})};
  vm.runInNewContext(fs.readFileSync(new URL('../SubscriptionGuard/resources/assets/dashboard.js',import.meta.url),'utf8'),context);
  await new Promise(resolve=>setTimeout(resolve,10));
  const all=[];const walk=node=>{all.push(node);node.children?.forEach(walk)};walk(nodes.rows);
  assert.ok(all.some(n=>n.textContent===hostile));assert.ok(!all.some(n=>n.tag==='img'));assert.equal(nodes.next.disabled,true);
});
