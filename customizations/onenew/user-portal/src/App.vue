<script setup lang="ts">
import { ref,computed } from 'vue'
import { NConfigProvider,NMessageProvider,zhCN,dateZhCN,darkTheme } from 'naive-ui'
import Panel from './Panel.vue'
const dark=ref(localStorage.getItem('onenew-theme')==='dark')
function theme(){dark.value=!dark.value;localStorage.setItem('onenew-theme',dark.value?'dark':'light');document.documentElement.dataset.theme=dark.value?'dark':'light'}
document.documentElement.dataset.theme=dark.value?'dark':'light'
const overrides=computed(()=>({common:{primaryColor:dark.value?'#e3a371':'#a6532b',primaryColorHover:dark.value?'#efb889':'#bd6a3e',primaryColorPressed:dark.value?'#d58d58':'#894021',borderRadius:'10px',fontFamily:'Inter, "Microsoft YaHei", system-ui, sans-serif'},Button:{fontWeight:'600'},Card:{borderRadius:'14px'}}))
</script>
<template><n-config-provider :theme="dark?darkTheme:null" :theme-overrides="overrides" :locale="zhCN" :date-locale="dateZhCN"><n-message-provider><Panel :dark="dark" @theme="theme"/></n-message-provider></n-config-provider></template>
