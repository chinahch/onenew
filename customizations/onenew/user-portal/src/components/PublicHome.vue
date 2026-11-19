<script setup lang="ts">
import {computed} from 'vue'
import {NButton,NIcon} from 'naive-ui'
import {ArrowRight,Sun,Moon,Link,ChartBar,Bolt} from '@vicons/tabler'
import BrandMark from './BrandMark.vue'
import AuthArtwork from './SeasonArtwork.vue'
import SeasonSelector from './SeasonSelector.vue'
import {seasonCopy,seasonIdentity} from '../season'
import {useSeason} from '../seasonContext'
const {season}=useSeason()
const identity=computed(()=>seasonIdentity[season.value]),copy=computed(()=>seasonCopy[season.value])
defineProps<{dark:boolean}>();defineEmits(['login','theme'])
</script>
<template>
 <div class="public-home">
  <header class="public-header">
   <a href="#" class="brand"><BrandMark/>{{identity.name}}</a>
   <nav><a href="#features">功能介绍</a><a href="#getting-started">开始使用</a></nav>
   <div class="button-row"><SeasonSelector/><n-button circle quaternary :title="dark?'浅色模式':'深色模式'" @click="$emit('theme')"><n-icon :component="dark?Sun:Moon"/></n-button><n-button type="primary" round @click="$emit('login')">登录账户</n-button></div>
  </header>
  <main>
   <section class="public-hero">
    <div class="hero-copy">
     <span class="warm-kicker"><span/> {{identity.english}} · {{identity.name}}</span>
     <h1>更从容地，<br><em>连接每一天。</em></h1>
     <p>把订阅、用量与服务放在一起。<br>{{identity.intro}}</p>
     <div class="button-row hero-actions"><n-button type="primary" size="large" round @click="$emit('login')">进入{{identity.name}} <template #icon><n-icon :component="ArrowRight"/></template></n-button><a class="hero-secondary" href="#features">了解{{identity.name}} <span>↗</span></a></div>
     <div class="hero-footnote"><span class="warm-line"/> 一处管理，轻松出发</div>
    </div>
    <AuthArtwork/>
   </section>
   <section id="features" class="public-features">
    <div class="public-section-heading"><span class="warm-kicker">SIMPLE &amp; {{identity.english.split(' ')[0]}}</span><h2>日常所需，清楚就好。</h2><p>服务状态、订阅和用量，各有各的位置。</p></div>
    <div class="public-feature-grid">
     <article><span class="feature-icon"><n-icon :component="Link"/></span><h3>订阅，随身带走</h3><p>在账户中查看与复制订阅，连接你常用的客户端。</p></article>
     <article><span class="feature-icon"><n-icon :component="ChartBar"/></span><h3>用量，心中有数</h3><p>剩余流量、有效期和节点状态，打开就能看见。</p></article>
     <article><span class="feature-icon"><n-icon :component="Bolt"/></span><h3>能量，按需补充</h3><p>自定义申请补充能量，管理员确认后到账。</p></article>
    </div>
   </section>
   <section id="getting-started" class="public-start">
    <div><span class="warm-kicker">YOUR NEXT CHAPTER</span><h2>下一程，从{{identity.name}}开始。</h2><p>登录账户，继续你的服务。</p></div>
    <n-button type="primary" size="large" round @click="$emit('login')">登录{{identity.name}} <template #icon><n-icon :component="ArrowRight"/></template></n-button>
   </section>
  </main>
  <footer class="public-footer"><a href="#" class="brand"><BrandMark/>{{identity.name}}</a><span>{{copy.tagline}}</span><small>{{identity.name}} · {{identity.english}}</small></footer>
 </div>
</template>
