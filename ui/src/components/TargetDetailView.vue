<!--
  Target detail page, ported from target.php: identity card,
  the four monitor counters, and the target's monitor table with
  lifetime-average latency columns (the classic page shows averages
  here, not current values, and that is kept). Editing stays on the
  in-app form; the delete button (admin tokens only, like the api) is
  the one mutation offered here.

  The detail lookup and the monitor listing are fetched independently:
  if the listing fails the identity card still shows, and the table
  says it failed instead of implying the target is monitored by nothing.
-->
<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import { delJson, getJson } from '../api'
import { getSession } from '../session'
import { resolveShowInactive, setShowInactive } from '../prefs'
import { fmtClock, lossClass } from '../format'
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

const targetId = computed(() => idFromLocation(props.id))

const target = ref(null)
const mons = ref(null)          // null = not loaded / failed; [] = genuinely none
const errors = reactive({ detail: null, mons: null })
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
    if (!targetId.value) {
        errors.detail = 'no target id found in the url'
        return
    }
    errors.detail = null
    errors.mons = null
    loading.value = true

    /* Both calls at once; each one succeeds or fails on its own. */
    const [targetR, monsR] = await Promise.allSettled([
        getJson('/cgi-bin/api/targets/' + encodeURIComponent(targetId.value)),
        getJson('/cgi-bin/api/monitors?target_id=' + encodeURIComponent(targetId.value))
    ])

    if (targetR.status === 'fulfilled') {
        target.value = targetR.value.target || null
        if (target.value) {
            lastOk.value = Date.now()
        } else {
            errors.detail = 'target payload was empty'
        }
    } else {
        target.value = null
        errors.detail = humanErr(targetR.reason, 'target not found')
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

/* Delete door: confirm first (the api cascades — the target's monitors
 * and their rrd files go with it), then call the same singular
 * endpoint the classic console's row delete proxies. Success leaves
 * for the targets listing; a failure keeps the record on screen and
 * says so, like every other failed request here. */
const deleting = ref(false)
const deleteError = ref(null)

async function deleteTarget() {
    if (deleting.value || !targetId.value || !canDelete.value) return
    if (!window.confirm('Delete this target? Its monitors and their rrd files are removed too.')) return
    deleting.value = true
    deleteError.value = null
    try {
        await delJson('/cgi-bin/api/target/' + encodeURIComponent(targetId.value))
        router.push({ name: 'targets' })
    } catch (err) {
        deleteError.value = humanErr(err, 'target delete failed')
    } finally {
        deleting.value = false
    }
}

/* Same counter rules as target.php: a row is active only when the
 * combined flag is on and this target is itself active; the own-flag
 * count tracks monitors that are individually disabled. */
const stats = computed(() => {
    const rows = mons.value || []
    const targetOn = target.value && Number(target.value.is_active) === 1
    let active = 0
    let ownInactive = 0
    for (const m of rows) {
        if (Number(m.is_active) === 1 && Number(m.agent_is_active) === 1 && targetOn) {
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
    return !!(target.value && Number(target.value.is_active) === 1
        && Number(m.agent_is_active) === 1 && Number(m.is_active) === 1)
}

const visibleMons = computed(() =>
    (mons.value || []).filter((m) => showInactive.value || rowActiveOn(m))
)
</script>

<template>
    <header class="bar">
        <div class="bar-title">
            <h1>target</h1>
            <span v-if="target" class="muted">{{ target.description || target.address || target.id }}</span>
        </div>
        <div class="bar-right">
            <template v-if="target">
                <span class="chip" :class="activeChipCls(target.is_active)">
                    {{ Number(target.is_active) === 1 ? 'active' : 'inactive' }}
                </span>
            </template>
            <span v-if="loading" class="muted">loading…</span>
            <span v-if="lastOk" class="muted">updated {{ fmtClock(lastOk) }}</span>
            <button class="btn" type="button" :disabled="loading" @click="fetchAll">refresh</button>
            <router-link v-if="targetId && canEdit" class="btn"
                         :to="{ name: 'target-edit', params: { id: targetId } }">edit</router-link>
            <button v-if="targetId && canDelete" class="btn" type="button" :disabled="deleting"
                    @click="deleteTarget">{{ deleting ? 'deleting…' : 'delete' }}</button>
        </div>
    </header>

    <div v-if="errors.detail" class="banner banner-error">
        target api: {{ errors.detail }} — nothing below is live data.
    </div>
    <div v-if="deleteError" class="banner banner-warn">
        delete failed: {{ deleteError }} — the target is still there.
    </div>

    <div v-if="target" class="cols">
        <div class="col-side">
            <section class="panel">
                <h2>details</h2>
                <div class="kv"><span class="k">id</span><span class="v mono wrap">{{ target.id }}</span></div>
                <div class="kv"><span class="k">address</span><span class="v mono">{{ target.address || '-' }}</span></div>
                <div class="kv"><span class="k">description</span><span class="v">{{ target.description || '-' }}</span></div>
                <div class="kv">
                    <span class="k">status</span>
                    <span class="v">
                        <span class="chip" :class="activeChipCls(target.is_active)">
                            {{ Number(target.is_active) === 1 ? 'active' : 'inactive' }}
                        </span>
                    </span>
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
                            <th>agent</th>
                            <th>protocol</th>
                            <th class="num">median</th>
                            <th class="num">min</th>
                            <th class="num">max</th>
                            <th class="num">std dev</th>
                            <th class="num">loss</th>
                            <th class="num">last update</th>
                        </tr>
                        </thead>
                        <tbody>
                        <tr v-if="!visibleMons.length">
                            <td colspan="9" class="muted">no monitors on this target</td>
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
                                <a :href="detailLink('agent', m.agent_id)"
                                   :title="'agent ' + m.agent_id">{{ m.agent_name || '-' }}</a>
                            </td>
                            <td :title="'dscp ' + (m.dscp || '-')">{{ protoLabel(m.protocol, m.port) }}</td>
                            <!-- Lifetime averages, exactly as the classic
                                 target page shows them (not currents). -->
                            <td class="num">{{ numOr(m.avg_median) }}</td>
                            <td class="num">{{ numOr(m.avg_min) }}</td>
                            <td class="num">{{ numOr(m.avg_max) }}</td>
                            <td class="num">{{ numOr(m.avg_stddev) }}</td>
                            <td class="num">
                                <span v-if="m.avg_loss !== null && m.avg_loss !== undefined"
                                      class="chip" :class="lossClass(m.avg_loss)">
                                    {{ numOr(m.avg_loss, '%') }}
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