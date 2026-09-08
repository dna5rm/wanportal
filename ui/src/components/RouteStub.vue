<!--
  Stand-in for the detail pages until the real views land. Says so
  plainly and hands the visitor to the classic console page that
  already renders the record, plus a way back to the list. The route
  table carries the classic page and list name in meta, so this file
  never needs to know which entity it is showing.
-->
<script setup>
import { computed } from 'vue'
import { useRoute } from 'vue-router'

const route = useRoute()

const legacyUrl = computed(() =>
    (route.meta.legacy || '/')
        + '?id=' + encodeURIComponent(String(route.params.id || ''))
)
</script>

<template>
    <section class="panel">
        <h2>detail not migrated yet</h2>
        <div class="placeholder-body">
            <p>
                The Vue app lists these records but does not render a detail
                page for <span class="chip">{{ route.params.id }}</span> yet.
            </p>
            <p>
                Keep using the classic console for now:
                <a :href="legacyUrl">{{ legacyUrl }}</a>
            </p>
            <p>
                <router-link :to="{ name: route.meta.list || 'dashboard' }">back to the list</router-link>
            </p>
        </div>
    </section>
</template>