import test from 'node:test'
import assert from 'node:assert/strict'
import {energy,energyAmount,energyName} from './energy.ts'
test('energy preserves exact cents and rejects malformed request quantities',()=>{
 assert.equal(energy(1234),'12.34 能量');assert.equal(energyAmount('12.34'),'12.34');
 for(const v of ['0','-1','1.001','1e3','1000000.01'])assert.throws(()=>energyAmount(v));
 assert.equal(energyName('10元套餐'),'10能量套餐');
})

import {renewalMessage} from './renewalState.ts'
test('covered renewals report activation rather than a false approval error',()=>{
 assert.match(renewalMessage({status:'approved'}),/已生效/)
 assert.match(renewalMessage({status:'pending'}),/需补充/)
 assert.match(renewalMessage({status:'processing'}),/开通/)
 assert.throws(()=>renewalMessage({status:'cancelled'}))
})
