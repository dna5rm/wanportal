<!--
  App shell, SaaS-style: the top bar splits into a public nav on the
  left — brand plus Dashboard and Latency, the pages that work
  signed-out — and the account cluster on the right, which opens with
  the API link to the swagger at /api, immediately left of the
  account chip, so the cluster reads [API] [Log in] signed-out and
  [API] [username] signed in. Search is not a
  bar item: it lives on the dashboard as the MonitorSearch panel. The
  gated listing pages never sit in the bar: they live in the account
  dropdown SessionChip renders (Users only for an admin claim), so a
  signed-out visitor sees no links that would only dead-end on the
  API's 401. Runtime is a tool door, not a public page: it stays in
  the account dropdown, so it is reachable only once signed in.
  Sign-in lives in the app at #/login; the classic console is one hop
  away at /classic from the dropdown, the only classic door in the
  chrome. The theme toggle also lives in the right cluster — between
  the API door and the account chip, sharing the .btn chrome — and
  writes the same localStorage key the classic console's dark-mode
  toggle uses, so one preference serves both consoles.

  The shell runs the session probe itself — on mount and on every
  route change, so signing in on /login lights the account menu
  without a reload — and hands the answer down to SessionChip. The
  watch keys on fullPath (path, query and hash) so a navigation that
  only moves the hash still re-probes, and the probe is also provided
  to the tree: LoginView injects it and re-runs it on sign-in success,
  so the chip flips to the account menu even before the redirect.

  The brand slot follows the operator config read at startup
  (htdocs/config.json, outside every build): a nonempty logo swaps the
  text brand for the brand image, anything else keeps the text. The
  config also carries extra nav entries, rendered after the public
  pages by NavMenu — router-links for in-app routes, external doors
  for hrefs, and a hover/click dropdown for nested children — while
  the right cluster stays exactly the built-in set.
-->
<script setup>
import { computed, onMounted, provide, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import NavMenu from './components/NavMenu.vue'
import SessionChip from './components/SessionChip.vue'
import ThemeToggle from './components/ThemeToggle.vue'
import { loadSiteConfig } from './siteConfig.js'
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

/* The public pages stay on the left of the bar for everyone; the
 * gated listings and the Runtime tool door render from the account
 * dropdown instead. The API swagger is not one of them — it sits in
 * the right cluster, immediately left of the account chip, rendered
 * directly in the template below. */
const publicItems = [
    { label: 'Dashboard', to: '/' },
    { label: 'Latency', to: '/latency' }
]

/* '/' + first segment groups a listing with its detail pages
 * ('/monitors/xyz' lights the Monitors link). */
const section = computed(() => '/' + (route.path.split('/')[1] || ''))

/* Operator site config (htdocs/config.json, read outside every
 * build): the brand image and the extra nav entries after the public
 * pages. loadSiteConfig never throws — every failure lands on the
 * default, so a broken config just renders the built-in chrome. */
const site = ref({ logo: '', menu: [] })

async function loadSite() {
    site.value = await loadSiteConfig()
}

onMounted(loadSite)
</script>

<template>
    <nav class="topnav">
        <img v-if="site.logo" class="brand-logo" :src="site.logo" alt="wanportal">
        <span v-else class="brand">wanportal</span>
        <router-link v-for="it in publicItems" :key="it.to" class="nav-link" :to="it.to"
                     :class="{ active: it.to === '/' ? route.path === '/' : section === it.to }">
            {{ it.label }}
        </router-link>
        <NavMenu :items="site.menu" />
        <span class="nav-end">
            <router-link class="nav-link" to="/api">API</router-link>
            <ThemeToggle />
            <SessionChip :session="state" @change="probe" />
        </span>
    </nav>

    <router-view />
</template>

<style scoped>
/* The operator's brand image takes the text brand's slot in the bar:
 * bar height, not banner height. */
.brand-logo {
    display: block;
    height: 22px;
    width: auto;
}
</style>