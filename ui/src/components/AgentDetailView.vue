<!--
  Agent detail page, ported read-only from agent.php: identity card,
  the four monitor counters, and the agent's monitor table with current
  median/loss per row. The edit button opens the in-app agent form —
  this page never mutates anything itself.

  The detail lookup and the monitor listing are fetched independently:
  if the listing fails the identity card still shows, and the table
  says it failed instead of implying the agent monitors nothing.
-->
<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { getJson } from '../api'
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
const errors = reactive({ detail: null, mons: null })
const loading = ref(false)
const lastOk = ref(null)
const session = ref(null)

/* The edit door opens the in-app form, but the offer itself waits for
 * a signed-in SPA session — the classic page's login wall, in probe
 * form. Nothing on this page mutates anything. */
const canEdit = computed(() => !!(session.value && session.value.authenticated))

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
    loading.value = true

    /* Both calls at once; each one succeeds or fails on its own. */
    const [agentR, monsR] = await Promise.allSettled([
        getJson('/cgi-bin/api/agents/' + encodeURIComponent(agentId.value)),
        getJson('/cgi-bin/api/monitors?agent_id=' + encodeURIComponent(agentId.value))
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

    loading.value = false
}

onMounted(async () => {
    session.value = await getSession()
    await fetchAll()
})

/* Same counter rules as agent.php: a row is active only when the
 * combined flag is on and this agent is itself active; the own-flag
 * count tracks monitors that are individually disabled. */
const stats = computed(() => {
    const rows = mons.value || []
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
    return {
        total: rows.length,
        active,
        ownInactive,
        effInactive: rows.length - active
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
        </div>
    </header>

    <div v-if="errors.detail" class="banner banner-error">
        agent api: {{ errors.detail }} — nothing below is live data.
    </div>

    <div v-if="agent" class="cols">
        <div class="col-side">
            <section class="panel">
                <h2>details</h2>
                <div class="kv"><span class="k">id</span><span class="v mono wrap">{{ agent.id }}</span></div>
                <div class="kv"><span class="k">address</span><span class="v mono">{{ agent.address || '-' }}</span></div>
                <div class="kv"><span class="k">description</span><span class="v">{{ agent.description || '-' }}</span></div>
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