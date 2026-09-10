<!--
  New/Edit form for monitors, matching monitors_edit.php. One component
  does both doors: no id means create (POST), an id means edit (load
  the record, then PUT). The classic page's one asymmetry is kept: the
  poll schedule is set on create and never touched again — a monitor's
  rrd file is sized by those numbers, so changing them mid-life would
  leave the graph lying.

  The active checkbox reads monitor_is_active when the API hands it
  over and falls back to is_active, because the joined detail payload
  can carry a wider meaning for is_active than this monitor's own flag.
  Writes are admin-only on the API; a 403 gets its own banner instead
  of a mystery HTTP code.
-->
<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import { getJson, postJson, putJson } from '../api'
import { getSession } from '../session'
import { humanErr, uuidOk } from './detailShared'

const props = defineProps({
    id: { type: String, default: '' }
})

const router = useRouter()

/* Two jobs, one component, same split the classic page makes. */
const isEdit = computed(() => !!props.id)

/* Same field values the classic form carries. */
const PROTOCOLS = ['ICMP', 'ICMPV6', 'TCP']
const DSCPS = [
    'BE', 'EF',
    'CS0', 'CS1', 'CS2', 'CS3', 'CS4', 'CS5', 'CS6', 'CS7',
    'AF11', 'AF12', 'AF13',
    'AF21', 'AF22', 'AF23',
    'AF31', 'AF32', 'AF33',
    'AF41', 'AF42', 'AF43'
]

const description = ref('')
const agentId = ref('')
const targetId = ref('')
const protocol = ref('ICMP')
const port = ref('')
const dscp = ref('BE')
const pollcount = ref(5)
const pollinterval = ref(60)
const isActive = ref(true)

const agents = ref([])
const targets = ref([])
const listError = ref('')
const busy = ref(false)
const attempted = ref(false)   // field notes stay hidden until first submit
const saveError = ref('')
const forbidden = ref(false)   // 403: the write is admin-only
const fillLoading = ref(false)
const fillError = ref('')
const redirecting = ref(false)
const loadedAgentName = ref('')    // agent name as the API last returned it
const loadedTargetName = ref('')   // target address likewise

/* Port only exists for TCP; for ICMP it rides out as 0 the way the
 * classic form posts it, so a protocol switch never leaves a stale
 * port behind. */
const isTcp = computed(() => protocol.value === 'TCP')

function portNumOk(s) {
    const n = Number(s)
    return Number.isInteger(n) && n >= 1 && n <= 65535
}

function pollNumOk(s) {
    const n = Number(s)
    return Number.isInteger(n) && n >= 1
}

/* The same rules the classic form enforces, in its wording. */
const errs = computed(() => ({
    description: description.value.trim() ? '' : 'Description is required',
    agent: agentId.value ? '' : 'Agent is required',
    target: targetId.value ? '' : 'Target is required',
    port: (!isTcp.value || portNumOk(port.value)) ? '' : 'Port must be an integer between 1 and 65535',
    pollcount: (isEdit.value || pollNumOk(pollcount.value)) ? '' : 'Poll count must be a positive integer',
    pollinterval: (isEdit.value || pollNumOk(pollinterval.value)) ? '' : 'Poll interval must be a positive integer'
}))

const hasErrs = computed(() => Object.values(errs.value).some(Boolean))

/*
 * The selects only carry active agents and targets — a monitor should
 * not be pointed at something switched off. If the record being edited
 * references one that is currently disabled, it is bolted back on with
 * a "(disabled)" label rather than silently swapped on save.
 */
function mergeDisabled(list, id, label) {
    if (id && !list.some((x) => x.id === id)) {
        list.push({ id, label: label + ' (disabled)', disabled: true })
    }
    return list
}

const agentOptions = computed(() =>
    mergeDisabled(agents.value.slice(), agentId.value, loadedAgentName.value))
const targetOptions = computed(() =>
    mergeDisabled(targets.value.slice(), targetId.value, loadedTargetName.value))

async function loadLists() {
    listError.value = ''
    const [a, t] = await Promise.allSettled([
        getJson('/cgi-bin/api/agents'),
        getJson('/cgi-bin/api/targets')
    ])
    const failed = []
    if (a.status === 'fulfilled') {
        agents.value = (a.value.agents || []).filter((x) => Number(x.is_active) === 1)
    } else {
        failed.push('agents')
    }
    if (t.status === 'fulfilled') {
        targets.value = (t.value.targets || []).filter((x) => Number(x.is_active) === 1)
    } else {
        failed.push('targets')
    }
    if (failed.length) listError.value = 'could not load ' + failed.join(' and ') + ' — the picks below may be stale'
}

/* Fill from the monitor detail endpoint. monitor_is_active wins when
 * present: it is this monitor's own flag, while is_active on a joined
 * payload can mean something wider. */
async function fill() {
    if (!uuidOk(props.id)) {
        fillError.value = 'no monitor id found in the url'
        return
    }
    fillLoading.value = true
    fillError.value = ''
    try {
        const json = await getJson('/cgi-bin/api/monitors/' + encodeURIComponent(props.id))
        const m = json.monitor || null
        if (!m) {
            fillError.value = 'monitor payload was empty'
            return
        }
        description.value = m.description || ''
        agentId.value = m.agent_id || ''
        targetId.value = m.target_id || ''
        protocol.value = PROTOCOLS.includes(m.protocol) ? m.protocol : 'ICMP'
        port.value = m.port && Number(m.port) ? String(m.port) : ''
        dscp.value = m.dscp || 'BE'
        const flag = (m.monitor_is_active !== undefined && m.monitor_is_active !== null)
            ? m.monitor_is_active
            : m.is_active
        isActive.value = Number(flag) === 1
        loadedAgentName.value = m.agent_name || ''
        loadedTargetName.value = m.target_address || ''
    } catch (err) {
        fillError.value = humanErr(err, 'monitor not found')
    } finally {
        fillLoading.value = false
    }
}

/*
 * api.js throws bare Error('HTTP 403') strings without a status field,
 * so the admin gate is read off the message too — one helper because
 * both save paths need it.
 */
function isForbidden(err) {
    return !!err && (err.status === 403 || /^HTTP 403\b/.test(err.message || ''))
}

/* No session, no form: a doomed submit helps nobody. */
async function requireSession() {
    const s = await getSession()
    if (s.authenticated) return true
    redirecting.value = true
    router.push({ name: 'login' })
    return false
}

onMounted(async () => {
    if (!await requireSession()) return
    await loadLists()
    if (isEdit.value) fill()
})

/* The router reuses this component when only the id changes, so an
 * open edit form must refetch or it would keep showing the previous
 * monitor. */
watch(() => props.id, () => {
    if (isEdit.value) fill()
})

async function save() {
    if (busy.value) return
    attempted.value = true
    if (hasErrs.value) return
    if (!await requireSession()) return

    busy.value = true
    saveError.value = ''
    forbidden.value = false

    /* Same body the classic page posts. Poll settings go out on create
     * only — after that they are part of the rrd layout, not the config. */
    const payload = {
        description: description.value.trim(),
        agent_id: agentId.value,
        target_id: targetId.value,
        protocol: protocol.value,
        port: isTcp.value ? Number(port.value) : 0,
        dscp: dscp.value,
        is_active: isActive.value
    }
    if (!isEdit.value) {
        payload.pollcount = Number(pollcount.value)
        payload.pollinterval = Number(pollinterval.value)
    }

    try {
        const r = isEdit.value
            ? await putJson('/cgi-bin/api/monitor/' + encodeURIComponent(props.id), payload)
            : await postJson('/cgi-bin/api/monitor', payload)
        const rid = (r && r.id) || (r && r.monitor && r.monitor.id) || (isEdit.value ? props.id : '')
        if (rid) {
            router.push({ name: 'monitor', params: { id: rid } })
        } else {
            router.push({ name: 'monitors' })
        }
    } catch (err) {
        if (isForbidden(err)) {
            forbidden.value = true
            saveError.value = 'admin rights required — only admins can save monitors'
        } else {
            saveError.value = (err && err.message) || 'saving failed'
        }
    } finally {
        busy.value = false
    }
}

const showForm = computed(() =>
    !redirecting.value && !fillLoading.value && !fillError.value)
</script>

<template>
    <header class="bar">
        <div class="bar-title">
            <h1>{{ isEdit ? 'edit monitor' : 'new monitor' }}</h1>
        </div>
        <div class="bar-right">
            <router-link class="btn" :to="{ name: 'monitors' }">back to monitors</router-link>
        </div>
    </header>

    <div v-if="forbidden" class="banner banner-error" role="alert">
        {{ saveError }} — sign in as an admin to save.
    </div>
    <div v-else-if="saveError" class="banner banner-error" role="alert">
        {{ saveError }} — nothing was saved.
    </div>
    <div v-else-if="listError" class="banner banner-warn">{{ listError }}</div>

    <p v-if="redirecting" class="muted block-note">
        sign-in required — taking you to the sign-in page…
    </p>

    <p v-else-if="fillLoading" class="muted block-note">loading monitor…</p>

    <section v-if="showForm" class="panel form-panel">
        <h2>monitor details</h2>
        <form novalidate @submit.prevent="save">
            <div class="field">
                <label class="field-label" for="monitor-desc">description *</label>
                <input id="monitor-desc" v-model="description" class="field-input" type="text"
                       :class="{ invalid: attempted && errs.description }">
                <p v-if="attempted && errs.description" class="err-note">{{ errs.description }}</p>
            </div>

            <div class="field">
                <label class="field-label" for="monitor-agent">agent *</label>
                <select id="monitor-agent" v-model="agentId" class="field-input"
                        :class="{ invalid: attempted && errs.agent }">
                    <option value="" disabled>pick an agent</option>
                    <option v-for="a in agentOptions" :key="a.id" :value="a.id">
                        {{ a.label || a.name }}
                    </option>
                </select>
                <p v-if="attempted && errs.agent" class="err-note">{{ errs.agent }}</p>
            </div>

            <div class="field">
                <label class="field-label" for="monitor-target">target *</label>
                <select id="monitor-target" v-model="targetId" class="field-input"
                        :class="{ invalid: attempted && errs.target }">
                    <option value="" disabled>pick a target</option>
                    <option v-for="t in targetOptions" :key="t.id" :value="t.id">
                        {{ t.label || t.address }}
                    </option>
                </select>
                <p v-if="attempted && errs.target" class="err-note">{{ errs.target }}</p>
            </div>

            <div class="field">
                <label class="field-label" for="monitor-proto">protocol *</label>
                <select id="monitor-proto" v-model="protocol" class="field-input">
                    <option v-for="p in PROTOCOLS" :key="p" :value="p">{{ p }}</option>
                </select>
            </div>

            <div v-if="isTcp" class="field">
                <label class="field-label" for="monitor-port">port *</label>
                <input id="monitor-port" v-model="port" class="field-input" type="number" min="1" max="65535"
                       :class="{ invalid: attempted && errs.port }">
                <p class="field-note muted">TCP monitors only — ICMP leaves the port at 0.</p>
                <p v-if="attempted && errs.port" class="err-note">{{ errs.port }}</p>
            </div>

            <div class="field">
                <label class="field-label" for="monitor-dscp">dscp *</label>
                <select id="monitor-dscp" v-model="dscp" class="field-input">
                    <option v-for="d in DSCPS" :key="d" :value="d">{{ d }}</option>
                </select>
            </div>

            <template v-if="!isEdit">
                <div class="field">
                    <label class="field-label" for="monitor-pollcount">poll count *</label>
                    <input id="monitor-pollcount" v-model="pollcount" class="field-input" type="number" min="1"
                           :class="{ invalid: attempted && errs.pollcount }">
                    <p class="field-note muted">probes per cycle — set once, the rrd file is sized by it.</p>
                    <p v-if="attempted && errs.pollcount" class="err-note">{{ errs.pollcount }}</p>
                </div>

                <div class="field">
                    <label class="field-label" for="monitor-pollinterval">poll interval (s) *</label>
                    <input id="monitor-pollinterval" v-model="pollinterval" class="field-input" type="number" min="1"
                           :class="{ invalid: attempted && errs.pollinterval }">
                    <p class="field-note muted">seconds between cycles — set once, the rrd file is sized by it.</p>
                    <p v-if="attempted && errs.pollinterval" class="err-note">{{ errs.pollinterval }}</p>
                </div>
            </template>
            <p v-else class="field-note muted">
                poll schedule is fixed after create ({{ pollcount }}x every {{ pollinterval }}s) —
                the rrd file is sized by it.
            </p>

            <div class="field">
                <label class="check">
                    <input id="monitor-active" v-model="isActive" type="checkbox">
                    active
                </label>
            </div>

            <div class="actions">
                <button class="btn submit" type="submit" :disabled="busy">
                    {{ busy ? 'saving…' : (isEdit ? 'save monitor' : 'create monitor') }}
                </button>
                <router-link class="btn" :to="{ name: 'monitors' }">cancel</router-link>
            </div>
        </form>
    </section>

</template>

<style scoped>
/* Form styling stays local, same as the other editors: the shared
 * stylesheet is dashboard-only. */
.form-panel {
    max-width: 560px;
}

.block-note {
    padding: 8px 0;
}

.field {
    margin: 0 0 14px;
}

.field-label {
    color: var(--muted);
    display: block;
    font-size: 11px;
    letter-spacing: .6px;
    margin: 0 0 4px;
    text-transform: uppercase;
}

.field-input {
    background: var(--bg);
    border: 1px solid var(--panel-edge);
    border-radius: 6px;
    box-sizing: border-box;
    color: var(--text);
    font: inherit;
    padding: 6px 9px;
    width: 100%;
}

.field-input:focus {
    border-color: var(--up);
    outline: none;
}

.field-input.invalid {
    border-color: var(--danger);
}

.field-note {
    font-size: 11.5px;
    margin: 4px 0 0;
}

.check {
    align-items: center;
    cursor: pointer;
    display: inline-flex;
    gap: 7px;
}

.actions {
    display: flex;
    gap: 10px;
    margin-top: 16px;
}

.submit:disabled {
    cursor: default;
    opacity: .6;
}
</style>