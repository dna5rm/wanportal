<!--
  App shell, SaaS-style: the top bar splits into public nav on the
  left — brand plus Dashboard/Latency, the pages that work
  signed-out — and the account cluster on the right. Search is not a
  bar item: it lives on the dashboard as the MonitorSearch panel. The
  gated listing pages never sit in the bar: they live in the account
  dropdown SessionChip renders (Users only for an admin claim), so a
  signed-out visitor sees no links that would only dead-end on the
  API's 401.
  Sign-in lives in the app at #/login; the classic console is one hop
  away at /classic from the dropdown, the only classic door in the
  chrome.

  The shell runs the session probe itself — on mount and on every
  route change, so signing in on /login lights the account menu
  without a reload — and hands the answer down to SessionChip. The
  watch keys on fullPath (path, query and hash) so a navigation that
  only moves the hash still re-probes, and the probe is also provided
  to the tree: LoginView injects it and re-runs it on sign-in success,
  so the chip flips to the account menu even before the redirect.
-->
<script setup>
import { computed, onMounted, provide, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import SessionChip from './components/SessionChip.vue'
import { getSession } from './session'

const route = useRoute()

/* Session probe: null while asking, then the claims. */
const state = ref(null)

async function probe() {
    state.value = await getSession()
}

onMounted(probe)
/* fullPath covers path, query and hash: a re-route that only moves
 * the hash would never fire a path-only watch. */
watch(() => route.fullPath, probe)
provide('sessionProbe', probe)

/* The public pages stay in the bar for everyone; the gated listings
 * render from the account dropdown instead. */
const publicItems = [
    { label: 'Dashboard', to: '/' },
    { label: 'Latency', to: '/latency' }
]

/* '/' + first segment groups a listing with its detail pages
 * ('/monitors/xyz' lights the Monitors link). */
const section = computed(() => '/' + (route.path.split('/')[1] || ''))
</script>

<template>
    <nav class="topnav">
        <span class="brand">wanportal</span>
        <router-link v-for="it in publicItems" :key="it.to" class="nav-link" :to="it.to"
                     :class="{ active: it.to === '/' ? route.path === '/' : section === it.to }">
            {{ it.label }}
        </router-link>
        <span class="nav-end">
            <SessionChip :session="state" @change="probe" />
        </span>
    </nav>

    <router-view />
</template>