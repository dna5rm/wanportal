<!--
  Monitor detail page, ported read-only from monitor.php. Shows the
  same identity card (agent/target links, protocol, active state with
  the disable reasons), the sample counters and timestamps, and the
  latency numbers — plus a performance graph drawn from the same /rrd
  endpoint the classic page charts. The graph is a hand-rolled inline
  SVG instead of the classic page's CDN-loaded Chart.js: this app
  bundles everything and never pulls chart libraries from a CDN. One
  unit trap lives in that endpoint: samples are stamped in epoch
  seconds, and src/rrdChart.js rescales them to the milliseconds the
  window math runs in.

  Honesty rules carried over from the dashboard: when a request fails
  the page says so instead of quietly showing zeros, and each request
  fails on its own (a broken graph never blanks the monitor details).
-->
<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import { getJson, postJson } from '../api'
import { fmtClock, lossClass } from '../format'
import { buildChartView } from '../rrdChart'
import {
    activeChipCls,
    detailLink,
    humanErr,
    idFromLocation,
    inactiveReasons,
    isoMinutes,
    isDataFresh,
    numOr,
    protoLabel,
    rrdDataUrl,
    rrdRawUrl,
    rowActive,
    stampOr
} from './detailShared'

const props = defineProps({
    id: { type: String, default: '' }
})

const monitorId = computed(() => idFromLocation(props.id))

const monitor = ref(null)
const errors = reactive({ detail: null, chart: null })
const loadingDetail = ref(false)
const loadingChart = ref(false)
const lastOk = ref(null)

/* Counter reset: confirm first (it wipes samples, not the config),
 * then re-fetch so the numbers on screen match what the API keeps. */
const resetBusy = ref(false)
const resetError = ref('')

async function resetMonitor() {
    if (resetBusy.value || !monitorId.value) return
    if (!window.confirm('Reset this monitor? Counters and latency history are cleared.')) return
    resetBusy.value = true
    resetError.value = ''
    try {
        await postJson('/cgi-bin/api/monitor/' + encodeURIComponent(monitorId.value) + '/reset')
        await fetchDetail()
    } catch (err) {
        resetError.value = humanErr(err, 'reset failed')
    } finally {
        resetBusy.value = false
    }
}

/* Chart window: defaults to the same 3 hours the classic page loads,
 * with the same one-click presets. start/end are ms epochs; the text
 * inputs carry their minute-resolution UTC form. */
const startMs = ref(Date.now() - 3 * 3600 * 1000)
const endMs = ref(Date.now())
const startText = ref(isoMinutes(startMs.value))
const endText = ref(isoMinutes(endMs.value))
const chartRows = ref(null)

async function fetchDetail() {
    errors.detail = null
    loadingDetail.value = true
    try {
        const r = await getJson('/cgi-bin/api/monitors/' + encodeURIComponent(monitorId.value))
        monitor.value = r.monitor || null
        if (monitor.value) lastOk.value = Date.now()
        else errors.detail = 'monitor payload was empty'
    } catch (err) {
        monitor.value = null
        errors.detail = humanErr(err, 'monitor not found')
    } finally {
        loadingDetail.value = false
    }
}

async function fetchChart() {
    errors.chart = null
    loadingChart.value = true
    try {
        const r = await getJson(rrdDataUrl(monitorId.value, startMs.value, endMs.value))
        chartRows.value = r.data || []
        if (!chartRows.value.length) errors.chart = 'rrd returned no samples for this range'
    } catch (err) {
        chartRows.value = null
        errors.chart = humanErr(err, 'no rrd file yet for this monitor')
    } finally {
        loadingChart.value = false
    }
}

function fetchAll() {
    if (!monitorId.value) {
        errors.detail = 'no monitor id found in the url'
        return
    }
    fetchDetail()
    fetchChart()
}

onMounted(fetchAll)

/* Presets mirror the classic page: last 1h / 6h / 24h / 3d. */
function setRangeHours(hours) {
    endMs.value = Date.now()
    startMs.value = endMs.value - hours * 3600 * 1000
    startText.value = isoMinutes(startMs.value)
    endText.value = isoMinutes(endMs.value)
    fetchChart()
}

/* The range inputs carry UTC minute strings; parse them back as UTC to
 * match the classic page's convention for what those strings mean. */
function applyTextRange() {
    const s = Date.parse(startText.value + ':00Z')
    const e = Date.parse(endText.value + ':00Z')
    if (Number.isNaN(s) || Number.isNaN(e) || e <= s) {
        errors.chart = 'invalid time range — use YYYY-MM-DD HH:MM in UTC, with "to" after "from"'
        return
    }
    startMs.value = s
    endMs.value = e
    fetchChart()
}

/* ---- SVG chart geometry (no chart library, just coordinates) ----
 * The math lives in ../rrdChart so it can be tested without mounting
 * the page; this only hands over the current window and the panel's
 * pixel box. Samples arrive as epoch seconds and the helper's
 * pointsFromRrd does the *1000 into the ms this window runs on. */
const CW = 760, CH = 250, PADL = 46, PADR = 46, PADT = 12, PADB = 24

const chartView = computed(() => buildChartView({
    rows: chartRows.value,
    startMs: startMs.value,
    endMs: endMs.value,
    CW, CH,
    pads: { left: PADL, right: PADR, top: PADT, bottom: PADB }
}))

/* ---- read-only chrome ---- */

const reasons = computed(() => (monitor.value ? inactiveReasons(monitor.value) : []))

const protocolText = computed(() =>
    monitor.value ? protoLabel(monitor.value.protocol, monitor.value.port) : '-'
)
</script>

<template>
    <header class="bar">
        <div class="bar-title">
            <h1>monitor</h1>
            <span v-if="monitor" class="muted">{{ monitor.description || monitor.id }}</span>
        </div>
        <div class="bar-right">
            <template v-if="monitor">
                <span class="chip" :class="activeChipCls(monitor.is_active)">
                    {{ rowActive(monitor) ? 'active' : 'inactive' }}
                </span>
                <span class="chip" :class="isDataFresh(monitor) ? 'chip-ok' : 'chip-stale'">
                    {{ isDataFresh(monitor) ? 'fresh data' : 'stale data' }}
                </span>
                <span v-if="Number(monitor.latency_flag) === 1" class="chip chip-danger"
                      title="current median is above the avg + 2 stddev threshold">latency spike</span>
            </template>
            <span v-if="loadingDetail" class="muted">loading…</span>
            <span v-if="lastOk" class="muted">updated {{ fmtClock(lastOk) }}</span>
            <button class="btn" type="button" :disabled="loadingDetail" @click="fetchAll">refresh</button>
            <router-link v-if="monitorId" class="btn"
                         :to="{ name: 'monitor-edit', params: { id: monitorId } }">edit</router-link>
            <button v-if="monitorId" class="btn" type="button" :disabled="resetBusy"
                    @click="resetMonitor">{{ resetBusy ? 'resetting…' : 'reset' }}</button>
            <a v-if="monitorId" class="btn" :href="rrdRawUrl(monitorId)" target="_blank" rel="noopener">raw data</a>
        </div>
    </header>

    <div v-if="errors.detail" class="banner banner-error">
        monitor api: {{ errors.detail }} — nothing below is live data.
        Classic page: <a :href="'/monitor.php?id=' + encodeURIComponent(monitorId)">monitor.php</a>
    </div>
    <div v-if="resetError" class="banner banner-warn">
        reset failed: {{ resetError }} — counters were not cleared.
    </div>

    <div v-if="monitor" class="cols">
        <div class="col-side">
            <section class="panel">
                <h2>details</h2>
                <div class="kv"><span class="k">id</span><span class="v mono wrap">{{ monitor.id }}</span></div>
                <div class="kv">
                    <span class="k">agent</span>
                    <span class="v">
                        <a :href="detailLink('agent', monitor.agent_id)"
                           :title="'agent ' + monitor.agent_id">{{ monitor.agent_name || '-' }}</a>
                    </span>
                </div>
                <div class="kv">
                    <span class="k">target</span>
                    <span class="v">
                        <a :href="detailLink('target', monitor.target_id)"
                           :title="'target ' + monitor.target_id">{{ monitor.target_address || '-' }}</a>
                    </span>
                </div>
                <div class="kv">
                    <span class="k">protocol</span>
                    <span class="v" :title="'dscp ' + (monitor.dscp || '-')">{{ protocolText }}</span>
                </div>
                <div class="kv"><span class="k">dscp</span><span class="v">{{ monitor.dscp || '-' }}</span></div>
                <div class="kv">
                    <span class="k">status</span>
                    <span class="v">
                        <span class="chip" :class="activeChipCls(monitor.is_active)">
                            {{ rowActive(monitor) ? 'active' : 'inactive' }}
                        </span>
                        <span v-if="reasons.length" class="muted small">({{ reasons.join(', ') }})</span>
                    </span>
                </div>
                <div class="kv">
                    <span class="k">polling</span>
                    <span class="v">{{ numOr(monitor.pollcount) }}x every {{ numOr(monitor.pollinterval) }}s</span>
                </div>
            </section>

            <section class="panel">
                <h2>statistics</h2>
                <div class="kv"><span class="k">total samples</span><span class="v">{{ numOr(monitor.sample) }}</span></div>
                <div class="kv"><span class="k">total down events</span><span class="v">{{ numOr(monitor.total_down) }}</span></div>
                <div class="kv"><span class="k">last update</span><span class="v mono">{{ stampOr(monitor.last_update) }}</span></div>
                <div class="kv"><span class="k">last down</span><span class="v mono">{{ stampOr(monitor.last_down) }}</span></div>
                <div class="kv"><span class="k">last clear</span><span class="v mono">{{ stampOr(monitor.last_clear) }}</span></div>
            </section>
        </div>

        <div class="col-main">
            <section class="panel">
                <h2>latency</h2>
                <table>
                    <thead>
                    <tr>
                        <th></th>
                        <th class="num">median</th>
                        <th class="num">min</th>
                        <th class="num">max</th>
                        <th class="num">std dev</th>
                        <th class="num">loss</th>
                    </tr>
                    </thead>
                    <tbody>
                    <tr>
                        <td>current</td>
                        <td class="num">{{ numOr(monitor.current_median) }}</td>
                        <td class="num">{{ numOr(monitor.current_min) }}</td>
                        <td class="num">{{ numOr(monitor.current_max) }}</td>
                        <td class="num">{{ numOr(monitor.current_stddev) }}</td>
                        <td class="num">
                            <span v-if="monitor.current_loss !== null && monitor.current_loss !== undefined"
                                  class="chip" :class="lossClass(monitor.current_loss)">
                                {{ numOr(monitor.current_loss, '%') }}
                            </span>
                            <span v-else>-</span>
                        </td>
                    </tr>
                    <tr>
                        <td>lifetime avg</td>
                        <td class="num">{{ numOr(monitor.avg_median) }}</td>
                        <td class="num">{{ numOr(monitor.avg_min) }}</td>
                        <td class="num">{{ numOr(monitor.avg_max) }}</td>
                        <td class="num">{{ numOr(monitor.avg_stddev) }}</td>
                        <td class="num">
                            <span v-if="monitor.avg_loss !== null && monitor.avg_loss !== undefined"
                                  class="chip" :class="lossClass(monitor.avg_loss)">
                                {{ numOr(monitor.avg_loss, '%') }}
                            </span>
                            <span v-else>-</span>
                        </td>
                    </tr>
                    </tbody>
                </table>
                <p class="muted small">
                    latency threshold (avg + 2&sigma;): {{ numOr(monitor.latency_threshold_ms) }} ms
                    <template v-if="Number(monitor.latency_flag) === 1">
                        — <span class="err-note">flag raised: current median is over it</span>
                    </template>
                    <template v-else>· no latency flag right now</template>
                </p>
            </section>

            <section class="panel">
                <h2>performance graph</h2>
                <div class="presets">
                    <button class="btn" type="button" @click="setRangeHours(1)">last 1h</button>
                    <button class="btn" type="button" @click="setRangeHours(6)">last 6h</button>
                    <button class="btn" type="button" @click="setRangeHours(24)">last 24h</button>
                    <button class="btn" type="button" @click="setRangeHours(72)">last 3d</button>
                    <span v-if="loadingChart" class="muted small">loading chart…</span>
                </div>

                <div v-if="errors.chart" class="err-note block">{{ errors.chart }}</div>
                <div v-else-if="!chartView" class="muted">no graph data</div>
                <template v-else>
                    <div class="legend muted small">
                        <span class="swatch swatch-rtt"></span> rtt ms
                        <span v-if="chartView.rttStats">
                            (last {{ chartView.rttStats.last }} · avg {{ chartView.rttStats.avg }}
                            · min {{ chartView.rttStats.min }} · max {{ chartView.rttStats.max }})
                        </span>
                        <span class="swatch swatch-loss"></span> loss %
                        <span v-if="chartView.lossStats">
                            (last {{ chartView.lossStats.last }}% · avg {{ chartView.lossStats.avg }}%)
                        </span>
                    </div>
                    <svg class="rrd-svg" :viewBox="`0 0 ${CW} ${CH}`" role="img"
                         aria-label="response time and packet loss over the selected range">
                        <line v-for="g in chartView.grid" :key="'g' + g.y" class="gridline"
                              :x1="PADL" :x2="CW - PADR" :y1="g.y" :y2="g.y" />
                        <text v-for="g in chartView.grid" :key="'r' + g.y" class="axis"
                              :x="PADL - 6" :y="g.y + 3" text-anchor="end">{{ g.rtt }}</text>
                        <text v-for="g in chartView.grid" :key="'l' + g.y" class="axis"
                              :x="CW - PADR + 6" :y="g.y + 3" text-anchor="start">{{ g.loss }}</text>
                        <text v-for="(l, i) in chartView.xLabels" :key="'x' + i" class="axis"
                              :x="l.x" :y="CH - 4" text-anchor="middle">{{ l.text }}</text>
                        <path class="line-rtt" :d="chartView.rttPath" />
                        <path class="line-loss" :d="chartView.lossPath" />
                    </svg>
                    <p class="muted small">
                        left scale: rtt ms (max {{ chartView.rttMax }}) · right scale: loss % · times in UTC
                    </p>
                </template>

                <form class="rangeform" @submit.prevent="applyTextRange">
                    <label>from <input v-model="startText" type="text" size="16" spellcheck="false"></label>
                    <label>to <input v-model="endText" type="text" size="16" spellcheck="false"></label>
                    <button class="btn" type="submit">apply</button>
                    <span class="muted small">utc · YYYY-MM-DD HH:MM</span>
                </form>
            </section>
        </div>
    </div>

    <footer class="muted">
        vue monitor detail; classic page at
        <a :href="'/monitor.php?id=' + encodeURIComponent(monitorId)">monitor.php</a>
    </footer>
</template>

<style scoped>
/* Two-column layout like the classic page: identity card on the left,
 * latency and graph on the right; collapses on narrow screens. */
.cols {
    display: grid;
    grid-template-columns: 300px 1fr;
    gap: 14px;
    align-items: start;
}

@media (max-width: 760px) {
    .cols { grid-template-columns: 1fr; }
}

.kv {
    display: flex;
    justify-content: space-between;
    gap: 10px;
    padding: 3px 0;
    border-bottom: 1px solid var(--panel-edge);
    font-size: 12.5px;
}

.kv:last-child { border-bottom: none; }

.k {
    color: var(--muted);
    text-transform: uppercase;
    font-size: 11px;
    letter-spacing: .6px;
    padding-top: 2px;
    white-space: nowrap;
}

.v { text-align: right; }

.mono { font-family: ui-monospace, monospace; font-size: 11.5px; }
.wrap { word-break: break-all; }
.small { font-size: 11.5px; }

.presets {
    display: flex;
    gap: 6px;
    flex-wrap: wrap;
    align-items: center;
    margin-bottom: 10px;
}

.legend { margin-bottom: 4px; }

.swatch {
    display: inline-block;
    width: 10px;
    height: 3px;
    border-radius: 2px;
    vertical-align: middle;
    margin: 0 4px 2px 6px;
}

.swatch-rtt { background: var(--up); }
.swatch-loss { background: var(--danger); }

.rrd-svg { width: 100%; height: auto; display: block; }

.gridline { stroke: var(--panel-edge); stroke-width: 1; }

.axis {
    fill: var(--muted);
    font-size: 9px;
    font-family: system-ui, sans-serif;
}

.line-rtt {
    fill: none;
    stroke: var(--up);
    stroke-width: 1.6;
}

.line-loss {
    fill: none;
    stroke: var(--danger);
    stroke-width: 1.4;
    stroke-dasharray: 3 2;
}

.rangeform {
    display: flex;
    align-items: center;
    gap: 10px;
    flex-wrap: wrap;
    margin-top: 8px;
    font-size: 12px;
}

.rangeform input {
    background: var(--panel);
    border: 1px solid var(--panel-edge);
    color: var(--text);
    border-radius: 6px;
    padding: 3px 6px;
    font: inherit;
}
</style>