<script setup lang="ts">
import { ref,computed,provide } from 'vue'
import { NConfigProvider,NMessageProvider,zhCN,dateZhCN,darkTheme } from 'naive-ui'
import Panel from './Panel.vue'
import {readSeason,saveSeason,seasonColors,seasonMetadata,type Season} from './season'
import {seasonContext} from './seasonContext'
const storage=(()=>{try{return localStorage}catch{return null}})()
function storedDark(){try{return storage?.getItem('onenew-theme')==='dark'}catch{return false}}
const dark=ref(storedDark()),season=ref<Season>(readSeason(storage))
function updateMeta(){
 const meta=seasonMetadata(season.value,import.meta.env.BASE_URL)
 document.title=meta.title
 document.querySelector('link[rel="icon"]')?.setAttribute('href',meta.icon)
 document.documentElement.style.setProperty('--season-cursor',`url("${meta.cursor}") 3 2`)
 document.documentElement.style.setProperty('--season-pointer',`url("${meta.pointer}") 13 4`)
 document.querySelector('meta[name="theme-color"]')?.setAttribute('content',getComputedStyle(document.documentElement).getPropertyValue('--canvas').trim())
}
function theme(){dark.value=!dark.value;try{storage?.setItem('onenew-theme',dark.value?'dark':'light')}catch{}document.documentElement.dataset.theme=dark.value?'dark':'light';updateMeta()}
function selectSeason(value:Season){season.value=value;saveSeason(storage,value);document.documentElement.dataset.season=value;updateMeta()}
provide(seasonContext,{season,selectSeason})
document.documentElement.dataset.theme=dark.value?'dark':'light'
document.documentElement.dataset.season=season.value
updateMeta()
const overrides=computed(()=>({common:{...seasonColors(season.value,dark.value),borderRadius:'10px',fontFamily:'Inter, "Microsoft YaHei", system-ui, sans-serif'},Button:{fontWeight:'600'},Card:{borderRadius:'14px'}}))
</script>
<template><n-config-provider :theme="dark?darkTheme:null" :theme-overrides="overrides" :locale="zhCN" :date-locale="dateZhCN"><n-message-provider><Panel :dark="dark" @theme="theme"/></n-message-provider></n-config-provider></template>
