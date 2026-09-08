<!--
  Dashboard, ported from the look-only ui-preview page. Same data
  sources, same refresh rhythm, same honesty rules: when an API call
  fails the page says so instead of quietly showing zeros, and it keeps
  showing the last good numbers (marked stale) rather than blanking.
  Names link into the SPA detail pages wherever the row carries the
  record id; without an id the name stays plain text.
-->
<script setup>
import { computed, onBeforeUnmount, onMounted, reactive, ref } from 'vue'
import { getJson } from '../api'
import {
    REFRESH_SECONDS,
    downAge,
    downRowClass,
    agentClass,
    fmtClock,
    fmtDownSince,
    lossClass
} from '../format'

const dash = ref(null)          // last good rollup; kept across failed refreshes
const agents = ref([])
const down = ref([])
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

const cards = computed(() => {
    const d = dash.value
    if (!d) return []
    return [
        { label: 'total monitors', num: d.total, cls: '', pct: null },
        { label: 'up', num: d.up, cls: 'c-up', pct: d.percent_up },
        { label: 'degraded', num: d.degraded, cls: 'c-warn', pct: d.percent_degraded },
        { label: 'down', num: d.down, cls: 'c-danger', pct: d.percent_down }
    ]
})

const topSlow = computed(() => dash.value ? (dash.value.top_slow || []) : [])

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
        <div class="bar-title">
            <h1>dashboard</h1>
            <span class="muted">bundled vue · public cgi api · auto-refresh 30s</span>
        </div>
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

    <section v-if="dash" class="cards">
        <div v-for="c in cards" :key="c.label" class="card" :class="c.cls">
            <div class="card-label">{{ c.label }}</div>
            <div class="card-num">{{ c.num }}<span v-if="c.pct != null" class="card-sub"> {{ c.pct }}%</span></div>
        </div>
    </section>

    <section class="panel">
        <h2>top 5 slowest links <span class="muted">(by current median)</span></h2>
        <table>
            <thead>
            <tr>
                <th>#</th>
                <th>monitor</th>
                <th>agent</th>
                <th>target</th>
                <th class="num">median</th>
                <th class="num">loss</th>
            </tr>
            </thead>
            <tbody>
            <tr v-if="!dash">
                <td colspan="6" class="muted">no data</td>
            </tr>
            <template v-else>
                <tr v-if="!topSlow.length">
                    <td colspan="6" class="muted">nothing to report</td>
                </tr>
                <template v-else>
                    <tr v-for="(m, i) in topSlow" :key="m.id">
                        <td class="muted">{{ i + 1 }}</td>
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
                        <td class="num">{{ m.current_median }} ms</td>
                        <td class="num"><span class="chip" :class="lossClass(m.current_loss)">{{
                            (Number(m.current_loss) || 0).toFixed(1) }}%</span></td>
                    </tr>
                </template>
            </template>
            </tbody>
        </table>
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

    <footer class="muted">
        vue dashboard; classic console still at <a href="/">/</a>
    </footer>
</template>

<style scoped>
/* Chip links keep the plain-chip look: the global anchor rule paints
 * every link green, which would turn the status chips into buttons. */
.agents a.chip {
    color: inherit;
    text-decoration: none;
}
</style>