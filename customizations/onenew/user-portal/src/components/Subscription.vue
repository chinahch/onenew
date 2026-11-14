<script setup lang="ts">
import {computed,onMounted,ref} from 'vue'
import {NAlert,NButton,NIcon,NPopconfirm,useMessage} from 'naive-ui'
import {Link,Refresh} from '@vicons/tabler'
import {api,bytes,stamp} from '../api'
import {cycleStamp} from '../featureState'
import CopyField from './CopyField.vue'
defineEmits<{renew:[]}>();defineProps<{me:any;zone?:string}>()
const path=ref(''),busy=ref(false),loading=ref(false),error=ref(''),message=useMessage()
const link=computed(()=>{if(!path.value)return '';try{const url=new URL(path.value,location.origin);return ['https:','http:'].includes(url.protocol)?url.href:''}catch{return ''}})
async function load(){loading.value=true;error.value='';try{path.value=(await api('/api/me/subscription')).path}catch(e){error.value=(e as Error).message}finally{loading.value=false}}
async function rotate(){if(busy.value)return;busy.value=true;try{path.value=(await api('/api/me/subscription-token',{method:'POST'})).path;message.success('订阅链接及连接凭据已重置')}catch(e){error.value=(e as Error).message}finally{busy.value=false}}
onMounted(load)
</script>
<template><div class="page-heading"><div><span class="eyebrow">SUBSCRIPTION</span><h1>我的订阅</h1><p>原订阅链接会在刷新、重新登录后保持显示。</p></div><div class="button-row"><n-button type="primary" @click="$emit('renew')">续期套餐</n-button><n-button :loading="loading" @click="load">刷新</n-button></div></div><section class="stat-grid three"><article class="stat-card"><span class="stat-label">本周期已用</span><strong>{{bytes(me.used_bytes)}}</strong><small>已应用节点计费倍率</small></article><article class="stat-card"><span class="stat-label">剩余额度</span><strong>{{bytes(Math.max(me.quota_bytes-me.used_bytes,0))}}</strong><small>本周期额度 {{bytes(me.quota_bytes)}}</small></article><article class="stat-card"><span class="stat-label">下次流量重置</span><strong class="date-stat">{{me.next_reset_at?cycleStamp(me.next_reset_at,zone||'UTC'):'无计划重置'}}</strong><small>{{zone||'面板时区'}} · 到期 {{me.expires_at?stamp(me.expires_at):'不限'}}</small></article></section><article class="surface subscription-card"><span class="subscription-icon"><n-icon :component="Link" size="30"/></span><h2>客户端订阅链接</h2><p class="muted">支持面板已配置的协议，请使用支持对应协议及版本的客户端。</p><n-alert type="info" class="space-bottom">订阅链接等同于访问凭据，请勿转发。可用节点由套餐、有效期、剩余流量和管理员配置决定。</n-alert><n-alert v-if="error" type="error" class="space-bottom">{{error}}</n-alert><CopyField v-if="link" :value="link" label="客户端订阅链接"/><p v-else-if="!loading" class="muted">暂无可用订阅链接，请联系管理员。</p><n-popconfirm @positive-click="rotate"><template #trigger><n-button type="primary" :loading="busy" :disabled="loading||!link"><template #icon><n-icon :component="Refresh"/></template>重置订阅链接</n-button></template>这会重置订阅链接及连接 UUID，旧链接和客户端连接凭据将失效，需要重新导入订阅。确定继续？</n-popconfirm><p class="small muted">链接由面板提供，仅在你明确重置时更换。</p></article></template>
