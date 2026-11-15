import test from 'node:test';import assert from 'node:assert/strict';import {reviewEnergy} from './src/energy.mjs';
test('energy review submits the decision to the protected endpoint and never a balance',async()=>{const calls=[];await reviewEnergy(async(...v)=>calls.push(v),'11111111-2222-4333-8444-555555555555','approved','fixture');assert.deepEqual(calls,[['/api/onenew/admin/energy/11111111-2222-4333-8444-555555555555/review',{decision:'approved',note:'fixture'},{baseURL:'/'}]]);assert.throws(()=>reviewEnergy(()=>assert.fail(),'invalid','approved'))});
import {EnergyRequests} from './src/energy.mjs';
test('initial energy request omits empty search and remains refreshable',async()=>{
 const effects=[],calls=[];
 const React={createElement:(tag,props,...children)=>({tag,props,children}),useState:v=>[v,()=>{}],useRef:v=>({current:v}),useEffect:fn=>effects.push(fn)};
 const tree=EnergyRequests({react:React,get:async(...args)=>{calls.push(args);return {data:[],last_page:1}},post:async()=>{}});
 assert.match(tree.props.className,/onenew-data-page/);
 const cleanup=effects[0]();await new Promise(resolve=>setTimeout(resolve,240));cleanup();
 assert.deepEqual(calls[0],['/api/onenew/admin/energy',{baseURL:'/',params:{page:1,status:'pending'}}]);
});
