import test from 'node:test'
import assert from 'node:assert/strict'
import {nodeAvailability,automaticHealthView} from './serverCards.ts'
import {calendarRange} from './calendar.ts'
import {subscriptionState} from './featureState.ts'
test('subscription URL remains absolute and stable across refresh',async()=>{
 const state=subscriptionState(),url='https://subscription.example.invalid/s/test_only'
 await state.load(async()=>({path:url,needs_recovery:false}));assert.equal(state.path,url)
 await state.load(async()=>({path:url,needs_recovery:false}));assert.equal(state.path,url)
})
test('proxy success is derived from real fresh probe regardless of host heartbeat',()=>{
 const now=Date.now(),probe={state:'succeeded',finished_at:new Date(now-1000).toISOString()}
 assert.equal(nodeAvailability({latest_probe:probe},now).group,'online')
 assert.equal(nodeAvailability({machine_last_report_at:new Date(now).toISOString()},now).group,'pending')
 assert.equal(nodeAvailability({latest_probe:{...probe,finished_at:new Date(now-601000).toISOString()}},now).group,'pending')
 assert.equal(nodeAvailability({latest_probe:{state:'succeeded'}},now).group,'pending')
 assert.equal(automaticHealthView({state:'failed',finished_at:probe.finished_at},now).tone,'error')
})
test('calendar filters use selected zone and date boundaries',()=>{
 assert.deepEqual(calendarRange('Asia/Shanghai',1,0,new Date('2026-01-31T23:00:00Z')),{from:'2026-02-01',to:'2026-02-01'})
 assert.deepEqual(calendarRange('UTC',2,0,new Date('2024-03-01T12:00:00Z')),{from:'2024-02-29',to:'2024-03-01'})
})
