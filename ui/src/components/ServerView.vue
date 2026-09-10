<!--
  Server page, ported from htdocs/server.php. Same four data doors as
  the classic page: the public /agents, /targets and /monitors
  listings for the active/disabled/total counts, and /health for the
  uptime. One door needs its own plumbing: /health answers a
  status:'ok' envelope, and the shared getJson only accepts the
  'success' one, so uptime rides a local fetcher with the same
  transport the api module applies (timeout, credentials omitted,
  bearer header when the tab holds a token). Routed through getJson,
  a perfectly healthy API would read as a failed door.

  Honesty rules are the dashboard's: the four calls fire together
  under Promise.allSettled, a failing door is named in the banner and
  shows 'fetch failed' in its own row, and the numbers the healthy
  doors delivered are never zeroed or blanked — a door that already
  answered keeps its last good counts through a failed refresh.

  /health carries only uptime_seconds — no clock fields — so the time
  rows are computed from the browser's clock and labeled 'browser'
  time/utc. If the endpoint ever grows the clock fields server.php
  used to print (server_localtime / server_utc_time /
  server_timezone), those take over and are labeled 'server' instead.

  server.php meta-refreshes every 300s; same rhythm here, and like the
  latency report there is no countdown chrome on the toolbar. No
  second title either: the top bar already names the page (Runtime),
  so the bar opens on just the updated stamp and refresh.
-->
<script setup>
import { computed, onBeforeUnmount, onMounted, reactive, ref } from 'vue'
import { getJson } from '../api'
import { authHeaders } from '../session'
import { fmtClock } from '../format'

/* server.php meta-refreshes every 300s; same rhythm here. */
const REFRESH_SECONDS = 300

/* counts: per-door {active, disabled, total} or null until the door
 * first answers; health: the /health payload; errors: last failure
 * message per door. Failed refreshes only ever set errors — the good
 * values from earlier cycles stay put. */
const counts = ref({ agents: null, targets: null, monitors: null })
const health = ref(null)
const errors = reactive({ agents: null, targets: null, monitors: null, health: null })
const loadedAt = ref(null)
const loading = ref(false)

let timer = null

/* /health's envelope says 'ok', not the 'success' getJson insists on,
 * so this one door is fetched by hand with the same transport the api
 * module wires: 10s timeout, credentials omitted, bearer only when a
 * token is stored. */
async function fetchHealth() {
    const ctrl = new AbortController()
    const killer = setTimeout(() => ctrl.abort(), 10000)
    try {
        const res = await fetch('/cgi-bin/api/health', {
            credentials: 'omit',
            headers: { Accept: 'application/json', ...authHeaders() },
            signal: ctrl.signal
        })
        if (!res.ok) throw new Error('HTTP ' + res.status)
        const json = await res.json()
        if (!json || (json.status !== 'ok' && json.status !== 'success')) {
            throw new Error('bad payload')
        }
        return json
    } finally {
        clearTimeout(killer)
    }
}

/* The classic count helper, verbatim: active vs disabled by is_active,
 * plus the total. Only ever called on a fulfilled promise, so a dead
 * door can never reach it to zero anything. */
function countRows(rows) {
    const out = { active: 0, disabled: 0, total: 0 }
    for (const r of rows || []) {
        if (Number(r.is_active) === 1) out.active++
        else out.disabled++
        out.total++
    }
    return out
}

/* server.php's format_uptime: days, hours, minutes — singulars kept,
 * seconds dropped, parts joined with ', '. A host up for under a
 * minute formats to an empty string in PHP; here that reads as
 * 'under a minute' instead of a blank. */
function formatUptime(secs) {
    let s = Math.max(0, Math.floor(Number(secs) || 0))
    const d = Math.floor(s / 86400)
    s -= d * 86400
    const h = Math.floor(s / 3600)
    s -= h * 3600
    const m = Math.floor(s / 60)
    const parts = []
    if (d > 0) parts.push(d + ' ' + (d === 1 ? 'day' : 'days'))
    if (h > 0) parts.push(h + ' ' + (h === 1 ? 'hour' : 'hours'))
    if (m > 0) parts.push(m + ' ' + (m === 1 ? 'minute' : 'minutes'))
    return parts.length ? parts.join(', ') : 'under a minute'
}

const uptimeText = computed(() =>
    health.value ? formatUptime(health.value.uptime_seconds) : ''
)

const p2 = (n) => String(n).padStart(2, '0')

function localStampOf(ts) {
    const d = new Date(ts)
    return d.getFullYear() + '-' + p2(d.getMonth() + 1) + '-' + p2(d.getDate()) +
        ' ' + p2(d.getHours()) + ':' + p2(d.getMinutes()) + ':' + p2(d.getSeconds())
}

/* UTC twin, the way gmdate printed it. */
function utcStampOf(ts) {
    return new Date(ts).toISOString().replace('T', ' ').slice(0, 19)
}

/* Clock rows. Server fields come from the payload when /health has
 * them (and the UTC line hides when the zone already is UTC, the way
 * the classic page behaved); otherwise the browser's clock stands in,
 * labeled as the browser's. */
const clockRows = computed(() => {
    const h = health.value
    if (h && h.server_localtime) {
        const tz = h.server_timezone || ''
        const rows = [{
            label: 'server time',
            text: h.server_localtime + (tz && tz !== 'UTC' ? ' (' + tz + ')' : '')
        }]
        if (tz !== 'UTC' && h.server_utc_time) {
            rows.push({ label: 'server utc', text: h.server_utc_time })
        }
        return rows
    }
    const at = loadedAt.value == null ? Date.now() : loadedAt.value
    const zone = (Intl.DateTimeFormat().resolvedOptions().timeZone || '')
    const rows = [{
        label: 'browser time',
        text: localStampOf(at) + (zone && zone !== 'UTC' ? ' (' + zone + ')' : '')
    }]
    rows.push({ label: 'browser utc', text: utcStampOf(at) })
    return rows
})

const doorRows = computed(() => [
    { key: 'agents', label: 'agents', count: counts.value.agents, error: errors.agents },
    { key: 'targets', label: 'targets', count: counts.value.targets, error: errors.targets },
    { key: 'monitors', label: 'monitors', count: counts.value.monitors, error: errors.monitors }
])

const failedDoors = computed(() => {
    const names = []
    if (errors.agents) names.push('agents')
    if (errors.targets) names.push('targets')
    if (errors.monitors) names.push('monitors')
    if (errors.health) names.push('uptime')
    return names
})

const everAnswered = computed(() =>
    counts.value.agents !== null ||
    counts.value.targets !== null ||
    counts.value.monitors !== null ||
    health.value !== null
)

/* Same two banners as the dashboard: loud error while nothing has ever
 * answered, warn naming the failed doors once good numbers are on the
 * page (they stay — no door's failure zeroes another's numbers). */
const banner = computed(() => {
    const failed = failedDoors.value
    if (!everAnswered.value) {
        if (failed.length) {
            const msg = errors.agents || errors.targets || errors.monitors || errors.health
            return { kind: 'banner banner-error', text: 'api unreachable — ' + msg }
        }
        return null
    }
    if (failed.length) {
        return {
            kind: 'banner banner-warn',
            text: 'refresh failed — ' + failed.join(', ') + ' fetch failed; other doors keep their last numbers'
        }
    }
    return null
})

/* All four doors fire together; one failing must not zero the others. */
async function fetchAll() {
    loading.value = true
    try {
        const results = await Promise.allSettled([
            getJson('/cgi-bin/api/agents'),
            getJson('/cgi-bin/api/targets'),
            getJson('/cgi-bin/api/monitors'),
            fetchHealth()
        ])
        const [agentsR, targetsR, monitorsR, healthR] = results

        errors.agents = errors.targets = errors.monitors = errors.health = null

        if (agentsR.status === 'fulfilled') {
            counts.value.agents = countRows(agentsR.value.agents)
        } else {
            errors.agents = (agentsR.reason && agentsR.reason.message) || 'unknown error'
        }

        if (targetsR.status === 'fulfilled') {
            counts.value.targets = countRows(targetsR.value.targets)
        } else {
            errors.targets = (targetsR.reason && targetsR.reason.message) || 'unknown error'
        }

        if (monitorsR.status === 'fulfilled') {
            counts.value.monitors = countRows(monitorsR.value.monitors)
        } else {
            errors.monitors = (monitorsR.reason && monitorsR.reason.message) || 'unknown error'
        }

        if (healthR.status === 'fulfilled') {
            health.value = healthR.value
        } else {
            errors.health = (healthR.reason && healthR.reason.message) || 'unknown error'
        }

        if (results.some((r) => r.status === 'fulfilled')) {
            loadedAt.value = Date.now()
        }
    } finally {
        loading.value = false
    }
}

onMounted(() => {
    fetchAll()
    timer = setInterval(fetchAll, REFRESH_SECONDS * 1000)
})

onBeforeUnmount(() => {
    if (timer) clearInterval(timer)
})
</script>

<template>
    <header class="bar">
        <div class="bar-right">
            <span v-if="loadedAt" class="muted">updated {{ fmtClock(loadedAt) }}</span>
            <button class="btn" type="button" :disabled="loading" @click="fetchAll">
                {{ loading ? 'loading…' : 'refresh now' }}
            </button>
        </div>
    </header>

    <div v-if="banner" :class="banner.kind">{{ banner.text }}</div>

    <!-- Runtime: uptime from /health, the clock rows labeled for where
         they really come from. -->
    <section class="panel">
        <h2>runtime</h2>
        <ul class="runtime">
            <li v-if="health">
                uptime: {{ uptimeText }}
                <span v-if="errors.health" class="err-note">refresh failed</span>
            </li>
            <!-- No last good value to stand behind: say the door failed
                 instead of printing a number we do not have. -->
            <li v-else-if="errors.health" class="err-note">
                uptime fetch failed — {{ errors.health }}
            </li>
            <li v-for="c in clockRows" :key="c.label">{{ c.label }}: {{ c.text }}</li>
        </ul>
    </section>

    <!-- Statistics: the classic three-door table. A door whose fetch
         failed keeps its own row but shows 'fetch failed' instead of
         numbers; the other rows keep whatever they last delivered. -->
    <section class="panel">
        <h2>statistics</h2>
        <table>
            <thead>
            <tr>
                <th></th>
                <th class="num">active</th>
                <th class="num">disabled</th>
                <th class="num">total</th>
            </tr>
            </thead>
            <tbody>
            <tr v-for="door in doorRows" :key="door.key">
                <td>{{ door.label }}</td>
                <template v-if="door.error">
                    <td colspan="3" class="err-note">fetch failed</td>
                </template>
                <template v-else-if="door.count">
                    <td class="num">{{ door.count.active }}</td>
                    <td class="num">{{ door.count.disabled }}</td>
                    <td class="num">{{ door.count.total }}</td>
                </template>
                <template v-else>
                    <td colspan="3" class="muted">no numbers yet</td>
                </template>
            </tr>
            </tbody>
        </table>
    </section>
</template>

<style scoped>
/* The classic Runtime card was a bare list; same here, inside a panel. */
.runtime {
    list-style: none;
    margin: 0;
    padding: 0;
}

.runtime li {
    padding: 2px 0;
}
</style>