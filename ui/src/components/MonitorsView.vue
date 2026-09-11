<!--
  Monitors listing, ported from htdocs/monitors.php. Same columns, same
  status wording: a row only counts as active when the monitor, its
  agent, and its target are all enabled, and the badge names whichever
  side is disabled. The row title opens the Vue detail route; editing
  opens the in-app monitor form, and admins get a delete door beside
  edit (confirm first, then the singular api path, then a refetch). The
  text filter above the table is client-side and persistent
  (listingFilter) until its clear button wipes it.
-->
<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { delJson, getJson } from '../api'
import { getSession } from '../session'
import { fmtClock } from '../format'
import { activeChipCls } from './detailShared'
import { clearFilter, loadFilter, matchesFilter, saveFilter } from '../listingFilter'

const rows = ref([])
const error = ref(null)
const loadedAt = ref(null)
const session = ref(null)

/* The text filter is a client-side pass over the loaded rows, kept in
 * localStorage ('wanportal-filter-monitors', via listingFilter.js) so it
 * survives leaving the page — re-read on mount, and sticky until the
 * clear button wipes box and key. */
const FILTER_PAGE = 'monitors'
const q = ref(loadFilter(FILTER_PAGE).q)

/* Write doors need the SPA session: the classic console enforces its
 * login server-side, but this listing should not even offer New/Edit
 * to a signed-out visitor. Reads stay public, so the data loads for
 * everyone; only the buttons wait for the probe. Delete goes further —
 * the api answers it for admin tokens only, so its button asks for the
 * admin claim on top of the probe, exactly like the detail page's
 * delete door. */
const canEdit = computed(() => !!(session.value && session.value.authenticated))
const canAdmin = computed(() =>
    !!(session.value && session.value.authenticated && session.value.isAdmin))

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

/* The filter reads after the sort: problems-first ordering holds
 * within the surviving rows too. */
const filtered = computed(() =>
    sorted.value.filter((m) => matchesFilter(m, q.value))
)

/* Persist as the user types — debounced so a fast typist writes the
 * key once per pause, not once per keystroke. */
let saveTimer = null
let swallowNextSave = false
watch(q, (val) => {
    if (swallowNextSave) { swallowNextSave = false; return }
    clearTimeout(saveTimer)
    saveTimer = setTimeout(() => saveFilter(FILTER_PAGE, { q: val }), 200)
})
/* Leaving mid-pause still persists: the pending debounce is flushed
 * synchronously, then cancelled — navigation must not drop the last
 * keystrokes. */
onBeforeUnmount(() => {
    saveFilter(FILTER_PAGE, { q: q.value })
    clearTimeout(saveTimer)
})

/* The clear button only renders while a filter is set; it must leave
 * no key behind, so the one watch tick it triggers is swallowed
 * instead of re-persisting { q: '' } over the removal. */
function clearQ() {
    clearTimeout(saveTimer)
    swallowNextSave = true
    q.value = ''
    clearFilter(FILTER_PAGE)
    saveTimer = null
}

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

/* Delete door: confirm first (the rrd history goes with the monitor),
 * then the singular api path the detail page also deletes through,
 * then the listing refetches so the row actually leaves. A failure
 * keeps the rows as they are and says so; delJson throws the bare
 * status ('HTTP 403') on a refusal, and that is what the banner shows
 * — the api's message text never survives it. Descriptions can be
 * null, so the confirm falls back to the id rather than naming
 * nothing. */
const deleting = ref(false)
const deleteError = ref(null)

async function deleteMonitorRow(m) {
    if (deleting.value || !canAdmin.value) return
    const label = m.description || m.id
    if (!window.confirm('Delete monitor "' + label + '"? Its rrd history is removed too.')) return
    deleting.value = true
    deleteError.value = null
    try {
        await delJson('/cgi-bin/api/monitor/' + encodeURIComponent(m.id))
        await fetchRows()
    } catch (err) {
        deleteError.value = (err && err.message) || 'unknown error'
    } finally {
        deleting.value = false
    }
}

onMounted(async () => {
    session.value = await getSession()
    await fetchRows()
})
</script>

<template>
    <header class="bar">
        <div class="bar-title">
            <h1>monitors</h1>
        </div>
        <div class="bar-right">
            <span v-if="loadedAt" class="muted">updated {{ fmtClock(loadedAt) }}</span>
            <button class="btn" type="button" @click="fetchRows">refresh now</button>
            <router-link v-if="canEdit" class="btn" :to="{ name: 'monitor-new' }">New Monitor</router-link>
        </div>
    </header>

    <div v-if="banner" :class="banner.kind">{{ banner.text }}</div>
    <div v-if="deleteError" class="banner banner-warn">
        delete failed: {{ deleteError }} — the monitor is still listed.
    </div>

    <section class="panel">
        <h2>monitors</h2>
        <div class="listing-filter">
            <input v-model="q" type="search" placeholder="filter…" aria-label="Filter monitors">
            <button v-if="q" class="btn" type="button" @click="clearQ">clear</button>
        </div>
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
            <tr v-if="!filtered.length">
                <td colspan="9" class="muted">{{ q ? 'no monitors match' : 'no monitors' }}</td>
            </tr>
            <tr v-for="m in filtered" :key="m.id" :class="{ 'row-inactive': !effectiveActive(m) }">
                <td>
                    <router-link :to="{ name: 'monitor', params: { id: m.id } }">
                        {{ m.description }}
                    </router-link>
                </td>
                <td>
                    <router-link v-if="m.agent_id" :to="{ name: 'agent', params: { id: m.agent_id } }"
                                 :class="{ muted: Number(m.agent_is_active) !== 1 }">
                        {{ m.agent_name }}<template v-if="Number(m.agent_is_active) !== 1"> (disabled)</template>
                    </router-link>
                    <template v-else>{{ m.agent_name }}</template>
                </td>
                <td>
                    <router-link v-if="m.target_id" :to="{ name: 'target', params: { id: m.target_id } }"
                                 :class="{ muted: Number(m.target_is_active) !== 1 }">
                        {{ m.target_address }}<template v-if="Number(m.target_is_active) !== 1"> (disabled)</template>
                    </router-link>
                    <template v-else>{{ m.target_address }}</template>
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
                    <router-link v-if="canEdit" class="btn" :to="{ name: 'monitor-edit', params: { id: m.id } }" title="Edit">edit</router-link>
                    <button v-if="canAdmin" class="btn" type="button" :disabled="deleting"
                            @click="deleteMonitorRow(m)">delete</button>
                </td>
            </tr>
            </tbody>
        </table>
    </section>

</template>

<style scoped>
/* The filter row borrows the search-form chrome (MonitorSearch): one
 * bare input, the clear button riding beside it only while set. */
.listing-filter {
    display: flex;
    gap: 8px;
    margin: 4px 0 12px;
    max-width: 420px;
}

.listing-filter input {
    flex: 1;
    background: var(--bg);
    color: var(--text);
    border: 1px solid var(--panel-edge);
    border-radius: 6px;
    padding: 4px 8px;
    font-size: 12.5px;
}

/* Stand-in for Bootstrap's table-secondary: inactive rows read dimmer. */
.row-inactive td { color: var(--muted); }
</style>