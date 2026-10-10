<!--
  The search panel, shared two ways: the /search deep-link route
  renders it as a page (SearchView is a thin wrapper around this), and
  the dashboard embeds it in compact mode — one bare input+submit row
  with no heading and no empty-state hint, and results only after a
  term is submitted. One term sweeps both estates: the public
  /monitors?q= the classic search.php calls plus /services?q= fired
  together, so both front ends always see the same hits for any given
  name. Read-only by design: the app lists what the API
  returns and links into its own detail routes; the classic console
  still owns everything that changes data.
-->
<script setup>
import { computed, ref } from 'vue'
import { getJson } from '../api'
import { lossClass } from '../format'

/* compact: the dashboard embed — a single form row that asks for
 * nothing until a real term is submitted. The /search page leaves the
 * prop off and keeps the verbose panel (heading, hint, capped width). */
defineProps({
    compact: { type: Boolean, default: false }
})

const q = ref('')
const rows = ref([])
const error = ref(null)
const loading = ref(false)
const searched = ref(false)   // separates "nothing searched yet" from "no hits"
const svcRows = ref([])       // the /services?q= half of the same term
const svcError = ref(null)    // set on its own: one dead sweep must not bury the other's hits

async function runSearch() {
    // The classic page just redirects home on an empty term; here an
    // empty box simply does nothing.
    const term = q.value.trim()
    if (!term) return
    loading.value = true
    error.value = null
    svcError.value = null
    // Both sweeps fire together and settle together, so the results
    // area paints once instead of monitors-first-then-services. Each
    // half keeps its own failure: the api serving services is a
    // separate build, and a miss there must not hide monitor hits the
    // other half still delivered.
    const [mon, svc] = await Promise.allSettled([
        getJson('/cgi-bin/api/monitors?q=' + encodeURIComponent(term)),
        getJson('/cgi-bin/api/services?q=' + encodeURIComponent(term))
    ])
    if (mon.status === 'fulfilled') {
        rows.value = mon.value.monitors || []
    } else {
        error.value = (mon.reason && mon.reason.message) || 'search failed'
        rows.value = []
    }
    if (svc.status === 'fulfilled') {
        svcRows.value = svc.value.services || []
    } else {
        svcError.value = (svc.reason && svc.reason.message) || 'search failed'
        svcRows.value = []
    }
    searched.value = true
    loading.value = false
}

/* is_active on a row is already the effective flag (monitor AND agent
 * AND target), so the count needs no extra math. */
const stats = computed(() => {
    const total = rows.value.length
    const active = rows.value.filter((m) => Number(m.is_active) === 1).length
    return { total, active, inactive: total - active }
})

function isActiveRow(m) {
    return Number(m.is_active) === 1
}

function protocolLabel(m) {
    const proto = String(m.protocol || '').toUpperCase()
    return proto === 'ICMP' ? proto : proto + '/' + (m.port ?? '-')
}

/* Service hits read like the down-services table: UP green, DOWN red,
 * everything else amber — an unreadable state never renders healthy. */
function svcStateCls(s) {
    const st = String(s.last_state || 'UNKNOWN')
    if (st === 'UP') return 'chip-ok'
    if (st === 'DOWN') return 'chip-danger'
    return 'chip-warn'
}

/* A service-level disable dims the row the way is_active dims monitor
 * rows; agent/target liveness rides no q= payload, so nothing deeper
 * is claimed here. */
function svcInactive(s) {
    return Number(s.is_active) === 0
}

/* scheme://host[:port]/path[?query] — the port prints when the row
 * carries a non-zero one (0 = scheme default), the same uri text the
 * services listing's cell builds. */
function svcUri(s) {
    const port = Number(s.port)
    return String(s.scheme || '') + '://' + (s.target_address || '')
        + (port ? ':' + port : '')
        + (s.uri_path || '/') + (s.uri_query ? '?' + s.uri_query : '')
}
</script>

<template>
    <section class="panel" :class="{ 'search-compact': compact }">
        <h2 v-if="!compact">search</h2>

        <form class="search-form" @submit.prevent="runSearch">
            <input v-model="q" type="search" placeholder="search monitors and services..."
                   aria-label="Search monitors and services">
            <button class="btn" type="submit" :disabled="loading">
                {{ loading ? 'searching…' : 'search' }}
            </button>
        </form>

        <div v-if="error" class="err-note block">search failed — {{ error }}</div>

        <p v-if="searched && !error" class="muted">
            {{ stats.total }} results · {{ stats.active }} effectively active ·
            {{ stats.inactive }} effectively inactive<span v-if="!svcError">
            · {{ svcRows.length }} service{{ svcRows.length === 1 ? '' : 's' }}</span>
        </p>

        <!-- The sweep hint only belongs on the /search page; the compact
             embed stays one bare row until results exist. -->
        <p v-if="!searched && !compact" class="muted search-hint">
            Sweeps monitor descriptions, agent names and addresses, and target
            addresses, plus service descriptions and url paths.
        </p>

        <table v-if="searched && !error">
            <thead>
            <tr>
                <th>monitor</th>
                <th>agent</th>
                <th>target</th>
                <th>protocol</th>
                <th class="num">median</th>
                <th class="num">loss</th>
                <th>last update</th>
            </tr>
            </thead>
            <tbody>
            <tr v-if="!rows.length">
                <td colspan="7" class="muted">no results found</td>
            </tr>
            <!-- Detail cells ride the SPA routes; a cell with no id to
                 aim at stays plain text instead of a dead link. -->
            <tr v-for="m in rows" :key="m.id" :class="{ dim: !isActiveRow(m) }">
                <td>
                    <router-link v-if="m.id" :to="{ name: 'monitor', params: { id: m.id } }" :title="m.id">
                        {{ m.description || m.id }}
                    </router-link>
                    <template v-else>{{ m.description || '-' }}</template>
                </td>
                <td>
                    <router-link v-if="m.agent_id" :to="{ name: 'agent', params: { id: m.agent_id } }">
                        {{ m.agent_name || '-' }}
                    </router-link>
                    <template v-else>{{ m.agent_name || '-' }}</template>
                </td>
                <td>
                    <router-link v-if="m.target_id" :to="{ name: 'target', params: { id: m.target_id } }">
                        {{ m.target_address || '-' }}
                    </router-link>
                    <template v-else>{{ m.target_address || '-' }}</template>
                </td>
                <td><span :title="'DSCP: ' + (m.dscp ?? '-')">{{ protocolLabel(m) }}</span></td>
                <td class="num">{{ m.current_median }} ms</td>
                <td class="num"><span class="chip" :class="lossClass(m.current_loss)">{{
                    (Number(m.current_loss) || 0).toFixed(1) }}%</span></td>
                <td :title="'last down: ' + (m.last_down || '-')">{{ m.last_update || '-' }}</td>
            </tr>
            </tbody>
        </table>

        <!-- The services half of the sweep reports itself where the
             monitor table ends: hits get their own table under a
             one-word label, a failed services sweep says so without
             touching the monitor results above. -->
        <div v-if="svcError" class="err-note block">service search failed — {{ svcError }}</div>

        <template v-if="searched && !error && svcRows.length">
            <p class="muted svc-label">services</p>
            <table>
                <thead>
                <tr>
                    <th>service</th>
                    <th>agent</th>
                    <th>target</th>
                    <th>uri</th>
                    <th>state</th>
                    <th>last check</th>
                </tr>
                </thead>
                <tbody>
                <!-- Same link rules as the monitor sweep: a cell with an
                     id rides its detail route, one without stays plain
                     text instead of a dead link. -->
                <tr v-for="s in svcRows" :key="s.id" :class="{ dim: svcInactive(s) }">
                    <td>
                        <router-link v-if="s.id" :to="{ name: 'service', params: { id: s.id } }" :title="s.id">
                            {{ s.description || s.id }}
                        </router-link>
                        <template v-else>{{ s.description || '-' }}</template>
                    </td>
                    <td>
                        <router-link v-if="s.agent_id" :to="{ name: 'agent', params: { id: s.agent_id } }">
                            {{ s.agent_name || '-' }}
                        </router-link>
                        <template v-else>{{ s.agent_name || '-' }}</template>
                    </td>
                    <td>
                        <router-link v-if="s.target_id" :to="{ name: 'target', params: { id: s.target_id } }">
                            {{ s.target_address || '-' }}
                        </router-link>
                        <template v-else>{{ s.target_address || '-' }}</template>
                    </td>
                    <td class="svc-uri" :title="svcUri(s)">{{ svcUri(s) }}</td>
                    <td><span class="chip" :class="svcStateCls(s)" :title="s.last_reason || ''">{{ s.last_state || 'UNKNOWN' }}</span></td>
                    <td>{{ s.last_check || '-' }}</td>
                </tr>
                </tbody>
            </table>
        </template>
    </section>
</template>

<style scoped>
.search-form {
    display: flex;
    gap: 8px;
    margin: 4px 0 12px;
    max-width: 420px;
}

.search-form input {
    flex: 1;
    background: var(--bg);
    color: var(--text);
    border: 1px solid var(--panel-edge);
    border-radius: 6px;
    padding: 4px 8px;
    font-size: 12.5px;
}

/* Dashboard embed: the panel tightens to one full-width form row; the
 * verbose /search page keeps its capped width and roomier margins. */
.search-compact .search-form {
    max-width: none;
    margin: 0;
}

/* Effectively inactive rows keep their place but read as greyed out. */
tr.dim td {
    color: var(--muted);
}

/* Hits from the /services sweep follow the monitor table: a one-word
 * label keeps the two groups reading apart (the section heading stays
 * 'search'), and the uri cell matches the services listing's mono
 * treatment — clamped, with the full value on hover. */
.svc-label {
    margin: 14px 0 6px;
}

.svc-uri {
    font-family: ui-monospace, monospace;
    font-size: 11.5px;
    max-width: 340px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}
</style>