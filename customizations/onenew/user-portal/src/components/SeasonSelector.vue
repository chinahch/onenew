<script setup lang="ts">
import {seasons,seasonLabels,isSeason} from '../season'
import {useSeason} from '../seasonContext'
const {season,selectSeason}=useSeason()
function change(event:Event){const value=(event.target as HTMLSelectElement).value;if(isSeason(value))selectSeason(value)}
</script>
<template>
 <label class="season-switch">
  <span class="season-switch-label">四季主题</span>
  <select :value="season" aria-label="切换四季主题" @change="change">
   <option v-for="value in seasons" :key="value" :value="value">{{seasonLabels[value]}}</option>
  </select>
 </label>
</template>
