<!--
  Service detail page: the full §3.1 config, the live state with its
  reason token, and the rrd graph. The graph reuses the monitor
  charting wholesale — the service rrd carries loss/rtt beside its
  extra status data source (§3.4), and buildChartView reads loss/rtt,
  so one graphing model covers both domains against the same /rrd
  reader.

  Honesty rules carried over from the monitor page: a dead api says so
  instead of quietly showing zeros, the graph failing never blanks the
  config panels, and the freshness chip stays advisory. The page links
  back to the target row the check runs against (§8.1) — that target
  page is the authoritative host view, not this one.

  Services stamp last_check where monitors stamp last_update, so the
  freshness helper is this page's own, with the service default 300s
  standing in when no interval is set.
-->
<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import { useRouter } from 'vue-router'
import { delJson, getJson, postJson } from '../api'
import { getSession } from '../session'
import { fmtClock, parseApiUtc } from '../format'
import { buildChartView } from '../rrdChart'
import { humanErr, idFromLocation, isoMinutes, numOr, rrdDataUrl, stampOr } from './detailShared'

const props = defineProps({
    id: { type: String, default: '' }
})

const serviceId = computed(() => idFromLocation(props.id))

const service = ref(null)
const errors = reactive({ detail: null, chart: null })
const loadingDetail = ref(false)
const loadingChart = ref(false)
const lastOk = ref(null)
const session = ref(null)

const router = useRouter()

/* Reset is admin-only like the api's /reset route (counters and the
 * rolled-up state go, the config stays), and delete takes the rrd
 * history with the row — both confirm first, and a failure keeps the
 * record on screen and says so. */
const resetBusy = ref(false)
const resetError = ref('')
const canReset = computed(() => !!(session.value && session.value.authenticated && session.value.isAdmin))
const canEdit = computed(() => !!(session.value && session.value.authenticated))
const deleting = ref(false)
const deleteError = ref(null)
const canDelete = computed(() => !!(session.value && session.value.authenticated && session.value.isAdmin))

async function deleteService() {
    if (deleting.value || !serviceId.value || !canDelete.value) return
    if (!window.confirm('Delete this service? Its rrd history is removed too.')) return
    deleting.value = true
    deleteError.value = null
    try {
        await delJson('/cgi-bin/api/service/' + encodeURIComponent(serviceId.value))
        router.push({ name: 'services' })
    } catch (err) {
        deleteError.value = humanErr(err, 'service delete failed')
    } finally {
        deleting.value = false
    }
}

async function resetService() {
    if (resetBusy.value || !serviceId.value) return
    if (!canReset.value) {
        resetError.value = 'sign in as admin in this UI first (classic login is a different session)'
        return
    }
    if (!window.confirm('Reset this service? Counters and the state history are cleared.')) return
    resetBusy.value = true
    resetError.value = ''
    try {
        await postJson('/cgi-bin/api/service/' + encodeURIComponent(serviceId.value) + '/reset', {})
        await fetchDetail()
    } catch (err) {
        resetError.value = humanErr(err, 'reset failed')
    } finally {
        resetBusy.value = false
    }
}

/* Chart window: the same 3-hour default and one-click presets the
 * monitor page runs, against the same /rrd endpoint — service rrd
 * files hang off the same reader keyed by id (§8.2). */
const startMs = ref(Date.now() - 3 * 3600 * 1000)
const endMs = ref(Date.now())
const startText = ref(isoMinutes(startMs.value))
const endText = ref(isoMinutes(endMs.value))
const chartRows = ref(null)

async function fetchDetail() {
    errors.detail = null
    loadingDetail.value = true
    try {
        const r = await getJson('/cgi-bin/api/service/' + encodeURIComponent(serviceId.value))
        service.value = r.service || null
        if (service.value) lastOk.value = Date.now()
        else errors.detail = 'service payload was empty'
    } catch (err) {
        service.value = null
        errors.detail = humanErr(err, 'service not found')
    } finally {
        loadingDetail.value = false
    }
}

async function fetchChart() {
    errors.chart = null
    loadingChart.value = true
    try {
        const r = await getJson(rrdDataUrl(serviceId.value, startMs.value, endMs.value))
        chartRows.value = r.data || []
        if (!chartRows.value.length) errors.chart = 'rrd returned no samples for this range'
    } catch (err) {
        chartRows.value = null
        errors.chart = humanErr(err, 'no rrd file yet for this service')
    } finally {
        loadingChart.value = false
    }
}

function fetchAll() {
    if (!serviceId.value) {
        errors.detail = 'no service id found in the url'
        return
    }
    fetchDetail()
    fetchChart()
}

onMounted(async () => {
    session.value = await getSession()
    await fetchAll()
})

function setRangeHours(hours) {
    endMs.value = Date.now()
    startMs.value = endMs.value - hours * 3600 * 1000
    startText.value = isoMinutes(startMs.value)
    endText.value = isoMinutes(endMs.value)
    fetchChart()
}

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

/* ---- SVG chart geometry: identical numbers to the monitor page so
 * the two graphs never drift visually. ---- */
const CW = 760, CH = 250, PADL = 46, PADR = 46, PADT = 12, PADB = 24

const chartView = computed(() => buildChartView({
    rows: chartRows.value,
    startMs: startMs.value,
    endMs: endMs.value,
    CW, CH,
    pads: { left: PADL, right: PADR, top: PADT, bottom: PADB }
}))

/* ---- read-only chrome ---- */

/* UP green, DOWN red, everything else amber — an unreadable or missing
 * state must never render as healthy, same rule the listing runs. */
function stateChipCls() {
    const st = String((service.value && service.value.last_state) || 'UNKNOWN')
    if (st === 'UP') return 'chip chip-ok'
    if (st === 'DOWN') return 'chip chip-danger'
    return 'chip chip-warn'
}

/* The reason token prints verbatim: it is a closed set (spec §7) and
 * the detail page is where an operator decodes it, so a lossy
 * paraphrase would be the wrong kindness here. */
function reasonText() {
    return (service.value && service.value.last_reason) || '-'
}

/* scheme://host[:port]/path[?query], port 0 meaning scheme default —
 * the URL as the agent builds it. */
const uriText = computed(() => {
    const s = service.value
    if (!s) return '-'
    const port = Number(s.port)
    return String(s.scheme || '') + '://' + (s.target_address || '')
        + (port ? ':' + port : '')
        + (s.uri_path || '/') + (s.uri_query ? '?' + s.uri_query : '')
})

function strOr(v) {
    return (v === null || v === undefined || v === '') ? '-' : String(v)
}

function followText() {
    return Number(service.value.follow_redirects) === 1 ? 'yes' : 'no'
}

/* headers as displayed rows; the column decodes to {name: value} and
 * an array of pairs is tolerated like the editor does. */
const headerPairs = computed(() => {
    const h = service.value && service.value.http_headers
    let pairs = []
    if (h && typeof h === 'object' && !Array.isArray(h)) {
        pairs = Object.entries(h).map(([name, value]) => ({ name, value: String(value ?? '') }))
    } else if (Array.isArray(h)) {
        pairs = h.map((x) => Array.isArray(x)
            ? { name: String(x[0] ?? ''), value: String(x[1] ?? '') }
            : { name: String((x && x.name) ?? ''), value: String((x && x.value) ?? '') })
    }
    return pairs.filter((p) => p.name !== '')
})

/* verify_tls never reads as on by accident: an explicit 0 is off,
 * anything the api omits keeps the column's NOT NULL default 1. */
const tlsOn = computed(() => Number(service.value && service.value.verify_tls) !== 0)

/* The same 3x-interval advisory the monitor page runs, keyed off
 * last_check with the 300s service default standing in. Stamps come
 * from a db that runs UTC and read as UTC through parseApiUtc (like
 * the format.js duration helpers), so the freshness math is right no
 * matter which timezone the viewer sits in — which is why the verbatim
 * stamp sits right next to the chip. */
function isFresh(now = Date.now()) {
    const s = service.value
    const t = parseApiUtc((s && s.last_check) || '')
    if (Number.isNaN(t)) return false
    const interval = Number(s && s.pollinterval) > 0 ? Number(s.pollinterval) : 300
    return now - t <= 3 * interval * 1000
}
</script>

<template>
    <header class="bar">
        <div class="bar-title">
            <h1>service</h1>
            <span v-if="service" class="muted">{{ service.description || service.id }}</span>
        </div>
        <div class="bar-right">
            <template v-if="service">
                <span class="chip" :class="stateChipCls()" :title="service.last_reason || ''">
                    {{ service.last_state || 'UNKNOWN' }}
                </span>
                <span v-if="!tlsOn" class="chip chip-warn"
                      title="the certificate is not verified — this check cannot detect a wrong, expired or forged certificate">
                    tls unverified
                </span>
                <span class="chip" :class="isFresh() ? 'chip-ok' : 'chip-stale'">
                    {{ isFresh() ? 'fresh data' : 'stale data' }}
                </span>
            </template>
            <span v-if="loadingDetail" class="muted">loading…</span>
            <span v-if="lastOk" class="muted">updated {{ fmtClock(lastOk) }}</span>
            <button class="btn" type="button" :disabled="loadingDetail" @click="fetchAll">refresh</button>
            <router-link v-if="serviceId && canEdit" class="btn"
                         :to="{ name: 'service-edit', params: { id: serviceId } }">edit</router-link>
            <button v-if="serviceId && canDelete" class="btn" type="button" :disabled="deleting"
                    @click="deleteService">{{ deleting ? 'deleting…' : 'delete' }}</button>
            <button v-if="serviceId && canReset" class="btn" type="button" :disabled="resetBusy"
                    @click="resetService">{{ resetBusy ? 'resetting…' : 'reset' }}</button>
        </div>
    </header>

    <div v-if="errors.detail" class="banner banner-error">
        service api: {{ errors.detail }} — nothing below is live data.
    </div>
    <div v-if="resetError" class="banner banner-warn">
        reset failed: {{ resetError }} — counters were not cleared.
    </div>
    <div v-if="deleteError" class="banner banner-warn">
        delete failed: {{ deleteError }} — the service is still there.
    </div>

    <!-- The service's own address, built exactly like the agent's probe:
         the line this page exists to show, so it leads the content as a
         clickable link (David's sanctioned new-tab door) instead of
         hiding in the details panel. -->
    <div v-if="service" class="service-uri">
        <span class="k">url</span>
        <a :href="uriText" target="_blank" rel="noopener noreferrer" class="mono wrap">{{ uriText }}</a>
    </div>

    <div v-if="service" class="cols">
        <div class="col-side">
            <section class="panel">
                <h2>details</h2>
                <div class="kv"><span class="k">id</span><span class="v mono wrap">{{ service.id }}</span></div>
                <div class="kv">
                    <span class="k">agent</span>
                    <span class="v">
                        <router-link v-if="service.agent_id" :to="{ name: 'agent', params: { id: service.agent_id } }">
                            {{ service.agent_name || '-' }}
                        </router-link>
                        <template v-else>-</template>
                    </span>
                </div>
                <div class="kv">
                    <span class="k">target</span>
                    <span class="v">
                        <router-link v-if="service.target_id" :to="{ name: 'target', params: { id: service.target_id } }">
                            {{ service.target_address || '-' }}
                        </router-link>
                        <template v-else>-</template>
                    </span>
                </div>
                <div class="kv"><span class="k">method</span><span class="v">{{ service.http_method || '-' }}</span></div>
                <div class="kv">
                    <span class="k">polling</span>
                    <span class="v">{{ numOr(service.pollcount) }}x every {{ numOr(service.pollinterval) }}s</span>
                </div>
            </section>

            <section class="panel">
                <h2>request</h2>
                <div class="kv">
                    <span class="k">headers</span>
                    <span class="v mono wrap" v-if="headerPairs.length">
                        <span class="hdr-line" v-for="h in headerPairs" :key="'hdr' + h.name">{{ h.name }}: {{ h.value || '-' }}</span>
                    </span>
                    <span class="v muted" v-else>-</span>
                </div>
                <div class="kv">
                    <span class="k">body</span>
                    <span class="v">
                        {{ service.body_encoding ? service.body_encoding + ': ' : '' }}<span class="mono wrap">{{ strOr(service.body) }}</span>
                    </span>
                </div>
                <div class="kv">
                    <span class="k">send string</span>
                    <span class="v mono wrap">{{ strOr(service.send_string) }}</span>
                </div>
                <div class="kv"><span class="k">redirects</span><span class="v">{{ followText() }}</span></div>
                <div class="kv">
                    <span class="k">verify tls</span>
                    <span class="v">
                        {{ tlsOn ? 'on' : 'off' }}
                        <span v-if="!tlsOn" class="muted small">— unverified: wrong or expired certificates go unnoticed</span>
                    </span>
                </div>
                <div class="kv"><span class="k">timeout</span><span class="v">{{ numOr(service.timeout) }}s</span></div>
            </section>

            <section class="panel">
                <h2>assertions</h2>
                <div class="kv">
                    <span class="k">expected status</span>
                    <span class="v mono">{{ strOr(service.expected_status) }}</span>
                </div>
                <div class="kv">
                    <span class="k">receive</span>
                    <span class="v mono wrap">
                        {{ strOr(service.receive_string) }}
                        <span v-if="Number(service.receive_regex) === 1" class="muted small">(regex)</span>
                    </span>
                </div>
                <div class="kv">
                    <span class="k">disable</span>
                    <span class="v mono wrap">
                        {{ strOr(service.disable_string) }}
                        <span v-if="Number(service.disable_regex) === 1" class="muted small">(regex)</span>
                    </span>
                </div>
                <p class="muted small">
                    a disable match forces DOWN even when receive also matched.
                </p>
            </section>

            <section class="panel">
                <h2>auth</h2>
                <div class="kv"><span class="k">type</span><span class="v">{{ service.auth_type || 'none' }}</span></div>
                <div class="kv" v-if="(service.auth_type || 'none') === 'header'">
                    <span class="k">header name</span><span class="v mono">{{ strOr(service.auth_header_name) }}</span>
                </div>
                <div class="kv" v-if="(service.auth_type || 'none') !== 'none' && service.auth_credential_id">
                    <span class="k">credential</span>
                    <span class="v">
                        <router-link :to="{ name: 'credential', params: { id: service.auth_credential_id } }">
                            {{ service.credential_name || 'vault entry' }}
                        </router-link>
                    </span>
                </div>
                <p class="muted small" v-if="(service.auth_type || 'none') !== 'none'">
                    the secret never rides this page — the service stores the vault reference only.
                </p>
            </section>
        </div>

        <div class="col-main">
            <section class="panel">
                <h2>state</h2>
                <div class="kv">
                    <span class="k">last state</span>
                    <span class="v">
                        <span class="chip" :class="stateChipCls()">{{ service.last_state || 'UNKNOWN' }}</span>
                    </span>
                </div>
                <div class="kv">
                    <span class="k">last status code</span><span class="v mono">{{ numOr(service.last_status_code) }}</span>
                </div>
                <div class="kv">
                    <span class="k">last reason</span><span class="v mono">{{ reasonText() }}</span>
                </div>
                <div class="kv">
                    <span class="k">last message</span><span class="v wrap">{{ strOr(service.last_message) }}</span>
                </div>
                <div class="kv">
                    <span class="k">last check</span><span class="v mono">{{ stampOr(service.last_check) }}</span>
                </div>
                <div class="kv">
                    <span class="k">last change</span><span class="v mono">{{ stampOr(service.last_change) }}</span>
                </div>
                <div class="kv">
                    <span class="k">total down events</span><span class="v">{{ numOr(service.total_down) }}</span>
                </div>
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
                         aria-label="response time and up/down state over the selected range">
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
                        left scale: rtt ms (max {{ chartView.rttMax }}) · right scale: loss % (0 up / 100 down or disabled) · times in UTC
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

</template>

<style scoped>
/* Two-column layout like the monitor detail: config cards on the
 * left, state and graph on the right; collapses on narrow screens. */
.cols {
    display: grid;
    grid-template-columns: 300px 1fr;
    gap: 14px;
    align-items: start;
}

@media (max-width: 760px) {
    .cols { grid-template-columns: 1fr; }
}

/* The address strip rides the panel chrome (same tokens, slimmer
 * padding) so it reads as part of the page rather than a stray line;
 * the anchor inherits the shared link color to say clickable. */
.service-uri {
    display: flex;
    align-items: baseline;
    gap: 10px;
    background: var(--panel);
    border: 1px solid var(--panel-edge);
    border-radius: 8px;
    padding: 7px 12px;
    margin-bottom: 14px;
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

/* Header lines read 'name: value' like the -H flags that produced
 * them, each on its own line, right-aligned in the kv grid. */
.hdr-line {
    display: block;
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