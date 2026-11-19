<script setup lang="ts">
import AuthArtwork from './AuthArtwork.vue'
import {useSeason} from '../seasonContext'
import {seasonLabels,seasonCopy,seasonIdentity} from '../season'
const {season}=useSeason()
</script>
<template>
 <AuthArtwork v-if="season==='autumn'"/>
 <figure v-else class="autumn-art season-art" :aria-label="seasonLabels[season]+'主题面板与移动设备插画'">
  <svg viewBox="0 0 680 570" fill="none" role="img" :aria-label="seasonLabels[season]+'主题插画'">
   <circle cx="330" cy="270" r="230" fill="var(--art-halo)"/>
   <circle cx="515" cy="116" r="59" fill="var(--art-sun)"/>
   <g v-if="season==='spring'" class="art-decoration">
    <path d="M75 132c-12-27 9-48 29-41-3 25-14 35-29 41Zm-4 26c0-22 6-40 18-53M598 405c2-26 13-38 29-36-1 22-11 33-29 36Z" fill="var(--art-signal)"/>
    <path d="M119 460c-18-18-33 4-16 13-21 5-14 29 5 20-3 21 23 23 26 4 15 14 32-6 15-18 23-5 13-30-4-20-2-21-27-19-26 1Z" fill="var(--art-flower)" stroke="none"/>
    <circle cx="126" cy="478" r="7" fill="var(--art-sun)" stroke="none"/>
   </g>
   <g v-else-if="season==='summer'" class="art-decoration">
    <path d="m515 38 0-14m-55 35-10-10m120 10 10-10m-15 114 12 10M76 462q20-18 40 0t40 0M56 481q20-18 40 0t40 0M596 386q12-12 24 0t24 0"/>
    <circle cx="88" cy="135" r="17" stroke="var(--art-phone)"/>
    <circle cx="613" cy="292" r="8" fill="var(--art-phone)" stroke="none"/>
   </g>
   <g v-else class="art-decoration">
    <path d="M88 105v48m-21-36 42 24m-42 0 42-24m-21-12-7 7m7-7 7 7m-7 41-7-7m7 7 7-7M600 362v48m-21-36 42 24m-42 0 42-24M111 469v30m-13-23 26 16m-26 0 26-16"/>
    <circle cx="184" cy="72" r="5" fill="var(--art-phone)" stroke="none"/>
    <circle cx="605" cy="221" r="5" fill="var(--art-phone)" stroke="none"/>
   </g>
   <rect x="48" y="91" width="540" height="346" rx="29" fill="var(--surface)"/>
   <rect x="62" y="105" width="512" height="317" rx="20" fill="var(--art-screen-top)"/>
   <path d="M62 315c130-75 277-90 512-10v97a20 20 0 0 1-20 20H82a20 20 0 0 1-20-20Z" fill="var(--art-screen-bottom)"/>
   <circle cx="86" cy="127" r="5" fill="var(--art-phone)"/>
   <circle cx="103" cy="127" r="5" fill="var(--art-sun)"/>
   <circle cx="120" cy="127" r="5" fill="var(--art-signal)"/>
   <text x="89" y="170" fill="#fff" font-size="18" font-family="sans-serif">{{seasonIdentity[season].name}}</text>
   <rect x="86" y="190" width="156" height="72" rx="13" fill="#fff" fill-opacity=".12"/>
   <path d="M104 210h62m-62 25h110M107 359l28-19 26 6 29-36 27 10" stroke="var(--art-signal)" stroke-width="5" stroke-linecap="round"/>
   <rect x="86" y="278" width="156" height="113" rx="13" fill="#fff" fill-opacity=".09"/>
   <rect x="262" y="158" width="287" height="233" rx="19" fill="var(--surface)"/>
   <path d="M286 184h106m-106 21h168" stroke="var(--line)" stroke-width="7" stroke-linecap="round"/>
   <rect x="286" y="286" width="38" height="79" rx="8" fill="var(--art-halo)"/>
   <rect x="337" y="254" width="38" height="111" rx="8" fill="var(--art-phone)"/>
   <rect x="388" y="276" width="38" height="89" rx="8" fill="var(--green)"/>
   <rect x="439" y="234" width="38" height="131" rx="8" fill="var(--art-signal)"/>
   <rect x="490" y="265" width="38" height="100" rx="8" fill="var(--art-halo)"/>
   <path d="M271 438v29m-58 8h145" stroke="var(--art-screen-bottom)" stroke-width="12" stroke-linecap="round"/>
   <rect x="408" y="229" width="172" height="295" rx="33" fill="var(--art-screen-top)"/>
   <rect x="417" y="239" width="154" height="275" rx="25" fill="var(--surface)"/>
   <path d="M472 250h45" stroke="var(--line)" stroke-width="5" stroke-linecap="round"/>
   <rect x="431" y="271" width="126" height="111" rx="15" fill="var(--art-screen-bottom)"/>
   <text x="445" y="300" fill="#fff" font-size="17" font-family="sans-serif">{{seasonIdentity[season].name}}</text>
   <path d="M445 322h65m-65 14h88" stroke="var(--art-signal)" stroke-width="5" stroke-linecap="round"/>
   <rect x="434" y="397" width="33" height="33" rx="10" fill="var(--art-phone)"/>
   <rect x="434" y="444" width="33" height="33" rx="10" fill="var(--art-halo)"/>
   <path d="M480 405h62m-62 14h44m-44 34h62m-62 14h44" stroke="var(--line)" stroke-width="5" stroke-linecap="round"/>
  </svg>
  <figcaption>{{seasonCopy[season].tagline}}</figcaption>
  <span class="art-label art-label-top">状态，随时可见</span>
  <span class="art-label art-label-bottom">{{seasonCopy[season].badge}}</span>
 </figure>
</template>
