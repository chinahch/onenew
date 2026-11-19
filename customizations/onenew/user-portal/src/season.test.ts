import test from 'node:test'
import assert from 'node:assert/strict'
import {isSeason,readSeason,saveSeason,seasonColors,seasonCopy,seasonIdentity,seasonMetadata,seasonKey,seasons} from './season.ts'

test('autumn remains the default for new, unavailable and invalid preferences',()=>{
 assert.equal(readSeason(null),'autumn')
 assert.equal(readSeason({getItem:()=>null}),'autumn')
 assert.equal(readSeason({getItem:()=>'<invalid>'}),'autumn')
 assert.equal(readSeason({getItem(){throw new Error('Storage blocked')}}),'autumn')
 assert.equal(isSeason('spring'),true)
 assert.equal(isSeason('dark'),false)
})

test('season selection persists independently from the existing light and dark preference',()=>{
 const values=new Map([['onenew-theme','dark']])
 const storage={getItem:(key:string)=>values.get(key)??null,setItem:(key:string,value:string)=>{values.set(key,value)}}
 for(const season of seasons){
  saveSeason(storage,season)
  assert.equal(readSeason(storage),season)
  assert.equal(values.get(seasonKey),season)
  assert.equal(values.get('onenew-theme'),'dark')
 }
 assert.doesNotThrow(()=>saveSeason({setItem(){throw new Error('Storage blocked')}},'summer'))
})

test('autumn retains its exact existing Naive UI colors in both modes',()=>{
 assert.deepEqual(seasonColors('autumn',false),{primaryColor:'#a6532b',primaryColorHover:'#bd6a3e',primaryColorPressed:'#894021'})
 assert.deepEqual(seasonColors('autumn',true),{primaryColor:'#e3a371',primaryColorHover:'#efb889',primaryColorPressed:'#d58d58'})
 assert.equal(new Set(seasons.map(season=>seasonColors(season,false).primaryColor)).size,4)
 for(const season of seasons){assert.notEqual(seasonColors(season,false).primaryColor,seasonColors(season,true).primaryColor)}
})

test('season artwork and footer copy vary together while autumn retains its original text',()=>{
 assert.deepEqual(seasonCopy.autumn,{tagline:'让连接，多一点温度。',badge:'订阅 · 能量 · 每一天',footer:'暖秋 · 让连接多一点温度'})
 assert.equal(new Set(seasons.map(season=>seasonCopy[season].tagline)).size,4)
 assert.equal(new Set(seasons.map(season=>seasonCopy[season].badge)).size,4)
 for(const season of seasons){assert.ok(seasonCopy[season].footer.endsWith(seasonCopy[season].tagline.replace('，','').replace('。','')))}
})

test('tab titles and icons follow the selected identity and deployment base path',()=>{
 for(const season of seasons){
  const meta=seasonMetadata(season,'/onenew-user/')
  assert.ok(meta.title.startsWith(seasonIdentity[season].name+' · '))
  assert.equal(meta.icon,`/onenew-user/icons/${season}.svg`)
  assert.equal(meta.cursor,`/onenew-user/cursors/${season}.svg`)
  assert.equal(meta.pointer,`/onenew-user/cursors/${season}-pointer.svg`)
 }
 assert.equal(seasonMetadata('winter','/onenew-user').icon,'/onenew-user/icons/winter.svg')
 assert.equal(seasonMetadata('autumn','/').title,'暖秋 · 让连接多一点温度')
})
