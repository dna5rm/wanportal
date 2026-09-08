<!--
  App shell. Every nav entry rides the router; a nav link stays lit
  while its detail pages are open. The route table is flat, so
  router-link-active cannot do that grouping for us — the first path
  segment does instead. The session chip asks /cgi-bin/api/session
  about the visitor; sign-in lives in the app at #/login now, with the
  classic console one hop away.
-->
<script setup>
import { computed } from 'vue'
import { useRoute } from 'vue-router'
import SessionChip from './components/SessionChip.vue'

const route = useRoute()

const items = [
    { label: 'Dashboard', to: '/' },
    { label: 'Monitors', to: '/monitors' },
    { label: 'Agents', to: '/agents' },
    { label: 'Targets', to: '/targets' },
    { label: 'Users', to: '/users' },
    { label: 'Search', to: '/search' },
    { label: 'Latency', to: '/latency' },
    { label: 'Credentials', to: '/credentials' }
]

/* '/' + first segment groups a listing with its detail pages
 * ('/monitors/xyz' lights the Monitors link). */
const section = computed(() => '/' + (route.path.split('/')[1] || ''))
</script>

<template>
    <nav class="topnav">
        <span class="brand">wanportal</span>
        <router-link v-for="it in items" :key="it.to" class="nav-link" :to="it.to"
                     :class="{ active: it.to === '/' ? route.path === '/' : section === it.to }">
            {{ it.label }}
        </router-link>
        <router-link class="nav-link" to="/login">log in</router-link>
        <SessionChip />
        <span class="nav-note muted">classic console still at <a href="/">/</a></span>
    </nav>

    <router-view />
</template>