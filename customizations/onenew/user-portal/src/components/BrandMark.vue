<script setup lang="ts">
import {useSeason} from '../seasonContext'
const {season}=useSeason()
</script>
<template><span class="brand-mark" aria-hidden="true">
 <svg v-if="season==='autumn'" viewBox="0 0 36 36" fill="none"><path d="M27 7C13 5 5 13 9 23c3 8 16 8 19-3 1-4 0-9-1-13Z" fill="currentColor" opacity=".95"/><path d="M10 28 25 12M16 22l-1-7m5 3 6 1" stroke="var(--green)" stroke-width="1.8" stroke-linecap="round"/></svg>
 <svg v-else-if="season==='spring'" viewBox="0 0 36 36" fill="none"><path d="M18 29V16m0 5C9 22 6 15 7 10c8-1 13 3 11 11Zm0-4C17 9 23 5 29 6c1 7-3 12-11 11Z" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"/></svg>
 <svg v-else-if="season==='summer'" viewBox="0 0 36 36" fill="none"><circle cx="18" cy="18" r="7" stroke="currentColor" stroke-width="2.4"/><path d="M18 3v4m0 22v4M3 18h4m22 0h4M7 7l3 3m16 16 3 3M7 29l3-3M26 10l3-3" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"/></svg>
 <svg v-else viewBox="0 0 36 36" fill="none"><path d="M18 4v28M6 11l24 14M6 25l24-14M18 4l-4 4m4-4 4 4M18 32l-4-4m4 4 4-4M6 11l1 6m-1-6 6-1m18 15-6 1m6-1-1-6M6 25l6 1m-6-1 1-6m23-8-6-1m6 1-1 6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
</span></template>
