<script setup lang="ts">
import {computed,h,onMounted,ref} from 'vue'
import {NAlert,NButton,NDataTable,NModal,NPopconfirm,NSelect,NTag,useMessage} from 'naive-ui'
import {api,bytes,stamp} from '../api'
import {energy,energyName} from '../energy'
import {assertPendingRenewal} from '../renewalState'
const props=defineProps<{balance?:number}>()
const confirm=ref(false)
const emit=defineEmits<{changed:[]}>(),message=useMessage()
const rows=ref<any[]>([]),plan=ref<any>(null),expiry=ref<string|null>(null),period=ref(''),busy=ref(false),working=ref(false),error=ref(''),key=ref('')
const pending=computed(()=>rows.value.some(r=>['pending','processing'].includes(r.status)))
const selected=computed(()=>plan.value?.periods.find((p:any)=>p.period===period.value))
const labels:Record<string,string>={pending:'待确认',processing:'开通中',approved:'已生效',cancelled:'已取消',discounted:'已折抵'}
function newKey(){const b=new Uint8Array(16);crypto.getRandomValues(b);return Array.from(b,v=>v.toString(16).padStart(2,'0')).join('')}
async function load(){busy.value=true;error.value='';try{const [data,list]=await Promise.all([api('/api/me/renewal-plan'),api('/api/me/renewals')]);plan.value=data.plan;expiry.value=data.expires_at;rows.value=list;if(!plan.value?.periods.some((p:any)=>p.period===period.value))period.value=plan.value?.period||''}catch(e){error.value=(e as Error).message}finally{busy.value=false}}
async function submit(){if(working.value)return;working.value=true;error.value='';try{if(!key.value)key.value=newKey();const result=await api('/api/me/renewals',{method:'POST',body:JSON.stringify({request_id:key.value,period:period.value})});key.value='';if(result.status!=='pending'){await load();emit('changed');assertPendingRenewal(result)}confirm.value=false;message.success('续期订单已提交，等待管理员确认');await load();emit('changed')}catch(e){error.value=(e as Error).message}finally{working.value=false}}
function changePeriod(value:string){period.value=value;key.value=''}
async function cancel(row:any){if(working.value)return;working.value=true;try{await api(`/api/me/renewals/${encodeURIComponent(row.id)}/cancel`,{method:'POST',body:'{}'});key.value='';message.success('订单已取消');await load();emit('changed')}catch(e){error.value=(e as Error).message}finally{working.value=false}}
const columns=computed<any[]>(()=>[
 {title:'订单',key:'id',minWidth:180},{title:'套餐',key:'plan_name',minWidth:130},
 {title:'额度 / 周期',key:'quota_bytes',minWidth:160,render:(r:any)=>`${r.quota_bytes===null?'未知额度':bytes(r.quota_bytes)} / ${r.period_label}`},
 {title:'所需能量',key:'price_cents',width:110,render:(r:any)=>energy(r.price_cents)},
 {title:'能量抵扣',key:'balance_cents',width:110,render:(r:any)=>energy(r.balance_cents)},
 {title:'状态',key:'status',width:140,render:(r:any)=>h(NTag,{size:'small',round:true,bordered:false,type:r.status==='approved'?'success':r.status==='pending'?'warning':'default'},()=>labels[r.status]||r.status)},
 {title:'提交时间',key:'created_at',minWidth:180,render:(r:any)=>stamp(r.created_at)},
 {title:'操作',key:'actions',width:90,render:(r:any)=>r.status!=='pending'?'—':h(NPopconfirm,{onPositiveClick:()=>cancel(r)},{trigger:()=>h(NButton,{size:'small',quaternary:true,disabled:working.value},()=> '取消'),default:()=> '确定取消此订单？抵扣能量将按面板规则退回。'})}
])
onMounted(load)
</script>
<template><n-alert v-if="error" type="error" class="space-bottom">{{error}}</n-alert><article class="surface renewal-plan"><div><span class="small muted">当前套餐</span><h2>{{energyName(plan?.name)||'尚未分配套餐'}}</h2><p v-if="plan" class="muted">{{bytes(plan.quota_bytes)}} <span v-if="selected" class="renewal-price">{{selected.period_label}} · {{energy(selected.price_cents)}}</span></p><p class="small muted">{{expiry?'到期时间：'+stamp(expiry):plan?'当前无到期时间，无需续期':'请联系管理员分配套餐'}}</p><n-select v-if="plan?.periods.length&&expiry" :value="period" :options="plan.periods.map((p:any)=>({label:p.period_label+' · '+energy(p.price_cents),value:p.period}))" :disabled="working" @update:value="changePeriod" style="min-width:220px"/></div><div><n-button v-if="plan?.renew&&selected&&expiry&&!pending" type="primary" :loading="working" :disabled="busy" @click="confirm=true">申请续期</n-button><n-tag v-else-if="pending" type="warning" :bordered="false">已有待支付或处理中的订单</n-tag><span v-else class="muted small">{{plan?'当前套餐不支持续期':'暂无可续期套餐'}}</span></div></article><article class="surface"><div class="section-heading"><h2>续期申请</h2><n-button size="small" :loading="busy" @click="load">刷新</n-button></div><n-alert type="info" class="space-bottom">提交后可在这里查看订单处理情况。</n-alert><n-data-table :columns="columns" :data="rows" :loading="busy" :bordered="false" :scroll-x="1000" :pagination="{pageSize:10}"/></article><n-modal v-model:show="confirm" preset="card" title="确认申请续期" :mask-closable="!working" :close-on-esc="!working" :closable="!working" style="width:440px;max-width:calc(100vw - 32px)"><template v-if="selected"><p>{{energyName(plan?.name)}} · {{selected.period_label}}</p><p>所需能量 <strong>{{energy(selected.price_cents)}}</strong></p><p>可用能量 {{energy(props.balance)}}</p><p class="muted">按规则抵扣能量，提交后由管理员确认。</p></template><n-alert v-if="error" type="error">{{error}}</n-alert><template #footer><div class="button-row" style="justify-content:flex-end"><n-button :disabled="working" @click="confirm=false">取消</n-button><n-button type="primary" :loading="working" @click="submit">确认提交</n-button></div></template></n-modal></template>
<style scoped>.renewal-plan{display:flex;align-items:center;justify-content:space-between;gap:20px;margin-bottom:20px}.renewal-plan h2{margin:8px 0}.renewal-plan p{margin:8px 0 0}.renewal-price{margin-left:18px;color:var(--green);font-weight:600}.section-heading{margin-bottom:18px;gap:16px}.section-heading h2{margin:0}@media(max-width:650px){.renewal-plan{align-items:flex-start;flex-direction:column}}</style>
