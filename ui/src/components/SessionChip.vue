<!--
  The right-hand account cluster of the top bar. Renders from the
  session probe App hands down — no fetch of its own — and shows
  claims only, never the token: a signed-out visitor gets a single
  log-in link, a dead API shows "session?" rather than pretending the
  visitor is signed out, and a signed-in one gets a single username
  button — one plain label, no nested chip; the admin claim and the
  token expiry live in its tooltip — that toggles the account
  dropdown: the gated listing pages — Agents, Targets, Monitors,
  Credentials, plus Users for admins — the muted classic /classic
  door, and log out. Signing out forgets the tab's token and asks App
  to re-probe via the change event. The menu closes on any route
  change and on clicks outside the cluster.
-->
<script setup>
import { onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import { logout } from '../session'

defineProps({
    /* Probe result from App: null while the probe is in flight. */
    session: { type: Object, default: null }
})
const emit = defineEmits(['change'])

const route = useRoute()

const open = ref(false)

/* Tooltip for the account button: who, the admin claim, the expiry.
 * The claim and the deadline are status, not UI — they say nothing the
 * label needs a chip for. */
function accountTitle(session) {
    const bits = ['signed in']
    if (session.isAdmin) bits.push('admin')
    if (session.exp) bits.push('token expires ' + new Date(session.exp * 1000).toLocaleTimeString())
    return bits.join(', ')
}

/* Any navigation collapses the menu — picking a page from it included. */
watch(() => route.path, () => { open.value = false })

/* So do clicks that land outside the cluster. */
const root = ref(null)
function onDocClick(e) {
    if (open.value && root.value && !root.value.contains(e.target)) open.value = false
}
onMounted(() => document.addEventListener('click', onDocClick))
onBeforeUnmount(() => document.removeEventListener('click', onDocClick))

/* Signing out just forgets the tab's token; the change event tells
 * App to re-probe, which flips the cluster to the log-in door. */
async function signOut() {
    open.value = false
    await logout()
    emit('change')
}
</script>

<template>
    <span ref="root" class="session-chip">
        <span v-if="!session" class="muted">session&hellip;</span>

        <template v-else-if="session.authenticated">
            <button class="account-btn" type="button" :title="accountTitle(session)" @click="open = !open">{{ session.username }}</button>
            <span v-if="open" class="account-menu">
                <router-link class="menu-link" to="/agents">Agents</router-link>
                <router-link class="menu-link" to="/targets">Targets</router-link>
                <router-link class="menu-link" to="/monitors">Monitors</router-link>
                <router-link class="menu-link" to="/credentials">Credentials</router-link>
                <router-link v-if="session.isAdmin" class="menu-link" to="/users">Users</router-link>
                <a class="menu-link menu-muted" href="/classic">Classic console</a>
                <button class="menu-link" type="button" title="forget this tab's token" @click="signOut">Log out</button>
            </span>
        </template>

        <router-link v-else-if="session.reason === 'signed-out'" class="nav-link" to="/login">Log in</router-link>

        <span v-else class="muted" :title="session.error || 'session check failed'">session?</span>
    </span>
</template>

<style scoped>
.session-chip {
    position: relative;
    display: inline-flex;
    align-items: center;
    gap: 5px;
    font-size: 11.5px;
}
</style>