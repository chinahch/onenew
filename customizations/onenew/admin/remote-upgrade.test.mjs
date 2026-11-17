import test from 'node:test';
import assert from 'node:assert/strict';
import {upgradeEligible,upgradePayload} from './src/remote-upgrade.mjs';
test('only capable active older machines without live jobs are eligible',()=>{
 const m={machine_id:1,is_active:true,capable:true,online:false,version:'v0.1.11'};
 assert.equal(upgradeEligible(m,'v0.1.12'),true);
 for(const changed of [{capable:false},{is_active:false},{version:'v0.1.12'},{version:'v0.2.0'},{version:null},{job:{state:'pending'}},{job:{state:'checking'}}])assert.equal(upgradeEligible({...m,...changed},'v0.1.12'),false);
 assert.equal(upgradeEligible({...m,job:{state:'rolled_back'}},'v0.1.12'),true);
});
test('bounded batch contains identifiers and fixed version only',()=>{
 assert.deepEqual(upgradePayload([1,'2',1],'v0.1.12'),{machine_ids:[1,2],version:'v0.1.12',confirmed:true});
 for(const ids of [[],[0],['bad'],Array.from({length:101},(_,i)=>i+1)])assert.throws(()=>upgradePayload(ids,'v0.1.12'));
 assert.throws(()=>upgradePayload([1],'https://example.test/command'));
});
