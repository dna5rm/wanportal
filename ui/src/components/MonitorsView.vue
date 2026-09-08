<!--
  Monitors listing, ported from htdocs/monitors.php. Same columns, same
  status wording: a row only counts as active when the monitor, its
  agent, and its target are all enabled, and the badge names whichever
  side is disabled. The row title opens the Vue detail route; editing
  and deleting stay on the classic console forms.
-->
<script setup>
import { computed, onMounted, ref } from 'vue'
import { getJson } from '../api'
import { fmtClock } from '../format'
import { activeChipCls, editLink } from './detailShared'

const rows = ref([])
const error = ref(null)
const loadedAt = ref(null)

/* A monitor is up only when monitor, agent, and target are all enabled —
 * the exact triple check the classic page runs. */
function effectiveActive(m) {
    return Number(m.is_active) === 1
        && Number(m.agent_is_active) === 1
        && Number(m.target_is_active) === 1
}

/* Inactive badges say which side is off, matching the PHP wording. */
function statusText(m) {
    if (effectiveActive(m)) return 'Active'
    let text = 'Inactive'
    if (Number(m.agent_is_active) !== 1) text += ' (Agent)'
    if (Number(m.target_is_active) !== 1) text += ' (Target)'
    return text
}

/* PHP hands port 0 to a falsy check, so ICMP rows read '-' not '0'. */
function portText(m) {
    return (m.port && Number(m.port)) ? m.port : '-'
}

/* PHP renders stamps as Y-m-d H:i and keeps the full value in a tooltip. */
function fmtStamp(s) {
    const m = /^(\d{4}-\d{2}-\d{2})[ T](\d{2}:\d{2})/.exec(s || '')
    return m ? m[1] + ' ' + m[2] : (s || '')
}

/* The classic table opens with Status descending (the DataTables
 * default), which floats the Inactive rows to the top; mirror that so
 * both consoles lead with the problems. Ties keep the API order. */
const sorted = computed(() =>
    rows.value
        .map((m, i) => ({ m, i }))
        .sort((a, b) => (effectiveActive(b.m) ? 0 : 1) - (effectiveActive(a.m) ? 0 : 1) || a.i - b.i)
        .map((x) => x.m)
)

/* Same honesty rules as the dashboard: a dead API with nothing to show
 * gets a loud banner, a dead refresh keeps the last good rows visible. */
const banner = computed(() => {
    if (!rows.value.length && error.value) {
        return { kind: 'banner banner-error', text: 'api unreachable — ' + error.value }
    }
    if (error.value) {
        return { kind: 'banner banner-warn', text: 'refresh failed — showing older data' }
    }
    return null
})

async function fetchRows() {
    error.value = null
    try {
        const json = await getJson('/cgi-bin/api/monitors')
        rows.value = json.monitors || []
        loadedAt.value = Date.now()
    } catch (e) {
        error.value = (e && e.message) || 'unknown error'
    }
}

onMounted(fetchRows)
</script>

<template>
    <header class="bar">
        <div class="bar-title">
            <h1>monitors</h1>
            <span class="muted">bundled vue · edits stay on the classic console</span>
        </div>
        <div class="bar-right">
            <span v-if="loadedAt" class="muted">updated {{ fmtClock(loadedAt) }}</span>
            <button class="btn" type="button" @click="fetchRows">refresh now</button>
            <router-link class="btn" :to="{ name: 'monitor-new' }">New Monitor</router-link>
        </div>
    </header>

    <div v-if="banner" :class="banner.kind">{{ banner.text }}</div>

    <section class="panel">
        <h2>monitors</h2>
        <table>
            <thead>
            <tr>
                <th>Description</th>
                <th>Agent</th>
                <th>Target</th>
                <th>Protocol</th>
                <th>Port</th>
                <th>DSCP</th>
                <th>Status</th>
                <th>Last Update</th>
                <th>Actions</th>
            </tr>
            </thead>
            <tbody>
            <tr v-if="!sorted.length">
                <td colspan="9" class="muted">no monitors</td>
            </tr>
            <tr v-for="m in sorted" :key="m.id" :class="{ 'row-inactive': !effectiveActive(m) }">
                <td>
                    <router-link :to="{ name: 'monitor', params: { id: m.id } }">
                        {{ m.description }}
                    </router-link>
                </td>
                <td>
                    <a :href="editLink('agent', m.agent_id)"
                       :class="{ muted: Number(m.agent_is_active) !== 1 }">
                        {{ m.agent_name }}<template v-if="Number(m.agent_is_active) !== 1"> (disabled)</template>
                    </a>
                </td>
                <td>
                    <a :href="editLink('target', m.target_id)"
                       :class="{ muted: Number(m.target_is_active) !== 1 }">
                        {{ m.target_address }}<template v-if="Number(m.target_is_active) !== 1"> (disabled)</template>
                    </a>
                </td>
                <td>{{ m.protocol }}</td>
                <td class="num">{{ portText(m) }}</td>
                <td>{{ m.dscp }}</td>
                <td>
                    <span class="chip" :class="activeChipCls(effectiveActive(m) ? 1 : 0)">{{ statusText(m) }}</span>
                </td>
                <td :title="m.last_update || ''">
                    {{ m.last_update ? fmtStamp(m.last_update) : 'Never' }}
                </td>
                <td>
                    <a class="btn" :href="editLink('monitor', m.id)" title="Edit">edit</a>
                </td>
            </tr>
            </tbody>
        </table>
    </section>

    <footer class="muted">
        vue listing; deletes and edits live on the classic console
    </footer>
</template>

<style scoped>
/* Stand-in for Bootstrap's table-secondary: inactive rows read dimmer. */
.row-inactive td { color: var(--muted); }
</style>