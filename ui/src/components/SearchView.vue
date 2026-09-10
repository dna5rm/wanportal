<!--
  Search page, kept as the /search deep-link target. The panel itself
  is the shared MonitorSearch component, which the dashboard also
  embeds — this wrapper stays only so old /search links keep working.
-->
<script setup>
import MonitorSearch from './MonitorSearch.vue'
</script>

<template>
    <MonitorSearch />
</template>