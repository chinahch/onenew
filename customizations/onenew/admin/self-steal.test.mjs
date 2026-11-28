import test from 'node:test';
import assert from 'node:assert/strict';
import {validSelfStealDomain,selfStealPayload} from './src/self-steal.mjs';

test('validSelfStealDomain normalizes and validates domains',()=>{
  assert.equal(validSelfStealDomain(' Blog.Example.COM '),'blog.example.com');
  assert.equal(validSelfStealDomain('bad domain!'),'');
  assert.equal(validSelfStealDomain('localhost'),'');
});

test('selfStealPayload validates install domain and port',()=>{
  assert.deepEqual(selfStealPayload('install','Blog.Example.COM','8443'),{action:'install',domain:'blog.example.com',port:8443});
  assert.deepEqual(selfStealPayload('restart'),{action:'restart'});
  assert.deepEqual(selfStealPayload('uninstall'),{action:'uninstall'});
  assert.throws(()=>selfStealPayload('install','invalid','8443'));
  assert.throws(()=>selfStealPayload('install','blog.example.com','80'));
  assert.throws(()=>selfStealPayload('exec'));
});
