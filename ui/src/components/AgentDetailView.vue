<!--
  Agent detail page, ported from agent.php: identity card,
  the four monitor counters, and the agent's monitor table with current
  median/loss per row. Below the monitors sit the agent's services,
  SPA-only (spec §8.4): their own counters and a table of the HTTP/S
  checks bound to this agent. The edit button opens the in-app agent
  form; the delete button (admin tokens only, like the api) is the one
  mutation offered here.

  The detail lookup, the monitor listing and the services listing are
  fetched independently: if a listing fails the identity card still
  shows, and the table and its counters say which list failed instead
  of implying the agent monitors nothing.
-->
<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import { delJson, getJson } from '../api'
import { getSession } from '../session'
import { resolveShowInactive, setShowInactive } from '../prefs'
import { agentClass, fmtClock, lossClass } from '../format'
import {
    activeChipCls,
    detailLink,
    humanErr,
    idFromLocation,
    inactiveReasons,
    numOr,
    protoLabel,
    stampOr
} from './detailShared'

const props = defineProps({
    id: { type: String, default: '' }
})

const agentId = computed(() => idFromLocation(props.id))

const agent = ref(null)
const mons = ref(null)          // null = not loaded / failed; [] = genuinely none
const svcs = ref(null)          // same convention as mons, for the services list
const errors = reactive({ detail: null, mons: null, svcs: null })
const loading = ref(false)
const lastOk = ref(null)
const session = ref(null)

const router = useRouter()

/* The edit door opens the in-app form, but the offer itself waits for
 * a signed-in SPA session — the classic page's login wall, in probe
 * form. Delete is the one mutation this page offers, and the api
 * answers it for admin tokens only, so its button asks for the admin
 * claim on top of the probe. */
const canEdit = computed(() => !!(session.value && session.value.authenticated))
const canDelete = computed(() =>
    !!(session.value && session.value.authenticated && session.value.isAdmin))

/* The classic page hides effectively-inactive rows behind a toggle;
 * the choice is the shared show-inactive flag (prefs.js), resolved
 * like lib/page.php's wanportal_get_show_inactive(): the URL query
 * wins, then the stored choice, else unchecked. Toggling persists it
 * and round-trips the URL query without a reload, so the choice
 * survives leaving this page the way the classic session flag did. */
const showInactive = ref(resolveShowInactive())
watch(showInactive, (value) => setShowInactive(value))

async function fetchAll() {
    if (!agentId.value) {
        errors.detail = 'no agent id found in the url'
        return
    }
    errors.detail = null
    errors.mons = null
    errors.svcs = null
    loading.value = true

    /* All three calls at once; each one succeeds or fails on its own.
     * The services list narrows by agent server-side — the api's
     * agent_id filter exists for exactly this page. */
    const [agentR, monsR, svcsR] = await Promise.allSettled([
        getJson('/cgi-bin/api/agents/' + encodeURIComponent(agentId.value)),
        getJson('/cgi-bin/api/monitors?agent_id=' + encodeURIComponent(agentId.value)),
        getJson('/cgi-bin/api/services?agent_id=' + encodeURIComponent(agentId.value))
    ])

    if (agentR.status === 'fulfilled') {
        agent.value = agentR.value.agent || null
        if (agent.value) {
            lastOk.value = Date.now()
        } else {
            errors.detail = 'agent payload was empty'
        }
    } else {
        agent.value = null
        errors.detail = humanErr(agentR.reason, 'agent not found')
    }

    if (monsR.status === 'fulfilled') {
        mons.value = monsR.value.monitors || []
    } else {
        mons.value = null
        errors.mons = (monsR.reason && monsR.reason.message) || 'unknown error'
    }

    if (svcsR.status === 'fulfilled') {
        svcs.value = svcsR.value.services || []
    } else {
        svcs.value = null
        errors.svcs = (svcsR.reason && svcsR.reason.message) || 'unknown error'
    }

    loading.value = false
}

onMounted(async () => {
    session.value = await getSession()
    await fetchAll()
})

/* Delete door: confirm first (the api cascades — the agent's monitors
 * and their rrd files go with it), then call the same singular
 * endpoint the classic console's row delete proxies. Success leaves
 * for the agents listing; a failure keeps the record on screen and
 * says so, like every other failed request here. */
const deleting = ref(false)
const deleteError = ref(null)

async function deleteAgent() {
    if (deleting.value || !agentId.value || !canDelete.value) return
    if (!window.confirm('Delete this agent? Its monitors and their rrd files are removed too.')) return
    deleting.value = true
    deleteError.value = null
    try {
        await delJson('/cgi-bin/api/agent/' + encodeURIComponent(agentId.value))
        router.push({ name: 'agents' })
    } catch (err) {
        deleteError.value = humanErr(err, 'agent delete failed')
    } finally {
        deleting.value = false
    }
}

/* Same counter rules as agent.php: a row is active only when the
 * combined flag is on and this agent is itself active; the own-flag
 * count tracks monitors that are individually disabled. The services
 * follow the same effective-active rule with their single own flag:
 * a service polls only when its own flag is on and this agent is
 * active, and the own-flag count tracks individually disabled
 * services. */
const stats = computed(() => {
    const rows = mons.value || []
    const srows = svcs.value || []
    const agentOn = agent.value && Number(agent.value.is_active) === 1
    let active = 0
    let ownInactive = 0
    for (const m of rows) {
        if (Number(m.is_active) === 1 && Number(m.target_is_active) === 1 && agentOn) {
            active++
        } else {
            if (Number(m.monitor_is_active) !== 1) ownInactive++
        }
    }
    let svcActive = 0
    let svcOwnInactive = 0
    for (const s of srows) {
        if (Number(s.is_active) === 1 && agentOn) {
            svcActive++
        } else {
            if (Number(s.is_active) !== 1) svcOwnInactive++
        }
    }
    return {
        total: rows.length,
        active,
        ownInactive,
        effInactive: rows.length - active,
        totalServices: srows.length,
        activeServices: svcActive,
        inactiveServices: svcOwnInactive
    }
})

/* Effective activity is the classic page's formula, kept verbatim. */
function rowActiveOn(m) {
    return !!(agent.value && Number(agent.value.is_active) === 1
        && Number(m.target_is_active) === 1 && Number(m.is_active) === 1)
}

const visibleMons = computed(() =>
    (mons.value || []).filter((m) => showInactive.value || rowActiveOn(m))
)

/* Service rows come from the services list api, whose is_active is the
 * service's own flag — this page's agent is their agent, so a row is
 * effectively active when its own flag is on and the agent is active.
 * The same rule drives the service counters, the toggle filter and the
 * row styling; nothing here re-checks the target. */
function svcActiveOn(s) {
    return !!(agent.value && Number(agent.value.is_active) === 1
        && Number(s.is_active) === 1)
}

/* Why a service row is not effectively active, in the monitor table's
 * wording — one convention for every table on this page. */
function svcReasons(s) {
    const why = []
    if (!s || Number(s.is_active) !== 1) why.push('Service disabled')
    if (!agent.value || Number(agent.value.is_active) !== 1) why.push('Agent disabled')
    return why
}

const visibleSvcs = computed(() =>
    (svcs.value || []).filter((s) => showInactive.value || svcActiveOn(s))
)

/* The rolled-up check state, from the agent's last report — the same
 * chip the services listing renders: UP green, DOWN red, and everything
 * else (DISABLED, UNKNOWN, a missing value) amber, so an unreadable
 * state never renders as healthy. */
function stateChipCls(s) {
    const st = String(s.last_state || 'UNKNOWN')
    if (st === 'UP') return 'chip chip-ok'
    if (st === 'DOWN') return 'chip chip-danger'
    return 'chip chip-warn'
}

/* scheme://host[:port]/path[?query] — the port only prints when the
 * service overrides the scheme default (0 means default), the same way
 * the agent builds the URL it probes. */
function uriText(s) {
    const port = Number(s.port)
    return String(s.scheme || '') + '://' + (s.target_address || '')
        + (port ? ':' + port : '')
        + (s.uri_path || '/') + (s.uri_query ? '?' + s.uri_query : '')
}

/* Service stamps render minute-resolution with the full value in a
 * tooltip, as the services listing does; a check that never ran says
 * so instead of dressing the gap up as a dash. */
function fmtStamp(s) {
    const m = /^(\d{4}-\d{2}-\d{2})[ T](\d{2}:\d{2})/.exec(s || '')
    return m ? m[1] + ' ' + m[2] : (s || '')
}

const heartbeatStale = computed(() =>
    agent.value ? agentClass(agent.value) === 'chip-stale' : false
)
</script>

<template>
    <header class="bar">
        <div class="bar-title">
            <h1>agent</h1>
            <span v-if="agent" class="muted">{{ agent.name || agent.id }}</span>
        </div>
        <div class="bar-right">
            <template v-if="agent">
                <span class="chip" :class="activeChipCls(agent.is_active)">
                    {{ Number(agent.is_active) === 1 ? 'active' : 'inactive' }}
                </span>
                <span v-if="heartbeatStale" class="chip chip-stale">heartbeat stale</span>
            </template>
            <span v-if="loading" class="muted">loading…</span>
            <span v-if="lastOk" class="muted">updated {{ fmtClock(lastOk) }}</span>
            <button class="btn" type="button" :disabled="loading" @click="fetchAll">refresh</button>
            <!-- script download + container install live one hop away;
                 the serving itself stays with the classic console -->
            <a v-if="agentId" class="btn" :href="'#/agents/' + encodeURIComponent(agentId) + '/netping'"
               title="agent script + docker image install">netping</a>
            <router-link v-if="agentId && canEdit" class="btn"
                         :to="{ name: 'agent-edit', params: { id: agentId } }">edit</router-link>
            <button v-if="agentId && canDelete" class="btn" type="button" :disabled="deleting"
                    @click="deleteAgent">{{ deleting ? 'deleting…' : 'delete' }}</button>
        </div>
    </header>

    <div v-if="errors.detail" class="banner banner-error">
        agent api: {{ errors.detail }} — nothing below is live data.
    </div>
    <div v-if="deleteError" class="banner banner-warn">
        delete failed: {{ deleteError }} — the agent is still there.
    </div>

    <div v-if="agent" class="cols">
        <div class="col-side">
            <section class="panel">
                <h2>details</h2>
                <div class="kv"><span class="k">id</span><span class="v mono wrap">{{ agent.id }}</span></div>
                <div class="kv"><span class="k">address</span><span class="v mono">{{ agent.address || '-' }}</span></div>
                <div class="kv"><span class="k">description</span><span class="v">{{ agent.description || '-' }}</span></div>
                <!-- capability is self-declared on the periodic fetch the
                     agent already makes (spec §6.2): a missing version is
                     an agent that never declared one — announced as
                     "not reported" (the same wording the netping header
                     uses) rather than a bare "-", so the absence states
                     itself instead of reading as broken data; missing
                     support is the old monitor-only agent -->
                <div class="kv"><span class="k">version</span><span class="v mono">{{ agent.agent_version || 'not reported' }}</span></div>
                <div class="kv">
                    <span class="k">service checks</span>
                    <span class="v">{{ Number(agent.supports_services) === 1 ? 'supported' : 'not supported' }}</span>
                </div>
                <div class="kv">
                    <span class="k">status</span>
                    <span class="v">
                        <span class="chip" :class="activeChipCls(agent.is_active)">
                            {{ Number(agent.is_active) === 1 ? 'active' : 'inactive' }}
                        </span>
                    </span>
                </div>
                <div class="kv">
                    <span class="k">last seen</span>
                    <span class="v mono">{{ stampOr(agent.last_seen) }}</span>
                </div>
            </section>

            <section class="panel">
                <h2>statistics</h2>
                <div v-if="errors.mons" class="err-note block">monitor list failed — counts not shown</div>
                <template v-else>
                    <div class="kv"><span class="k">active monitors</span><span class="v">{{ stats.active }}</span></div>
                    <div class="kv"><span class="k">inactive monitors</span><span class="v">{{ stats.ownInactive }}</span></div>
                    <div class="kv"><span class="k">effectively inactive</span><span class="v">{{ stats.effInactive }}</span></div>
                    <div class="kv"><span class="k">total monitors</span><span class="v">{{ stats.total }}</span></div>
                </template>
                <div v-if="errors.svcs" class="err-note block">service list failed — counts not shown</div>
                <template v-else>
                    <div class="kv"><span class="k">active services</span><span class="v">{{ stats.activeServices }}</span></div>
                    <div class="kv"><span class="k">inactive services</span><span class="v">{{ stats.inactiveServices }}</span></div>
                    <div class="kv"><span class="k">total services</span><span class="v">{{ stats.totalServices }}</span></div>
                </template>
            </section>
        </div>

        <div class="col-main">
            <section class="panel">
                <h2>monitors</h2>
                <div v-if="errors.mons" class="err-note block">
                    monitor list fetch failed — this table is not trustworthy ({{ errors.mons }})
                </div>
                <template v-else>
                    <label class="toggle muted small">
                        <input v-model="showInactive" type="checkbox"> show effectively-inactive rows
                    </label>
                    <table>
                        <thead>
                        <tr>
                            <th>monitor</th>
                            <th>target</th>
                            <th>protocol</th>
                            <th class="num">median</th>
                            <th class="num">loss</th>
                            <th class="num">last update</th>
                        </tr>
                        </thead>
                        <tbody>
                        <tr v-if="!visibleMons.length">
                            <td colspan="6" class="muted">no monitors on this agent</td>
                        </tr>
                        <tr v-for="m in visibleMons" :key="m.id" :class="{ inactive: !rowActiveOn(m) }">
                            <td>
                                <del v-if="!rowActiveOn(m)">
                                    <a :href="detailLink('monitor', m.id)"
                                       :title="'monitor ' + m.id">{{ m.description || m.id }}</a>
                                </del>
                                <a v-else :href="detailLink('monitor', m.id)"
                                   :title="'monitor ' + m.id">{{ m.description || m.id }}</a>
                                <span v-if="!rowActiveOn(m)" class="muted small">
                                    ({{ inactiveReasons(m).join(', ') || 'inactive' }})
                                </span>
                            </td>
                            <td>
                                <a :href="detailLink('target', m.target_id)"
                                   :title="'target ' + m.target_id">{{ m.target_address || '-' }}</a>
                            </td>
                            <td :title="'dscp ' + (m.dscp || '-')">{{ protoLabel(m.protocol, m.port) }}</td>
                            <td class="num">{{ numOr(m.current_median) }}</td>
                            <td class="num">
                                <span v-if="m.current_loss !== null && m.current_loss !== undefined"
                                      class="chip" :class="lossClass(m.current_loss)">
                                    {{ numOr(m.current_loss, '%') }}
                                </span>
                                <span v-else>-</span>
                            </td>
                            <td class="num mono" :title="'last down: ' + stampOr(m.last_down)">
                                {{ stampOr(m.last_update) }}
                            </td>
                        </tr>
                        </tbody>
                    </table>
                </template>
            </section>

            <section class="panel">
                <h2>services</h2>
                <div v-if="errors.svcs" class="err-note block">
                    service list fetch failed — this table is not trustworthy ({{ errors.svcs }})
                </div>
                <template v-else>
                    <table>
                        <thead>
                        <tr>
                            <th>service</th>
                            <th>target</th>
                            <th>uri</th>
                            <th>state</th>
                            <th class="num">last check</th>
                        </tr>
                        </thead>
                        <tbody>
                        <tr v-if="!visibleSvcs.length">
                            <td colspan="5" class="muted">no services</td>
                        </tr>
                        <tr v-for="s in visibleSvcs" :key="s.id" :class="{ inactive: !svcActiveOn(s) }">
                            <td>
                                <del v-if="!svcActiveOn(s)">
                                    <a :href="detailLink('services', s.id)"
                                       :title="'service ' + s.id">{{ s.description || s.id }}</a>
                                </del>
                                <a v-else :href="detailLink('services', s.id)"
                                   :title="'service ' + s.id">{{ s.description || s.id }}</a>
                                <span v-if="!svcActiveOn(s)" class="muted small">
                                    ({{ svcReasons(s).join(', ') || 'inactive' }})
                                </span>
                            </td>
                            <td>{{ s.target_address || '-' }}</td>
                            <!-- David's sanctioned exception to the
                                 no-new-tabs rule: the uri cell links the
                                 live service itself; every other link
                                 on this page stays in-tab. -->
                            <td>
                                <a :href="uriText(s)" target="_blank" rel="noopener noreferrer"
                                   class="mono" :title="uriText(s)">{{ uriText(s) }}</a>
                            </td>
                            <td>
                                <span class="chip" :class="stateChipCls(s)">{{ s.last_state || 'UNKNOWN' }}</span>
                            </td>
                            <td class="num mono" :title="s.last_check || ''">
                                {{ s.last_check ? fmtStamp(s.last_check) : 'Never' }}
                            </td>
                        </tr>
                        </tbody>
                    </table>
                </template>
            </section>
        </div>
    </div>

</template>

<style scoped>
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

.toggle { display: inline-block; margin-bottom: 6px; cursor: pointer; }

tr.inactive td { color: var(--muted); }
tr.inactive a { color: var(--muted); }
</style>