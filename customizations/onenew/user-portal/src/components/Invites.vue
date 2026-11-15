<script setup lang="ts">
import {computed,onMounted,ref} from 'vue'
import {NAlert,NButton,NDataTable} from 'naive-ui'
import {api,stamp} from '../api'
import {energy} from '../energy'
import CopyField from './CopyField.vue'
const data=ref<any>(null),busy=ref(false),working=ref(false),error=ref('')
const rows=computed(()=>data.value?.invites||[])
const link=(path:string)=>new URL(path,location.origin).href
const columns=[{title:'受邀用户',key:'email',minWidth:220},{title:'注册时间',key:'created_at',minWidth:185,render:(r:any)=>stamp(r.created_at)}]
async function load(){busy.value=true;error.value='';try{data.value=await api('/api/me/invite')}catch(e){error.value=(e as Error).message}finally{busy.value=false}}
async function create(){working.value=true;try{await api('/api/me/invite',{method:'POST'});await load()}catch(e){error.value=(e as Error).message}finally{working.value=false}}
onMounted(load)
</script>
<template><div class="page-heading"><div><span class="eyebrow">INVITATIONS</span><h1>邀请好友</h1></div><div class="button-row"><n-button :loading="busy" @click="load">刷新</n-button><n-button type="primary" :loading="working" @click="create">生成邀请链接</n-button></div></div><n-alert v-if="error" type="error" class="space-bottom">{{error}}</n-alert><article class="surface"><h2>我的邀请链接</h2><div v-for="code in data?.codes||[]" :key="code.code"><CopyField :value="link(code.path)" label="邀请注册链接"/></div><p v-if="!data?.codes?.length" class="muted">暂无可用邀请链接，请点击生成。邀请码使用次数和数量遵循面板设置。</p><p class="muted small">已邀请 {{data?.invited_count||0}} 人</p><p v-if="data?.stat" class="muted small">奖励比例 {{data.stat[3]}}% · 累计奖励 {{energy(data.stat[1])}} · 确认中 {{energy(data.stat[2])}} · 可用奖励 {{energy(data.stat[4])}}</p></article><article class="surface"><h2>邀请记录</h2><n-data-table :columns="columns" :data="rows" :loading="busy" :bordered="false" :scroll-x="450" :pagination="{pageSize:10}"/></article></template>
