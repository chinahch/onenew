import test from 'node:test';
import assert from 'node:assert/strict';
import {CloudflaredTunnel,extractTunnelToken,tunnelPayload} from './src/cloudflared-tunnel.mjs';

test('extractTunnelToken accepts raw token or cloudflared install command and rejects shell injection',()=>{
  const tok='eyJhIjoi'+'AbCdEf0123456789'.repeat(4)+'==';
  assert.equal(extractTunnelToken(tok),tok);
  assert.equal(extractTunnelToken(`sudo cloudflared service install ${tok}`),tok);
  assert.equal(extractTunnelToken(`cloudflared tunnel run --token ${tok}`),tok);
  assert.equal(extractTunnelToken(`${tok}; rm -rf /`),'');
  assert.equal(extractTunnelToken('short'),'');
  assert.deepEqual(tunnelPayload('install',`sudo cloudflared service install ${tok}`),{action:'install',token:tok});
  assert.deepEqual(tunnelPayload('restart'),{action:'restart'});
  assert.throws(()=>tunnelPayload('install','bad;id'));
});

test('CloudflaredTunnel modal loads state and submits validated install payload',async()=>{
  const values=[],refs=[],effects=[],deps=[],cleanups=[],posts=[];
  let c=0,rc=0,ec=0;
  const R={Fragment:'fragment',createElement:(type,props,...children)=>({type,props:props||{},children:children.flat(Infinity)}),
    useState:init=>{const s=c++;if(!(s in values))values[s]=init;return [values[s],next=>{values[s]=typeof next==='function'?next(values[s]):next}]},
    useRef:init=>refs[rc++]??(refs[rc-1]={current:init}),
    useEffect:(fn,d)=>{const s=ec++;if(JSON.stringify(deps[s])!==JSON.stringify(d)){deps[s]=d;effects.push(fn)}}};
  const props={react:R,machineId:4,machineName:'raksmart-cn2',
    get:async()=>({data:{machine_id:4,name:'raksmart-cn2',is_active:true,capable:true,online:true,installed:false,running:false,version:null,arch:'amd64',job:null}}),
    post:async(...args)=>posts.push(args)};
  const render=()=>{c=0;rc=0;ec=0;return CloudflaredTunnel(props)};
  const visit=n=>!n||typeof n!=='object'?[]:[n,...(n.children||[]).flatMap(visit)];
  const btn=(tree,label)=>visit(tree).find(n=>n.type==='button'&&n.children.includes(label));

  let tree=render();
  btn(tree,'隧道').props.onClick({});
  tree=render();
  for(const fn of effects.splice(0))cleanups.push(fn());
  await new Promise(r=>setImmediate(r));
  tree=render();

  const tok='eyJhIjoi'+'AbCdEf0123456789'.repeat(4)+'==';
  const input=visit(tree).find(n=>n.props['aria-label']==='Cloudflare Tunnel Token');
  input.props.onChange({target:{value:`sudo cloudflared service install ${tok}`}});
  tree=render();
  await btn(tree,'安装 / 更新 Token').props.onClick();
  assert.deepEqual(posts[0],['/api/onenew/admin/tunnels/4',{action:'install',token:tok},{baseURL:'/'}]);
  cleanups.forEach(fn=>fn?.());
});
