import test from 'node:test';
import assert from 'node:assert/strict';
import {attentionCount} from './src/attention.mjs';
test('attention dots reflect only actionable counts and clear at zero',()=>{
 assert.equal(attentionCount({orders:2,energy:1},'all'),3);
 assert.equal(attentionCount({orders:0,energy:1},'orders'),0);
 assert.equal(attentionCount({energy:0},'energy'),0);
 for(const value of [-1,'3',NaN,Infinity])assert.equal(attentionCount({energy:value},'energy'),0);
});
