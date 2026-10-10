<!--
  Services listing: every HTTP/S check in one flat index. This page has
  no classic sibling to port (services are SPA-only, spec §8.4), so the
  columns come from §8.2 and the rows keep the api's own order — the
  monitors listing sorts problems-first only to mirror its DataTables
  default, and there is no such default to mirror here.

  The authoritative per-host view stays the target detail page (§8.1),
  which is why the target column links there just like a monitor row
  would. The text filter is client-side and persistent (listingFilter),
  and the write doors are gated exactly like the monitor table: New and
  Edit need a signed-in session, Delete additionally needs the admin
  claim, confirm first, then the singular path, then a refetch.
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
 * localStorage ('wanportal-filter-services', via listingFilter.js) so
 * it survives leaving the page — re-read on mount, and sticky until the
 * clear button wipes box and key. */
const FILTER_PAGE = 'services'
const q = ref(loadFilter(FILTER_PAGE).q)

/* Write doors need the SPA session: reads could stay public, but with
 * no classic twin carrying a public read of services there is no
 * audience for it — the route gate bounces signed-out visitors before
 * this component mounts, and the buttons still wait for the probe. */
const canEdit = computed(() => !!(session.value && session.value.authenticated))
const canAdmin = computed(() =>
    !!(session.value && session.value.authenticated && session.value.isAdmin))

/* A service row only counts as active when the service, its agent and
 * its target are all enabled — the same triple the monitor listing
 * checks. The joined flags ride in from the api; a payload without
 * them counts as on, so a config-only subset never mislabels a row. */
function flag(v) {
    return (v === undefined || v === null) ? 1 : Number(v)
}

function effectiveActive(s) {
    return flag(s.is_active) === 1
        && flag(s.agent_is_active) === 1
        && flag(s.target_is_active) === 1
}

/* Inactive badges say which side is off, in the monitor table's
 * wording — the same reader should never learn a second convention. */
function statusText(s) {
    if (effectiveActive(s)) return 'Active'
    let text = 'Inactive'
    if (flag(s.agent_is_active) !== 1) text += ' (Agent)'
    if (flag(s.target_is_active) !== 1) text += ' (Target)'
    return text
}

/* The rolled-up check state, from the agent's last report. UP is
 * green, DOWN red, and everything else (DISABLED, UNKNOWN, a missing
 * value) amber — an unreadable state must never render as healthy. */
function stateChipCls(s) {
    const st = String(s.last_state || 'UNKNOWN')
    if (st === 'UP') return 'chip chip-ok'
    if (st === 'DOWN') return 'chip chip-danger'
    return 'chip chip-warn'
}

/* scheme://host[:port]/path[?query] — the port only prints when the
 * service overrides the scheme default (0 means default, §3.1), the
 * same way the agent builds the URL it probes. */
function uriText(s) {
    const port = Number(s.port)
    return String(s.scheme || '') + '://' + (s.target_address || '')
        + (port ? ':' + port : '')
        + (s.uri_path || '/') + (s.uri_query ? '?' + s.uri_query : '')
}

/* An agent that never announced services support cannot run the check
 * at all (I3) — the api would keep it UNKNOWN. When the join says so,
 * the state chip explains itself on hover instead of looking broken. */
function stateTitle(s) {
    if (s.agent_supports_services !== undefined
        && Number(s.agent_supports_services) === 0) {
        return 'agent_unsupported — this agent has not announced services support'
    }
    return s.last_reason || ''
}

/* PHP hands port 0 to a falsy check, so a scheme-default port prints
 * as '-' not '0'; and stamps render minute-resolution with the full
 * value in a tooltip, as the classic tables do. */
function portText(s) {
    return (s.port && Number(s.port)) ? s.port : '-'
}

function fmtStamp(s) {
    const m = /^(\d{4}-\d{2}-\d{2})[ T](\d{2}:\d{2})/.exec(s || '')
    return m ? m[1] + ' ' + m[2] : (s || '')
}

const filtered = computed(() =>
    rows.value.filter((s) => matchesFilter(s, q.value)))

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

/* Same honesty rules as the monitor listing: a dead api with nothing
 * to show gets a loud banner, a dead refresh keeps the last good rows
 * visible. */
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
        const json = await getJson('/cgi-bin/api/services')
        rows.value = json.services || []
        loadedAt.value = Date.now()
    } catch (e) {
        error.value = (e && e.message) || 'unknown error'
    }
}

/* Delete door: confirm first (the rrd history goes with the service),
 * then the singular api path the detail page also deletes through,
 * then the listing refetches so the row actually leaves. A failure
 * keeps the rows as they are and says so — the api's message text
 * never survives delJson's bare status, so that is what shows.
 * Descriptions can be null, so the confirm falls back to the id. */
const deleting = ref(false)
const deleteError = ref(null)

async function deleteServiceRow(s) {
    if (deleting.value || !canAdmin.value) return
    const label = s.description || s.id
    if (!window.confirm('Delete service "' + label + '"? Its rrd history is removed too.')) return
    deleting.value = true
    deleteError.value = null
    try {
        await delJson('/cgi-bin/api/service/' + encodeURIComponent(s.id))
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
            <h1>services</h1>
        </div>
        <div class="bar-right">
            <span v-if="loadedAt" class="muted">updated {{ fmtClock(loadedAt) }}</span>
            <button class="btn" type="button" @click="fetchRows">refresh now</button>
            <router-link v-if="canEdit" class="btn" :to="{ name: 'service-new' }">New Service</router-link>
        </div>
    </header>

    <div v-if="banner" :class="banner.kind">{{ banner.text }}</div>
    <div v-if="deleteError" class="banner banner-warn">
        delete failed: {{ deleteError }} — the service is still listed.
    </div>

    <section class="panel">
        <h2>services</h2>
        <div class="listing-filter">
            <input v-model="q" type="search" placeholder="filter…" aria-label="Filter services">
            <button v-if="q" class="btn" type="button" @click="clearQ">clear</button>
        </div>
        <table>
            <thead>
            <tr>
                <th>Description</th>
                <th>Agent</th>
                <th>Target</th>
                <th>URI</th>
                <th>Port</th>
                <th>State</th>
                <th>Last Check</th>
                <th>Last Change</th>
                <th>Actions</th>
            </tr>
            </thead>
            <tbody>
            <tr v-if="!filtered.length">
                <td colspan="9" class="muted">{{ q ? 'no services match' : 'no services' }}</td>
            </tr>
            <tr v-for="s in filtered" :key="s.id" :class="{ 'row-inactive': !effectiveActive(s) }">
                <td>
                    <router-link :to="{ name: 'service', params: { id: s.id } }">
                        {{ s.description || s.id }}
                    </router-link>
                </td>
                <td>
                    <router-link v-if="s.agent_id" :to="{ name: 'agent', params: { id: s.agent_id } }"
                                 :class="{ muted: flag(s.agent_is_active) !== 1 }">
                        {{ s.agent_name }}<template v-if="flag(s.agent_is_active) !== 1"> (disabled)</template>
                    </router-link>
                    <template v-else>{{ s.agent_name }}</template>
                </td>
                <td>
                    <router-link v-if="s.target_id" :to="{ name: 'target', params: { id: s.target_id } }"
                                 :class="{ muted: flag(s.target_is_active) !== 1 }">
                        {{ s.target_address }}<template v-if="flag(s.target_is_active) !== 1"> (disabled)</template>
                    </router-link>
                    <template v-else>{{ s.target_address }}</template>
                </td>
                <td class="mono uri-cell" :title="uriText(s)">{{ uriText(s) }}</td>
                <td class="num">{{ portText(s) }}</td>
                <td class="state-cell">
                    <span class="chip" :class="stateChipCls(s)" :title="stateTitle(s)">
                        {{ s.last_state || 'UNKNOWN' }}
                    </span>
                    <span class="chip" :class="activeChipCls(effectiveActive(s) ? 1 : 0)">
                        {{ statusText(s) }}
                    </span>
                </td>
                <td :title="s.last_check || ''">
                    {{ s.last_check ? fmtStamp(s.last_check) : 'Never' }}
                </td>
                <td :title="s.last_change || ''">
                    {{ s.last_change ? fmtStamp(s.last_change) : '-' }}
                </td>
                <td>
                    <router-link v-if="canEdit" class="btn" :to="{ name: 'service-edit', params: { id: s.id } }" title="Edit">edit</router-link>
                    <button v-if="canAdmin" class="btn" type="button" :disabled="deleting"
                            @click="deleteServiceRow(s)">delete</button>
                </td>
            </tr>
            </tbody>
        </table>
    </section>

</template>

<style scoped>
/* The filter row borrows the search-form chrome, and the two chips per
 * status cell stack tight: check state first, then the config-active
 * badge that names a disabled side. */
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

.uri-cell {
    font-size: 11.5px;
    max-width: 340px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.state-cell .chip {
    margin-right: 4px;
}
</style>