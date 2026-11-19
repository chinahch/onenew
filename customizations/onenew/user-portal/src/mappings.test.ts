import test from 'node:test'
import assert from 'node:assert/strict'
import {nodeAvailability,automaticHealthView} from './serverCards.ts'
import {calendarRange} from './calendar.ts'
import {probeEntryDetail,probeLandingDetail,probeLabel,subscriptionState} from './featureState.ts'
test('subscription URL remains absolute and stable across refresh',async()=>{
 const state=subscriptionState(),url='https://subscription.example.invalid/s/test_only'
 await state.load(async()=>({path:url,needs_recovery:false}));assert.equal(state.path,url)
 await state.load(async()=>({path:url,needs_recovery:false}));assert.equal(state.path,url)
})
test('proxy success is derived from real fresh probe regardless of host heartbeat',()=>{
 const now=Date.now(),probe={state:'succeeded',health_state:'online',finished_at:new Date(now-1000).toISOString()}
 assert.equal(nodeAvailability({latest_probe:probe},now).group,'online')
 assert.equal(nodeAvailability({machine_last_report_at:new Date(now).toISOString()},now).group,'pending')
 assert.equal(nodeAvailability({latest_probe:{...probe,finished_at:new Date(now-601000).toISOString()}},now).group,'pending')
 assert.equal(nodeAvailability({latest_probe:{state:'succeeded'}},now).group,'pending')
 assert.equal(automaticHealthView({state:'failed',health_state:'node_fault',finished_at:probe.finished_at},now).tone,'error')
})
test('calendar filters use selected zone and date boundaries',()=>{
 assert.deepEqual(calendarRange('Asia/Shanghai',1,0,new Date('2026-01-31T23:00:00Z')),{from:'2026-02-01',to:'2026-02-01'})
 assert.deepEqual(calendarRange('UTC',2,0,new Date('2024-03-01T12:00:00Z')),{from:'2024-02-29',to:'2024-03-01'})
})

test('legacy success stays unknown and staged landing labels remain specific',()=>{
 const now=Date.now(),finished_at=new Date(now).toISOString();
 assert.equal(automaticHealthView({state:'succeeded',finished_at},now).tone,'default');
 assert.equal(nodeAvailability({latest_probe:{state:'succeeded',health_state:'partial_landing_fault',finished_at}},now).text,'部分落地故障');
});

test('v2 display separates original CDN fallback local and landing evidence',()=>{
 const primary={state:'failed',error_code:'timeout'},fallback={state:'succeeded',error_code:''};
 const p={state:'succeeded',health_state:'online',stages:{version:2,entry:{source:'cdn_roundtrip',primary,fallback},landings:[]}};
 assert.equal(probeLabel(p),'备用入口正常');assert.equal(probeEntryDetail(p),'原CDN异常，备用 ip.sb:443 正常');
 p.stages.entry.primary={state:'succeeded',error_code:''};assert.equal(probeLabel(p),'原CDN正常');
 p.stages.entry.source='local_listener_roundtrip';assert.equal(probeEntryDetail(p),'本地监听正常');
 assert.equal(probeLandingDetail({state:'failed',error_code:'transport_failed'}),'落地故障');
 assert.equal(probeLandingDetail({state:'expired',error_code:'expired'}),'落地未完成检测');
});
