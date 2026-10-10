<!--
  Dashboard, ported from the look-only ui-preview page. Same data
  sources, same refresh rhythm, same honesty rules: when an API call
  fails the page says so instead of quietly showing zeros, and it keeps
  showing the last good numbers (marked stale) rather than blanking.
  Names link into the SPA detail pages wherever the row carries the
  record id; without an id the name stays plain text.

  No second title: the top nav already marks the active page
  ("Dashboard" sits underlined there), so the view opens on a slim
  toolbar whose right side keeps updated/stale/countdown/refresh. The
  page then reads top-down — the agent chips, the embedded search in
  MonitorSearch's compact mode (one bare row; results only after a term
  is submitted), the rollup as an inline SVG donut of up / degraded /
  down with the total in its hole, a second services ring beside it
  fed by the same payload (no chart library, no CDN; a fully empty
  estate — or an api build that predates the services keys — gets a
  muted note instead of a broken pie), then the down-monitors table
  and the down-services table under it. The old top-5-slowest panel is
  gone: the down lists are the actionable view, and /latency keeps the
  over-baseline report.
-->
<script setup>
import { computed, onBeforeUnmount, onMounted, reactive, ref } from 'vue'
import { getJson } from '../api'
import MonitorSearch from './MonitorSearch.vue'
import {
    REFRESH_SECONDS,
    downAge,
    downRowClass,
    agentClass,
    fmtClock,
    fmtDownSince
} from '../format'

const dash = ref(null)          // last good rollup; kept across failed refreshes
const agents = ref([])
const down = ref([])
/* Service parity rides the same /dashboard payload: counts for the
 * second ring, the down list for the table below. Zeroed on purpose —
 * an api build from before the services keys land has to read here as
 * an honest empty state, never as an exception. */
const svc = reactive({ total: 0, up: 0, down: 0, downList: [] })
const errors = reactive({ dash: null, agents: null, down: null })
const lastOk = ref(null)        // timestamp of the last fully good dashboard fetch
const countdown = ref(REFRESH_SECONDS)
const now = ref(Date.now())

let timer = null

const errorsAny = computed(() => !!(errors.dash || errors.agents || errors.down))

const banner = computed(() => {
    if (!dash.value) {
        // Nothing usable yet and the API is not answering: say so loudly.
        if (errors.dash) {
            return { kind: 'banner banner-error', text: 'api unreachable — ' + errors.dash }
        }
        return null
    }
    if (errorsAny.value) {
        return { kind: 'banner banner-warn', text: 'refresh failed — showing older data' }
    }
    return null
})

/* The stale marker kicks in once the last good data is two refresh
 * cycles old, which is the same rule the preview applied. */
const stale = computed(() =>
    !!(lastOk.value && (now.value - lastOk.value) > REFRESH_SECONDS * 2000)
)

/* One arc routine feeds both donuts: dash arcs on a circumference-100
 * ring (r 15.9155 in a 42-unit viewBox, the classic stroke-dasharray
 * trick), drawn top-clockwise in bucket order. Arcs come from the raw
 * counts so the ring closes exactly even if the API's rounded percents
 * don't sum to 100, and zero buckets are skipped rather than drawn as
 * invisible slivers. */
function arcSlices(total, buckets) {
    if (!total || total <= 0) return []
    const slices = []
    let start = 0
    for (const b of buckets) {
        if (!b.num) continue
        const pct = +((b.num / total) * 100).toFixed(2)
        slices.push({
            key: b.key,
            dasharray: pct + ' ' + +(100 - pct).toFixed(2),
            dashoffset: +(25 - start).toFixed(2)
        })
        start += pct
    }
    return slices
}

/* The monitor ring: up → degraded → down, same buckets as ever. */
const pieSlices = computed(() => {
    const d = dash.value
    if (!d) return []
    return arcSlices(Number(d.total) || 0, [
        { key: 'up', num: Number(d.up) || 0 },
        { key: 'degraded', num: Number(d.degraded) || 0 },
        { key: 'down', num: Number(d.down) || 0 }
    ])
})

/* The services ring shares the geometry and the status colors but has
 * no degraded bucket — a service check reads UP or DOWN, and an
 * unreadable state is a table concern, not a slice. */
const svcSlices = computed(() =>
    arcSlices(svc.total, [
        { key: 'up', num: svc.up },
        { key: 'down', num: svc.down }
    ]))

/* Services legend keeps zeros listed, same rule as the monitor legend:
 * the counts stay readable even when a bucket is empty. */
const svcLegendRows = computed(() => [
    { key: 'up', label: 'up', num: svc.up },
    { key: 'down', label: 'down', num: svc.down }
])

const svcAria = computed(() =>
    'up ' + svc.up + ', down ' + svc.down + ' of ' + svc.total + ' services')

/* The legend keeps every bucket — zeros included — so the counts stay
 * readable even when a bucket is empty (its slice is skipped). */
const legendRows = computed(() => {
    const d = dash.value
    if (!d) return []
    return [
        { key: 'up', label: 'up', num: d.up, pct: d.percent_up },
        { key: 'degraded', label: 'degraded', num: d.degraded, pct: d.percent_degraded },
        { key: 'down', label: 'down', num: d.down, pct: d.percent_down }
    ]
})

const pieAria = computed(() => {
    const d = dash.value
    if (!d) return ''
    return 'up ' + (Number(d.up) || 0) + ', degraded ' + (Number(d.degraded) || 0) +
        ', down ' + (Number(d.down) || 0) + ' of ' + d.total + ' monitors'
})

/* Down-service rows wear the state chip the services pages run:
 * UP green, DOWN red, anything else amber — an unreadable state must
 * never render as healthy. The reason token rides the chip's title so
 * the row itself stays one glance wide. */
function svcStateCls(s) {
    const st = String(s.last_state || 'UNKNOWN')
    if (st === 'UP') return 'chip-ok'
    if (st === 'DOWN') return 'chip-danger'
    return 'chip-warn'
}

async function fetchAll() {
    // All three calls fire together; one failing must not zero the others.
    const results = await Promise.allSettled([
        getJson('/cgi-bin/api/dashboard'),
        getJson('/cgi-bin/api/agents'),
        getJson('/cgi-bin/api/monitors?current_loss=100&is_active=1')
    ])
    const [dashR, agentsR, downR] = results

    errors.dash = errors.agents = errors.down = null

    if (dashR.status === 'fulfilled') {
        const rollup = dashR.value.dashboard || null
        if (rollup) {
            dash.value = rollup
            lastOk.value = Date.now()
            // The service half of the same payload is optional: an api
            // build from before services ships none of the keys, and
            // every read below keeps its zero/empty default — the page
            // renders the honest empty state instead of throwing. The
            // merged view also tolerates the keys sitting beside the
            // rollup rather than inside it.
            const both = Object.assign({}, dashR.value, rollup)
            svc.total = Number(both.services_total) || 0
            svc.up = Number(both.services_up) || 0
            svc.down = Number(both.services_down) || 0
            svc.downList = Array.isArray(both.down_services) ? both.down_services : []
        } else {
            errors.dash = 'dashboard payload had no rollup'
        }
    } else {
        errors.dash = (dashR.reason && dashR.reason.message) || 'unknown error'
    }

    if (agentsR.status === 'fulfilled') {
        agents.value = (agentsR.value.agents || []).filter((a) => Number(a.is_active) === 1)
    } else {
        errors.agents = (agentsR.reason && agentsR.reason.message) || 'unknown error'
    }

    if (downR.status === 'fulfilled') {
        down.value = downR.value.monitors || []
    } else {
        errors.down = (downR.reason && downR.reason.message) || 'unknown error'
    }
}

function tick() {
    now.value = Date.now()
    countdown.value -= 1
    if (countdown.value <= 0) {
        countdown.value = REFRESH_SECONDS
        fetchAll()
    }
}

function refreshNow() {
    countdown.value = REFRESH_SECONDS
    fetchAll()
}

onMounted(() => {
    fetchAll().then(() => {
        timer = setInterval(tick, 1000)
    })
})

onBeforeUnmount(() => {
    if (timer) clearInterval(timer)
})
</script>

<template>
    <header class="bar">
        <div class="bar-right">
            <span v-if="lastOk" class="muted">updated {{ fmtClock(lastOk) }}</span>
            <span v-if="stale" class="chip chip-stale">stale</span>
            <span class="muted">next in <span>{{ countdown }}</span>s</span>
            <button class="btn" type="button" @click="refreshNow">refresh now</button>
        </div>
    </header>

    <div v-if="banner" :class="banner.kind">{{ banner.text }}</div>

    <section class="agents">
        <span class="sec-label">agents</span>
        <template v-if="errors.agents">
            <span class="err-note">agents fetch failed</span>
        </template>
        <template v-else-if="!agents.length">
            <span class="muted">no active agents</span>
        </template>
        <template v-else>
            <!-- Chip names open the agent page; a row without an id
                 keeps the plain chip instead of linking nowhere. -->
            <template v-for="a in agents" :key="a.id">
                <router-link v-if="a.id" class="chip" :class="agentClass(a)"
                             :to="{ name: 'agent', params: { id: a.id } }"
                             :title="a.description || ''">{{ a.name }}</router-link>
                <span v-else class="chip" :class="agentClass(a)"
                      :title="a.description || ''">{{ a.name }}</span>
            </template>
        </template>
    </section>

    <MonitorSearch compact />

    <section v-if="dash" class="pie-row">
        <!-- Label, ring and counts travel as one cluster, mirroring
             .pie-svc, so the pair wraps as a unit and each ring keeps
             the same slot in its cluster however the row folds. The
             label sits above the empty-state branch, so the naming
             survives an all-zero estate just like the services one. -->
        <div class="pie-mon">
            <span class="sec-label">monitors</span>
            <template v-if="pieSlices.length">
                <svg class="pie" viewBox="0 0 42 42" role="img" :aria-label="pieAria">
                    <circle class="pie-track" cx="21" cy="21" r="15.9155" />
                    <circle v-for="s in pieSlices" :key="s.key" class="pie-slice" :class="'slice-' + s.key"
                            cx="21" cy="21" r="15.9155"
                            :stroke-dasharray="s.dasharray" :stroke-dashoffset="s.dashoffset" />
                    <text class="pie-total" x="21" y="23" text-anchor="middle">{{ dash.total }}</text>
                    <text class="pie-total-label" x="21" y="28.5" text-anchor="middle">total</text>
                </svg>
                <ul class="pie-legend">
                    <li v-for="r in legendRows" :key="r.key" :class="'legend-' + r.key">
                        <span class="swatch" :class="'swatch-' + r.key" aria-hidden="true"></span>
                        <span class="legend-label">{{ r.label }}</span>
                        <span class="legend-num">{{ r.num }}</span>
                        <span v-if="r.pct != null" class="muted">{{ r.pct }}%</span>
                    </li>
                </ul>
            </template>
            <!-- All-zero rollup: a muted note instead of an empty ring. -->
            <p v-else class="muted">no monitors tracked yet</p>
        </div>

        <!-- Same rollup fetch, second ring: service counts beside the
             monitor ones. An api build without the services keys zeroes
             this cluster out into the muted note, so the page keeps its
             shape while deploy order catches up. -->
        <div class="pie-svc">
            <span class="sec-label">services</span>
            <template v-if="svcSlices.length">
                <svg class="pie" viewBox="0 0 42 42" role="img" :aria-label="svcAria">
                    <circle class="pie-track" cx="21" cy="21" r="15.9155" />
                    <circle v-for="s in svcSlices" :key="s.key" class="pie-slice" :class="'slice-' + s.key"
                            cx="21" cy="21" r="15.9155"
                            :stroke-dasharray="s.dasharray" :stroke-dashoffset="s.dashoffset" />
                    <text class="pie-total" x="21" y="23" text-anchor="middle">{{ svc.total }}</text>
                    <text class="pie-total-label" x="21" y="28.5" text-anchor="middle">total</text>
                </svg>
                <ul class="pie-legend">
                    <li v-for="r in svcLegendRows" :key="r.key" :class="'legend-' + r.key">
                        <span class="swatch" :class="'swatch-' + r.key" aria-hidden="true"></span>
                        <span class="legend-label">{{ r.label }}</span>
                        <span class="legend-num">{{ r.num }}</span>
                    </li>
                </ul>
            </template>
            <!-- All-zero services estate: same honesty rule as above. -->
            <p v-else class="muted">no services tracked yet</p>
        </div>
    </section>

    <section class="panel">
        <h2>down monitors</h2>
        <div v-if="errors.down" class="err-note block">down-list fetch failed — this table is not trustworthy</div>
        <table>
            <thead>
            <tr>
                <th>monitor</th>
                <th>agent</th>
                <th>target</th>
                <th class="num">down since</th>
                <th class="num">duration</th>
            </tr>
            </thead>
            <tbody>
            <template v-if="!down.length">
                <tr>
                    <td colspan="5" class="muted">no down monitors</td>
                </tr>
            </template>
            <template v-else>
                <tr v-for="m in down" :key="m.id" :class="downRowClass(m)">
                    <td>
                        <router-link v-if="m.id" :to="{ name: 'monitor', params: { id: m.id } }">{{ m.description || m.id }}</router-link>
                        <template v-else>{{ m.description || m.id }}</template>
                    </td>
                    <td>
                        <router-link v-if="m.agent_id" :to="{ name: 'agent', params: { id: m.agent_id } }">{{ m.agent_name || '-' }}</router-link>
                        <template v-else>{{ m.agent_name || '-' }}</template>
                    </td>
                    <td>
                        <router-link v-if="m.target_id" :to="{ name: 'target', params: { id: m.target_id } }">{{ m.target_address || '-' }}</router-link>
                        <template v-else>{{ m.target_address || '-' }}</template>
                    </td>
                    <td class="num">{{ fmtDownSince(m.last_down) }}</td>
                    <td class="num">{{ downAge(m.last_down) }}</td>
                </tr>
            </template>
            </tbody>
        </table>
    </section>

    <!-- Service parity with the table above: same panel chrome, same
         link rules, fed by the down-services list the /dashboard payload
         carries (when the api build is new enough to ship it). -->
    <section class="panel">
        <h2>down services</h2>
        <table>
            <thead>
            <tr>
                <th>service</th>
                <th>agent</th>
                <th>target</th>
                <th>state</th>
                <th class="num">status code</th>
                <th class="num">last check</th>
            </tr>
            </thead>
            <tbody>
            <template v-if="!svc.downList.length">
                <tr>
                    <td colspan="6" class="muted">no down services</td>
                </tr>
            </template>
            <template v-else>
                <tr v-for="s in svc.downList" :key="s.id">
                    <td>
                        <router-link v-if="s.id" :to="{ name: 'service', params: { id: s.id } }">{{ s.description || s.id }}</router-link>
                        <template v-else>{{ s.description || s.id }}</template>
                    </td>
                    <td>
                        <router-link v-if="s.agent_id" :to="{ name: 'agent', params: { id: s.agent_id } }">{{ s.agent_name || '-' }}</router-link>
                        <template v-else>{{ s.agent_name || '-' }}</template>
                    </td>
                    <td>
                        <router-link v-if="s.target_id" :to="{ name: 'target', params: { id: s.target_id } }">{{ s.target_address || '-' }}</router-link>
                        <template v-else>{{ s.target_address || '-' }}</template>
                    </td>
                    <td>
                        <span class="chip" :class="svcStateCls(s)" :title="s.last_reason || ''">{{ s.last_state || 'UNKNOWN' }}</span>
                    </td>
                    <!-- A code of 0 is a transport failure, not a real status:
                         it still prints, only a missing one reads as '-'. -->
                    <td class="num">{{ s.last_status_code ?? '-' }}</td>
                    <td class="num">{{ fmtDownSince(s.last_check) }}</td>
                </tr>
            </template>
            </tbody>
        </table>
    </section>

</template>

<style scoped>
/* Chip links keep the plain-chip look: the global anchor rule paints
 * every link green, which would turn the status chips into buttons. */
.agents a.chip {
    color: inherit;
    text-decoration: none;
}

/* The nav already marks the active page, so this bar carries no title:
 * the refresh cluster is the whole bar, pinned right. */
.bar {
    justify-content: flex-end;
}

/* Rollup row: the monitor donut and the services donut centered as a
 * pair. The colors ride the same status vars the old stat cards
 * painted; presentation attributes cannot take var(), so the slices
 * get their stroke from these scoped rules. Below the pair's side-by-
 * side budget the media query folds the clusters onto their own
 * centered lines instead of pushing the page wide. */
.pie-row {
    display: flex;
    justify-content: center;
    align-items: center;
    gap: 18px;
    margin-bottom: 14px;
    flex-wrap: wrap;
}

/* Each ring travels with its label and legend as one wrap-safe
 * cluster, and the ring holds the same slot in both — that symmetry
 * is what keeps the two rings lining up however the row folds. */
.pie-mon {
    display: flex;
    align-items: center;
    gap: 18px;
}

/* The services cluster hangs off the monitor one behind a hairline and
 * reuses the ring classes wholesale, so the two donuts can never drift
 * apart visually; the sec-label does the naming. */
.pie-svc {
    display: flex;
    align-items: center;
    gap: 14px;
    border-left: 1px solid var(--panel-edge);
    padding-left: 18px;
}

/* Narrow widths: the clusters turn caption-over-ring-over-legend — two
 * rings share one centered axis while a column pair still fits side by
 * side, then it stacks cleanly. The hairline divider belongs to the
 * side-by-side arrangement only. */
@media (max-width: 1020px) {
    .pie-mon,
    .pie-svc {
        flex-direction: column;
    }

    .pie-svc {
        border-left: none;
        padding-left: 0;
    }
}

.pie {
    width: 116px;
    height: 116px;
    flex: none;
}

.pie-track {
    fill: none;
    stroke: var(--panel-edge);
    stroke-width: 5;
}

.pie-slice {
    fill: none;
    stroke-width: 5;
    transition: stroke-dasharray .2s, stroke-dashoffset .2s;
}

.slice-up { stroke: var(--up); }
.slice-degraded { stroke: var(--warn); }
.slice-down { stroke: var(--danger); }

.pie-total {
    fill: var(--text);
    font-size: 7px;
    font-weight: 600;
}

.pie-total-label {
    fill: var(--muted);
    font-size: 3.2px;
    letter-spacing: .4px;
    text-transform: uppercase;
}

.pie-legend {
    list-style: none;
    margin: 0;
    padding: 0;
    display: flex;
    flex-wrap: wrap;
    justify-content: center;
    gap: 16px;
    font-size: 12px;
}

.pie-legend li {
    display: flex;
    align-items: center;
    gap: 5px;
}

.legend-num {
    font-weight: 600;
}

.swatch {
    width: 9px;
    height: 9px;
    border-radius: 2px;
    flex: none;
}

.swatch-up { background: var(--up); }
.swatch-degraded { background: var(--warn); }
.swatch-down { background: var(--danger); }
</style>