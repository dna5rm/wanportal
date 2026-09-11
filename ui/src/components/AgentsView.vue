<!--
  Agents listing, ported from htdocs/agents.php. Same columns and
  status wording; the row title opens the Vue detail route. Creating
  happens in the app now — New opens the agent form — and per-row
  edits open the same in-app form. There is simply no delete button
  here at all. New and edit wait for a signed-in SPA session
  (getSession probe), like the classic page's login wall. The text
  filter above the table is client-side and persistent (listingFilter)
  until its clear button wipes it.
-->
<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { getJson } from '../api'
import { getSession } from '../session'
import { fmtClock } from '../format'
import { activeChipCls } from './detailShared'
import { clearFilter, loadFilter, matchesFilter, saveFilter } from '../listingFilter'

const rows = ref([])
const error = ref(null)
const loadedAt = ref(null)
const session = ref(null)

/* The text filter is a client-side pass over the loaded rows, kept in
 * localStorage ('wanportal-filter-agents', via listingFilter.js) so it
 * survives leaving the page — re-read on mount, and sticky until the
 * clear button wipes box and key. */
const FILTER_PAGE = 'agents'
const q = ref(loadFilter(FILTER_PAGE).q)

/* Write doors need the SPA session: the classic console enforces its
 * login server-side, but this listing should not even offer New/Edit
 * to a signed-out visitor. Reads stay public, so the data loads for
 * everyone; only the buttons wait for the probe. */
const canEdit = computed(() => !!(session.value && session.value.authenticated))

function isActive(a) {
    return Number(a.is_active) === 1
}

/* PHP renders stamps as Y-m-d H:i and keeps the full value in a tooltip. */
function fmtStamp(s) {
    const m = /^(\d{4}-\d{2}-\d{2})[ T](\d{2}:\d{2})/.exec(s || '')
    return m ? m[1] + ' ' + m[2] : (s || '')
}

/* Status descending, like the classic table's default order: problems
 * on top, ties in API order. */
const sorted = computed(() =>
    rows.value
        .map((a, i) => ({ a, i }))
        .sort((x, y) => (isActive(y.a) ? 0 : 1) - (isActive(x.a) ? 0 : 1) || x.i - y.i)
        .map((x) => x.a)
)

/* The filter reads after the sort: problems-first ordering holds
 * within the surviving rows too. */
const filtered = computed(() =>
    sorted.value.filter((a) => matchesFilter(a, q.value))
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
        const json = await getJson('/cgi-bin/api/agents')
        rows.value = json.agents || []
        loadedAt.value = Date.now()
    } catch (e) {
        error.value = (e && e.message) || 'unknown error'
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
            <h1>agents</h1>
        </div>
        <div class="bar-right">
            <span v-if="loadedAt" class="muted">updated {{ fmtClock(loadedAt) }}</span>
            <button class="btn" type="button" @click="fetchRows">refresh now</button>
            <router-link v-if="canEdit" class="btn" :to="{ name: 'agent-new' }">New Agent</router-link>
        </div>
    </header>

    <div v-if="banner" :class="banner.kind">{{ banner.text }}</div>

    <section class="panel">
        <h2>agents</h2>
        <div class="listing-filter">
            <input v-model="q" type="search" placeholder="filter…" aria-label="Filter agents">
            <button v-if="q" class="btn" type="button" @click="clearQ">clear</button>
        </div>
        <table>
            <thead>
            <tr>
                <th>Name</th>
                <th>Address</th>
                <th>Description</th>
                <th>Status</th>
                <th>Last Seen</th>
                <th>Actions</th>
            </tr>
            </thead>
            <tbody>
            <tr v-if="!filtered.length">
                <td colspan="6" class="muted">{{ q ? 'no agents match' : 'no agents' }}</td>
            </tr>
            <tr v-for="a in filtered" :key="a.id" :class="{ 'row-inactive': !isActive(a) }">
                <td>
                    <router-link :to="{ name: 'agent', params: { id: a.id } }">
                        {{ a.name }}
                    </router-link>
                </td>
                <td>{{ a.address }}</td>
                <td>{{ a.description }}</td>
                <td>
                    <span class="chip" :class="activeChipCls(isActive(a) ? 1 : 0)">
                        {{ isActive(a) ? 'Active' : 'Inactive' }}
                    </span>
                </td>
                <td :title="a.last_seen || ''">
                    {{ a.last_seen ? fmtStamp(a.last_seen) : 'Never' }}
                </td>
                <td>
                    <router-link v-if="canEdit" class="btn" :to="{ name: 'agent-edit', params: { id: a.id } }" title="Edit">edit</router-link>
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