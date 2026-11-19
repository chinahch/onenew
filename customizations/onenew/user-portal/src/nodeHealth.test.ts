import test from 'node:test'
import assert from 'node:assert/strict'
import {probeLandingSummary,type Probe} from './featureState.ts'
import {nodeAvailability} from './serverCards.ts'

const landing=(state:string,error_code='')=>({id:'private-id',name:'Private landing identity',state,error_code})
const probe=(landings:ReturnType<typeof landing>[]):Probe=>({state:'succeeded',health_state:'online',stages:{version:2,landings}})

test('user landing summary hides identities and reports failure when any tested landing fails',()=>{
 assert.equal(probeLandingSummary(null),'')
 assert.equal(probeLandingSummary(probe([])),'')
 assert.equal(probeLandingSummary(probe([landing('succeeded'),landing('succeeded')])),'落地正常')
 assert.equal(probeLandingSummary(probe([landing('succeeded'),landing('failed','timeout')])),'落地故障')
 assert.equal(probeLandingSummary(probe([landing('failed','configuration_unavailable')])),'落地未完成检测')
 assert.equal(probeLandingSummary(probe([landing('succeeded'),landing('expired','expired')])),'落地未完成检测')
 assert.doesNotMatch(probeLandingSummary(probe([landing('succeeded')])),/Private|private-id/)
})

test('the three existing status groups retain success, failure and pending freshness semantics',()=>{
 const now=Date.parse('2026-11-20T05:31:00+08:00')
 const fresh={state:'succeeded',created_at:new Date(now-1000).toISOString()}
 assert.equal(nodeAvailability({latest_probe:{...fresh,health_state:'online'}},now).group,'online')
 for(const health_state of ['node_fault','landing_fault','partial_landing_fault']){
  assert.equal(nodeAvailability({latest_probe:{...fresh,health_state}},now).group,'abnormal')
 }
 assert.equal(nodeAvailability({latest_probe:null},now).group,'pending')
 assert.equal(nodeAvailability({latest_probe:{...fresh,health_state:'online',created_at:new Date(now-601000).toISOString()}},now).group,'pending')
})
