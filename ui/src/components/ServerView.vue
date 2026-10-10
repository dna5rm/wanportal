<!--
  Server page, ported from htdocs/server.php. Same data doors as the
  classic page plus /services, the fourth statistics row: the public
  /agents, /targets, /monitors and /services listings for the
  active/disabled/total counts, and /health for the uptime. One door
  needs its own plumbing: /health answers a
  status:'ok' envelope, and the shared getJson only accepts the
  'success' one, so uptime rides a local fetcher with the same
  transport the api module applies (timeout, credentials omitted,
  bearer header when the tab holds a token). Routed through getJson,
  a perfectly healthy API would read as a failed door.

  Honesty rules are the dashboard's: the five calls fire together
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
  so the bar holds just the one sanctioned external door — the public
  repo, opening in its own tab under the same exception the service
  URIs ride — plus the updated stamp and the refresh.

  The page is mixed-auth by design, and the split is worth spelling
  out. The five public doors — the four listings plus /health — answer
  for everyone, and two extra lines ride the data they already
  deliver: heartbeat freshness from the /agents rows and collection
  recency from the /monitors last_update stamps, both parsed through
  parseApiUtc because the api writes naive UTC that a bare Date.parse
  would read as the viewer's own wall clock. The runtime-stats door is
  different: it reports host load/memory/disk and agent versions, is
  JWT-gated server side, and is asked for only once a tab token
  validates through getSession(). An anonymous visitor never calls it,
  and when it 401s (expired session), 404s (api old enough to predate
  the door) or breaks, the block simply does not render — no banner,
  nothing else zeroed. Agent versions ride only that gated door: the
  public /agents SELECT carries no version field, so a signed-out
  visitor sees the counts without versions rather than a placeholder.
-->
<script setup>
import { computed, onBeforeUnmount, onMounted, reactive, ref } from 'vue'
import { getJson } from '../api'
import { authHeaders, getSession, getToken } from '../session'
import { AGENT_STALE_MS, downAge, fmtClock, parseApiUtc } from '../format'

/* server.php meta-refreshes every 300s; same rhythm here. */
const REFRESH_SECONDS = 300

/* counts: per-door {active, disabled, total} or null until the door
 * first answers; health: the /health payload; errors: last failure
 * message per door. Failed refreshes only ever set errors — the good
 * values from earlier cycles stay put. */
const counts = ref({ agents: null, targets: null, monitors: null, services: null })
const health = ref(null)
/* Listing rows for the freshness lines: the tallies above only count,
 * these keep the stamps the doors delivered. Filled only on a
 * fulfilled door, never emptied by a failing one — the same
 * last-good-numbers rule. */
const agentRows = ref(null)
const monitorRows = ref(null)
/* The gated stats payload: null for anonymous visitors, for a session
 * that would not validate, and whenever the door is absent or broken. */
const runtimeStats = ref(null)
const errors = reactive({ agents: null, targets: null, monitors: null, services: null, health: null })
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

/* The runtime-stats door is signed-in-only on both ends: with no token
 * in the tab it is never even called — an anonymous visitor's traffic
 * is exactly what this page always made. With a token, the session
 * door validates it first (dropping an expired one on the way), and
 * only a live session opens the stats fetch. Any failure — 401, a 404
 * from an api too old to ship the door, transport trouble, an odd
 * payload — clears the block instead of raising a banner: the host
 * numbers are a bonus for signed-in visitors, not a headline error.
 * The block is cleared only when a refresh cannot renew it, so a
 * signed-in page keeps its last good numbers mid-cycle, like the
 * doors above keep theirs. */
async function refreshRuntimeStats() {
    if (!getToken()) {
        runtimeStats.value = null
        return
    }
    const session = await getSession()
    if (!session || !session.authenticated) {
        runtimeStats.value = null
        return
    }
    try {
        runtimeStats.value = await getJson('/cgi-bin/api/runtime-stats')
    } catch {
        runtimeStats.value = null
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

/* Freshness of the fleet, from the heartbeat stamps the /agents door
 * already delivered: reporting means last_seen within the stale window
 * (an hour, the dashboard's chip rule), stale is everything else — an
 * old heartbeat or none — so the two always add up to the whole
 * listing, disabled or not. */
const agentFreshText = computed(() => {
    const rows = agentRows.value
    if (!rows) return ''
    let reporting = 0
    let stale = 0
    const now = Date.now()
    for (const a of rows) {
        const t = parseApiUtc(a.last_seen)
        if (!isNaN(t) && now - t <= AGENT_STALE_MS) reporting++
        else stale++
    }
    return 'agents reporting: ' + reporting + ' of ' + rows.length + ' (' + stale + ' stale)'
})

/* Data-fresh stamp: the newest monitor last_update the /monitors door
 * delivered, shown under the api's own naive UTC form (parsed zone-
 * tagged, printed labeled utc) with a dashboard-style age beside it.
 * No parseable stamp anywhere in the payload renders no line at all. */
const collectionText = computed(() => {
    const rows = monitorRows.value
    if (!rows || !rows.length) return ''
    let best = NaN
    for (const m of rows) {
        const t = parseApiUtc(m.last_update)
        if (!isNaN(t) && (isNaN(best) || t > best)) best = t
    }
    if (isNaN(best)) return ''
    const stamp = utcStampOf(best)
    return 'data fresh as of ' + stamp + ' utc (' + downAge(stamp) + ')'
})

/* The gated payload names its block runtime (the shipped endpoint's
 * envelope); runtime_stats and a top-level shape read too, so the
 * view tolerates either placement. */
const statsBlock = computed(() => {
    const p = runtimeStats.value
    if (!p || typeof p !== 'object') return null
    if (p.runtime && typeof p.runtime === 'object') return p.runtime
    if (p.runtime_stats && typeof p.runtime_stats === 'object') return p.runtime_stats
    return p
})

/* A percent under the object spellings the endpoint might use — or,
 * via the flat fallback, plain host-level fields. NaN never passes, so
 * junk or missing numbers simply do not render. */
function pctField(obj) {
    if (!obj || typeof obj !== 'object') return null
    for (const key of ['percent', 'used_percent', 'use_pct']) {
        if (obj[key] !== undefined && obj[key] !== null) {
            const n = Number(obj[key])
            if (isFinite(n)) return n
        }
    }
    return null
}

function flatPct(obj, ...keys) {
    for (const k of keys) {
        if (obj && obj[k] !== undefined && obj[k] !== null) {
            const n = Number(obj[k])
            if (isFinite(n)) return n
        }
    }
    return null
}

/* Load as the endpoint's {one_min, five_min, fifteen_min} readout — or
 * tolerant fallbacks: a three-wide array, a string, or the load1/5/15
 * trio. The trio must be complete; a partial one renders no row rather
 * than half a load figure. */
function loadText(h) {
    const src = (h.load !== undefined && h.load !== null) ? h.load
        : (h.loadavg !== undefined && h.loadavg !== null) ? h.loadavg
        : undefined
    if (src === undefined || src === null) return null
    if (Array.isArray(src)) return src.length ? src.map((v) => String(v)).join(' ') : null
    if (typeof src === 'string') { const s = src.trim(); return s || null }
    if (typeof src === 'object') {
        const vals = [['one_min', 'load1'], ['five_min', 'load5'], ['fifteen_min', 'load15']]
            .map(([a, b]) => (src[a] !== undefined ? src[a] : src[b]))
        if (vals.some((v) => v === undefined || v === null)) return null
        return vals.map((v) => String(v)).join(' ')
    }
    return null
}

/* Memory: the endpoint totals kB and available kB, so used percent is
 * one subtraction away; a percent spelling (older shape) reads first.
 * Available can out-total total on skewed /proc rows — clamped to 0,
 * never a negative percent. */
function memText(h) {
    const m = h.memory
    const pct = pctField(m) ?? flatPct(h, 'mem_percent', 'memory_pct')
    if (pct !== null) return pct + '%'
    if (m && typeof m === 'object') {
        const total = Number(m.total_kb)
        const avail = Number(m.available_kb)
        if (isFinite(total) && isFinite(avail) && total > 0) {
            return Math.max(0, Math.round(((total - avail) / total) * 100)) + '%'
        }
    }
    return null
}

/* Host rows: whatever the payload resolves renders, whatever does not
 * shrinks the block instead of throwing. */
const hostRows = computed(() => {
    const h = statsBlock.value && statsBlock.value.host
    if (!h || typeof h !== 'object') return []
    const rows = []
    const load = loadText(h)
    if (load) rows.push({ label: 'load', text: load })
    const mem = memText(h)
    if (mem !== null) rows.push({ label: 'memory', text: mem })
    const disk = pctField(h.disk) ?? flatPct(h, 'disk_percent', 'disk_pct')
    if (disk !== null) rows.push({ label: 'disk', text: disk + '%' })
    return rows
})

/* Agent versions ride only the gated door — the public /agents SELECT
 * (id, name, address, description, last_seen, is_active) carries no
 * version field. The shipped endpoint answers a census: grouped
 * {agent_version, count} rows, with the never-reported group under a
 * null key sorted last so the block itself spots an un-upgraded fleet.
 * Each row prints a sentence that carries its own meaning — '1 agent on
 * 0.2.0', '2 agents on 1.0.0' — and the never-reported group spells out
 * why it is unknown: those agents never reported a version, and none is
 * invented for them. version/count stay the plain census values; text
 * is the line the panel prints. A per-agent
 * {name, agent_version} shape stays readable as a fallback, printed as
 * 'agent <name> — <version>', and rows naming no version drop out there
 * rather than print blanks. */
const versionRows = computed(() => {
    const s = statsBlock.value
    if (!s) return []
    const out = []
    if (Array.isArray(s.agent_versions)) {
        for (const row of s.agent_versions) {
            if (!row) continue
            const v = row.agent_version
            const count = row.count === undefined || row.count === null ? 0 : Number(row.count)
            const version = (v === undefined || v === null || v === '') ? null : String(v)
            const named = version === null ? 'unknown (never reported a version)' : version
            const text = count === 1 ? '1 agent on ' + named : count + ' agents on ' + named
            out.push({ version, count, text })
        }
        return out
    }
    const list = Array.isArray(s.agents) ? s.agents : []
    for (const a of list) {
        if (!a) continue
        const v = a.agent_version !== undefined ? a.agent_version : a.version
        if (v === undefined || v === null || v === '') continue
        const agent = a.name || a.id || 'agent'
        out.push({ agent, version: String(v), text: 'agent ' + agent + ' — ' + String(v) })
    }
    return out
})

const doorRows = computed(() => [
    { key: 'agents', label: 'agents', count: counts.value.agents, error: errors.agents },
    { key: 'targets', label: 'targets', count: counts.value.targets, error: errors.targets },
    { key: 'monitors', label: 'monitors', count: counts.value.monitors, error: errors.monitors },
    { key: 'services', label: 'services', count: counts.value.services, error: errors.services }
])

const failedDoors = computed(() => {
    const names = []
    if (errors.agents) names.push('agents')
    if (errors.targets) names.push('targets')
    if (errors.monitors) names.push('monitors')
    if (errors.services) names.push('services')
    if (errors.health) names.push('uptime')
    return names
})

const everAnswered = computed(() =>
    counts.value.agents !== null ||
    counts.value.targets !== null ||
    counts.value.monitors !== null ||
    counts.value.services !== null ||
    health.value !== null
)

/* Same two banners as the dashboard: loud error while nothing has ever
 * answered, warn naming the failed doors once good numbers are on the
 * page (they stay — no door's failure zeroes another's numbers). */
const banner = computed(() => {
    const failed = failedDoors.value
    if (!everAnswered.value) {
        if (failed.length) {
            const msg = errors.agents || errors.targets || errors.monitors || errors.services || errors.health
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

/* All five doors fire together; one failing must not zero the others. */
async function fetchAll() {
    loading.value = true
    try {
        const results = await Promise.allSettled([
            getJson('/cgi-bin/api/agents'),
            getJson('/cgi-bin/api/targets'),
            getJson('/cgi-bin/api/monitors'),
            getJson('/cgi-bin/api/services'),
            fetchHealth()
        ])
        const [agentsR, targetsR, monitorsR, servicesR, healthR] = results

        errors.agents = errors.targets = errors.monitors = errors.services = errors.health = null

        if (agentsR.status === 'fulfilled') {
            counts.value.agents = countRows(agentsR.value.agents)
            agentRows.value = agentsR.value.agents || []
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
            monitorRows.value = monitorsR.value.monitors || []
        } else {
            errors.monitors = (monitorsR.reason && monitorsR.reason.message) || 'unknown error'
        }

        if (servicesR.status === 'fulfilled') {
            counts.value.services = countRows(servicesR.value.services)
        } else {
            errors.services = (servicesR.reason && servicesR.reason.message) || 'unknown error'
        }

        if (healthR.status === 'fulfilled') {
            health.value = healthR.value
        } else {
            errors.health = (healthR.reason && healthR.reason.message) || 'unknown error'
        }

        if (results.some((r) => r.status === 'fulfilled')) {
            loadedAt.value = Date.now()
        }

        /* Same five-minute rhythm as the public doors, but outside
         * their door accounting: its failures never name a door in
         * the banner and never blank the counts table. */
        await refreshRuntimeStats()
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
            <!-- The one sanctioned external door on the page, same
                 exception class as the service URIs: the public repo
                 opens in its own tab so a click can never navigate
                 away from a live refresh loop. -->
            <a class="btn doc-icon" href="https://github.com/dna5rm/wanportal" target="_blank" rel="noopener noreferrer" title="wanportal source on GitHub" aria-label="wanportal source on GitHub">
                <svg viewBox="0 0 16 16" width="15" height="15" aria-hidden="true" fill="currentColor">
                    <path d="M8 0C3.58 0 0 3.58 0 8c0 3.54 2.29 6.53 5.47 7.59.4.07.55-.17.55-.38 0-.19-.01-.82-.01-1.49-2.01.37-2.53-.49-2.69-.94-.09-.23-.48-.94-.82-1.13-.28-.15-.68-.52-.01-.53.63-.01 1.08.58 1.23.82.72 1.21 1.87.87 2.33.66.07-.52.28-.87.51-1.07-1.78-.2-3.64-.89-3.64-3.95 0-.87.31-1.59.82-2.15-.08-.2-.36-1.02.08-2.12 0 0 .67-.21 2.2.82.64-.18 1.32-.27 2-.27.68 0 1.36.09 2 .27 1.53-1.04 2.2-.82 2.2-.82.44 1.1.16 1.92.08 2.12.51.56.82 1.27.82 2.15 0 3.07-1.87 3.75-3.65 3.95.29.25.54.73.54 1.48 0 1.07-.01 1.93-.01 2.2 0 .21.15.46.55.38A8.01 8.01 0 0 0 16 8c0-4.42-3.58-8-8-8z"/>
                </svg>
            </a>
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
            <!-- Signal from rows the doors already delivered: heartbeat
                 freshness from /agents, collection recency from
                 /monitors. Each renders only while its door answered. -->
            <li v-if="agentFreshText">{{ agentFreshText }}</li>
            <li v-if="collectionText">{{ collectionText }}</li>
            <li v-for="c in clockRows" :key="c.label">{{ c.label }}: {{ c.text }}</li>
        </ul>
    </section>

    <!-- Statistics: the classic table with services as the fourth row.
         A door whose fetch failed keeps its own row but shows 'fetch
         failed' instead of numbers; the other rows keep whatever they
         last delivered. -->
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

    <!-- Signed-in bonus blocks, fed by the JWT-gated runtime-stats door:
         host load/memory/disk and the agent versions the public listing
         cannot carry. Whatever the payload failed to resolve here simply
         does not render, and an anonymous visitor gets neither block. -->
    <section v-if="hostRows.length" class="panel">
        <h2>host</h2>
        <ul class="runtime">
            <li v-for="(r, i) in hostRows" :key="i">{{ r.label }}: {{ r.text }}</li>
        </ul>
    </section>

    <section v-if="versionRows.length" class="panel">
        <h2>agent versions</h2>
        <ul class="runtime">
            <li v-for="(v, i) in versionRows" :key="i">{{ v.text }}</li>
        </ul>
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

/* The repo door wears the shared .btn chrome but squares up into an
 * icon box: the octocat centers on the same fill and edge instead of
 * stretching into a wide text-shaped button. */
.doc-icon {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    padding: 5px;
    line-height: 1;
}
</style>