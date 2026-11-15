<script setup lang="ts">
import {computed,h,onMounted,ref} from 'vue'
import {NAlert,NButton,NDataTable,NTag} from 'naive-ui'
import {api,bytes,stamp} from '../api'
import {energy,energyName} from '../energy'
import Renewals from './Renewals.vue'
defineProps<{me:any}>()
const emit=defineEmits<{changed:[]}>(),rows=ref<any[]>([]),busy=ref(false),error=ref('')
async function load(){busy.value=true;error.value='';try{rows.value=await api('/api/me/orders')}catch(e){error.value=(e as Error).message}finally{busy.value=false}}
async function changed(){await load();emit('changed')}
const labels:Record<string,string>={pending:'待确认',processing:'开通中',approved:'已生效',cancelled:'已取消',discounted:'已折抵'}
const columns=computed(()=>[{title:'订单',key:'id',minWidth:180},{title:'套餐',key:'plan_name',minWidth:150,render:(r:any)=>energyName(r.plan_name)},{title:'额度',key:'quota_bytes',minWidth:120,render:(r:any)=>r.quota_bytes===null?'未知':bytes(r.quota_bytes)},{title:'周期',key:'period_label',minWidth:95},{title:'所需能量',key:'price_cents',minWidth:110,render:(r:any)=>energy(r.price_cents)},{title:'能量抵扣',key:'balance_cents',minWidth:110,render:(r:any)=>energy(r.balance_cents)},{title:'状态',key:'status',minWidth:140,render:(r:any)=>h(NTag,{size:'small',bordered:false},()=>labels[r.status]||r.status)},{title:'创建时间',key:'created_at',minWidth:185,render:(r:any)=>stamp(r.created_at)}])
onMounted(load)
</script>
<template><div class="page-heading"><div><span class="eyebrow">ORDERS</span><h1>订单与续期</h1></div><n-button :loading="busy" @click="load">刷新</n-button></div><n-alert v-if="error" type="error" class="space-bottom">{{error}}</n-alert><Renewals :balance="me.balance_cents" @changed="changed"/><article class="surface" style="margin-top:20px"><h2>全部订单</h2><n-data-table :columns="columns" :data="rows" :loading="busy" :bordered="false" :scroll-x="1100" :pagination="{pageSize:10}"/></article></template>
