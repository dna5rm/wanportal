<!--
  Tiny nav chip for the sign-in state. Asks the session probe what
  GET /cgi-bin/api/session says about the caller — the SPA attaches its
  Bearer token when it holds one — and renders claims only, never the
  token itself: a signed-in visitor gets their username (plus an admin
  chip when the claim says so and a log out button), a signed-out one
  gets a link to the SPA /login plus the classic /login.php kept around
  in muted grey. A dead API shows "session?" rather than pretending the
  visitor is signed out. The chip mounts once for the whole app, so it
  re-probes when the route changes and stays current with sign-ins
  happening on /login.
-->
<script setup>
import { onMounted, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import { getSession, logout } from '../session'

const route = useRoute()

const state = ref(null) // session probe result, null while probing

async function probe() {
    state.value = await getSession()
}

/* Signing out just forgets the tab's token; the probe afterwards flips
 * the chip back to the signed-out links. */
async function signOut() {
    await logout()
    await probe()
}

onMounted(probe)
watch(() => route.path, probe)
</script>

<template>
    <span v-if="state === null" class="session-chip muted">session&hellip;</span>
    <span v-else-if="state.authenticated" class="session-chip">
        <span class="chip chip-ok" :title="'signed in' + (state.exp ? ', token expires ' + new Date(state.exp * 1000).toLocaleTimeString() : '')">{{ state.username }}</span>
        <span v-if="state.isAdmin" class="chip chip-danger">admin</span>
        <button class="chip-logout" type="button" title="forget this tab's token" @click="signOut">log out</button>
    </span>
    <span v-else-if="state.reason === 'signed-out'" class="session-chip muted" title="not signed in">
        signed out
        <router-link class="chip-login" to="/login">log in</router-link>
        <a class="muted" href="/login.php">classic</a>
    </span>
    <span v-else class="session-chip muted" :title="state.error || 'session check failed'">session?</span>
</template>

<style scoped>
.session-chip {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    font-size: 11.5px;
}

.chip-logout {
    background: none;
    border: none;
    color: var(--muted);
    cursor: pointer;
    font: inherit;
    font-size: 11.5px;
    padding: 0;
}

.chip-logout:hover { color: var(--text); }

.chip-login {
    color: var(--up);
    text-decoration: none;
}

.chip-login:hover { text-decoration: underline; }
</style>