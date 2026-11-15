<script setup lang="ts">
import { computed,defineAsyncComponent,onMounted,onUnmounted,ref } from 'vue'
import { NAlert,NButton,NDropdown,NIcon,NInput,NSpin,NTag,useMessage } from 'naive-ui'
import { Dashboard,Users,LayersLinked as Layers,Link,Sun,Moon,ArrowRight,Mail,Lock,User,Apps,Bolt } from '@vicons/tabler'
import { api,ApiError,login as nativeLogin,logout as nativeLogout } from './api'
import { createAccountState } from './account'
import BrandMark from './components/BrandMark.vue'
import AuthArtwork from './components/AuthArtwork.vue'
import PublicHome from './components/PublicHome.vue'
import {initialPublicView} from './publicView'
import { inviteCode } from './featureState'
const UserDashboard=defineAsyncComponent(()=>import('./components/UserDashboard.vue'))
const Usage=defineAsyncComponent(()=>import('./components/Usage.vue'))
const Subscription=defineAsyncComponent(()=>import('./components/Subscription.vue'))
const Orders=defineAsyncComponent(()=>import('./components/Orders.vue'))
const Energy=defineAsyncComponent(()=>import('./components/Energy.vue'))
const Invites=defineAsyncComponent(()=>import('./components/Invites.vue'))
const NodeHealth=defineAsyncComponent(()=>import('./components/NodeHealth.vue'))
defineProps<{dark:boolean}>();defineEmits(['theme'])
const account=createAccountState<any>();const me=account.user,zone=account.timezone
const loading=ref(true),error=ref(''),page=ref('overview'),email=ref(''),password=ref(''),signing=ref(false),mobile=ref(false),accountError=ref('');let accountTimer:ReturnType<typeof setInterval>|undefined
const message=useMessage()
const emailCode=ref(''),emailRequired=ref(false),sendingCode=ref(false)
const publicView=ref(initialPublicView(location.hash,location.search))
function showLogin(){publicView.value='login';history.pushState(null,'','#login')}
function showHome(){publicView.value='home';history.pushState(null,'',location.pathname+location.search)}
function syncPublicView(){publicView.value=initialPublicView(location.hash,location.search)}
const customerShell=computed(()=>true)
const customerTabs=[{key:'overview',label:'仪表盘',icon:Dashboard},{key:'subscription',label:'我的订阅',icon:Link},{key:'orders',label:'套餐续期',icon:Layers},{key:'energy',label:'补充能量',icon:Bolt},{key:'invite',label:'邀请好友',icon:Users}]
const moreOptions=[{label:'节点在线情况',key:'connectivity'},{label:'流量明细',key:'usage'}]
const profileOptions=computed(()=>[{label:me.value?.email||'',key:'email',disabled:true},...(me.value?.admin_path?[{label:'管理后台',key:'admin'}]:[]),{label:'退出登录',key:'logout'}])
function profileAction(key:string){if(key==='logout')logout();else if(key==='admin'&&me.value?.admin_path)location.assign(me.value.admin_path)}
const invitation=inviteCode(location.search),registering=ref(!!invitation),inviteValid=ref(false),inviteLoading=ref(!!invitation),registrationNotice=ref('')
async function verifyInvite(){if(!invitation)return;inviteLoading.value=true;try{const data=await api('/api/invites/'+encodeURIComponent(invitation));inviteValid.value=data.valid===true;emailRequired.value=data.email_verify===true;if(data.captcha_enable){inviteValid.value=false;error.value='此站点已启用人机验证，当前用户界面暂不支持此注册方式，请联系管理员。'}else if(!inviteValid.value)error.value='邀请链接已失效。'}catch(e){error.value=(e as Error).message;inviteValid.value=false}finally{inviteLoading.value=false}}
async function sendCode(){sendingCode.value=true;try{await api('/api/v1/passport/comm/sendEmailVerify',{method:'POST',body:JSON.stringify({email:email.value})});message.success('验证码已发送，请检查邮箱')}catch(e){error.value=(e as Error).message}finally{sendingCode.value=false}}
async function register(){if(!inviteValid.value||signing.value)return;signing.value=true;error.value='';try{await api('/api/register',{method:'POST',body:JSON.stringify({email:email.value,password:password.value,email_code:emailCode.value,invite_code:invitation})});password.value='';registering.value=false;registrationNotice.value='账号已注册，请登录。'}catch(e){error.value=(e as Error).message}finally{signing.value=false}}
async function load(){loading.value=true;error.value='';try{await account.refresh(()=>api('/api/me'))}catch(e){if(e instanceof ApiError&&(e.status===401||e.status===403))me.value=null;else error.value=(e as Error).message}finally{loading.value=false}}
async function login(){if(signing.value)return;signing.value=true;error.value='';try{await nativeLogin(email.value,password.value);password.value='';await load()}catch(e){error.value=(e as Error).message}finally{signing.value=false}}
async function logout(){try{await nativeLogout();me.value=null;page.value='overview';}catch(e){message.error((e as Error).message)}finally{me.value=null;page.value='overview'}}
async function refreshAccount(){if(!me.value)return;try{await account.refresh(()=>api('/api/me'));accountError.value=''}catch(e){if(e instanceof ApiError&&(e.status===401||e.status===403))me.value=null;else accountError.value='账户信息刷新失败，能量可能滞后：'+(e as Error).message}}
function select(key:string){page.value=key;mobile.value=false;refreshAccount()}
function sessionChanged(){me.value=null;page.value='overview';accountError.value=''}
onMounted(()=>{window.addEventListener('onenew-session',sessionChanged);window.addEventListener('popstate',syncPublicView);window.addEventListener('hashchange',syncPublicView);load();verifyInvite();accountTimer=setInterval(refreshAccount,30000)});onUnmounted(()=>{clearInterval(accountTimer);window.removeEventListener('onenew-session',sessionChanged);window.removeEventListener('popstate',syncPublicView);window.removeEventListener('hashchange',syncPublicView)})
</script>
<template>
 <div v-if="loading" class="screen-center"><n-spin size="large"/><span>正在连接暖秋</span></div>
 <PublicHome v-else-if="!me&&publicView==='home'" :dark="dark" @login="showLogin" @theme="$emit('theme')"/>
 <div v-else-if="!me" class="auth-page">
  <a class="auth-back" href="#" @click.prevent="showHome">← 暖秋首页</a>
  <AuthArtwork/>
  <n-button class="auth-theme" circle secondary :title="dark?'浅色模式':'深色模式'" @click="$emit('theme')"><n-icon :component="dark?Sun:Moon"/></n-button>
  <section class="auth-card"><a class="brand auth-brand" href="#"><BrandMark/>暖秋</a><h1>{{registering?'创建账户':'登录账户'}}</h1><p class="muted auth-subtitle">{{registering?'使用邀请加入暖秋':'欢迎回来，开启温暖的一天'}}</p>
  <n-alert v-if="registrationNotice" type="success" class="space-bottom">{{registrationNotice}}</n-alert><n-alert v-if="registering&&(!inviteValid||inviteLoading)" type="info" class="space-bottom">{{inviteLoading?'正在验证邀请…':'此邀请不可用，请联系邀请人。'}}</n-alert><n-alert v-if="error" type="error" class="space-bottom">{{error}}</n-alert>
  <form class="auth-form" @submit.prevent="registering?register():login()"><label for="login-email">邮箱</label><n-input v-model:value="email" type="text" placeholder="请输入邮箱地址" size="large" :input-props="{id:'login-email',type:'email',autocomplete:'username',required:true}"><template #prefix><n-icon :component="Mail"/></template></n-input><label for="login-password">密码</label><n-input v-model:value="password" type="password" show-password-on="click" placeholder="请输入密码" size="large" :input-props="{id:'login-password',autocomplete:registering?'new-password':'current-password',required:true,minlength:registering?12:undefined}"><template #prefix><n-icon :component="Lock"/></template></n-input><template v-if="registering&&emailRequired"><label for="email-code">邮箱验证码</label><n-input v-model:value="emailCode" placeholder="请输入邮箱验证码" :input-props="{id:'email-code',autocomplete:'one-time-code',required:true,pattern:'[0-9]{6}'}"/><n-button :loading="sendingCode" :disabled="!email.trim()" @click="sendCode">发送验证码</n-button></template><n-button type="primary" size="large" block attr-type="submit" :loading="signing" :disabled="registering&&(!inviteValid||inviteLoading)">{{registering?'创建账户':'登录'}} <template #icon><n-icon :component="ArrowRight"/></template></n-button></form><p v-if="registering" class="small muted auth-note">密码至少 12 个字符</p><n-button v-if="invitation" class="auth-register" secondary block @click="registering=!registering;error=''">{{registering?'已有账户，返回登录':'使用邀请创建账户'}}</n-button></section>
 </div>
 <div v-else class="app-shell" :class="{'customer-shell':customerShell}">
  <div class="main-wrap"><header v-if="customerShell" class="customer-topbar"><a class="brand customer-brand" href="#" @click.prevent="select('overview')"><BrandMark/>暖秋</a><nav class="customer-nav"><button v-for="item in customerTabs" :key="item.key" :class="{active:page===item.key}" @click="select(item.key)"><n-icon :component="item.icon" size="18"/><span>{{item.label}}</span></button><n-dropdown trigger="click" :options="moreOptions" @select="select"><button :class="{active:['connectivity','usage'].includes(page)}"><n-icon :component="Apps" size="18"/><span>更多</span></button></n-dropdown></nav><div class="customer-tools"><n-button circle secondary :title="dark?'浅色模式':'深色模式'" @click="$emit('theme')"><n-icon :component="dark?Sun:Moon"/></n-button><n-dropdown trigger="click" :options="profileOptions" @select="profileAction"><n-button circle secondary title="账户菜单"><n-icon :component="User"/></n-button></n-dropdown></div></header>
  <main class="main-content"><n-alert v-if="accountError" type="warning" class="space-bottom">{{accountError}}</n-alert><UserDashboard v-if="page==='overview'&&customerShell" :me="me" :zone="zone" @navigate="select"/><Usage v-else-if="page==='usage'" :me="me" :zone="zone"/><NodeHealth v-else-if="page==='connectivity'"/><Orders v-else-if="page==='orders'" :me="me" @changed="refreshAccount"/><Energy v-else-if="page==='energy'" :me="me" @changed="refreshAccount"/><Invites v-else-if="page==='invite'"/><Subscription v-else :me="me" :zone="zone" @renew="select('orders')"/></main><footer class="main-footer"><span>暖秋 · 让连接多一点温度</span></footer></div>
 </div>
</template>
