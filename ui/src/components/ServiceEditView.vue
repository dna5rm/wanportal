<!--
  New/Edit form for HTTP/S service checks (spec §3.1). One component
  for both doors, the monitor editor's split: no id prop means create
  (POST /cgi-bin/api/service), an id means edit (load the record, then
  PUT /cgi-bin/api/service/:id).

  Four asymmetries are deliberate:

  - pollcount/pollinterval ride out on create only, like monitors: the
    service rrd file is sized by that schedule, so it is clock layout,
    not config, and changing it mid-life would leave the graph lying.
  - auth is a credential PICKER, never a secret field (§5.4): the
    service stores auth_credential_id, a reference into the existing
    credentials vault. The secret itself is resolved for the agent at
    services-GET time and never passes through this form — a second
    secret store is exactly what the spec forbids.
  - verify_tls defaults ON (§3.1). Turning it off is allowed but
    recorded: the detail page marks the service and the fact is logged,
    so an unverified check is never silent. The OFF case is a normal
    checkbox untick, not a confirmation dance.
  - the send string and the body are the same slot (§4): in the F5
    model the send string IS the request body, so the form refuses the
    combination before the api has to.
-->
<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import { getJson, postJson, putJson } from '../api'
import { getSession } from '../session'
import { humanErr, uuidOk } from './detailShared'
import { leaveForm } from './goBack'

const props = defineProps({
    id: { type: String, default: '' }
})

const router = useRouter()

/* Two jobs, one component, same split the monitor editor makes. */
const isEdit = computed(() => !!props.id)

/* The closed sets §5.1 validates against — keep the selects and the
 * validator reading the same lists so a new token can only land in
 * both places at once or neither. */
const SCHEMES = ['http', 'https']
const METHODS = ['GET', 'POST', 'PUT', 'PATCH', 'HEAD', 'OPTIONS', 'DELETE']
const ENCODINGS = ['none', 'text', 'json', 'base64', 'form']
const AUTH_TYPES = ['none', 'basic', 'bearer', 'header']

const description = ref('')
const agentId = ref('')
const targetId = ref('')
const scheme = ref('http')
const port = ref('')
const uriPath = ref('/')
const uriQuery = ref('')
const httpMethod = ref('GET')
const headerRows = ref([{ name: '', value: '' }])
const bodyEncoding = ref('none')
const body = ref('')
const sendString = ref('')
const receiveString = ref('')
const receiveRegex = ref(false)
const disableString = ref('')
const disableRegex = ref(false)
const expectedStatus = ref('')
const followRedirects = ref(false)
const verifyTls = ref(true)
const timeout = ref('10')
const authType = ref('none')
const authHeaderName = ref('')
const authCredentialId = ref('')
const pollcount = ref(1)
const pollinterval = ref(300)

const agents = ref([])
const targets = ref([])
const credentialList = ref([])
const listError = ref('')
const busy = ref(false)
const attempted = ref(false)   // field notes stay hidden until first submit
const saveError = ref('')
const forbidden = ref(false)   // 403: the write is admin-only
const fillLoading = ref(false)
const fillError = ref('')
const redirecting = ref(false)
const loadedAgentName = ref('')       // agent name as the api last returned it
const loadedTargetName = ref('')      // target address likewise
const loadedCredentialName = ref('')  // vault entry name likewise

/* ---- validators, in the api's §5.1 wording ---- */

function portNumOk(s) {
    if (String(s).trim() === '') return true   // blank = scheme default
    const n = Number(s)
    return Number.isInteger(n) && n >= 1 && n <= 65535
}

function timeoutOk(s) {
    const n = Number(s)
    return Number.isInteger(n) && n >= 1 && n <= 120
}

/* Empty or starts with '/', and never a full URL — the host comes from
 * the target row (I8), so a scheme/host pasted here is a split brain. */
function pathOk(s) {
    const v = String(s).trim()
    if (v === '') return true
    return v.startsWith('/') && !v.includes('://')
}

/* Each comma token is NNN or NNN-NNN, 100..599. Blank means the api's
 * default (any 2xx/3xx), never an impossible empty expectation. */
function statusOk(s) {
    const v = String(s).trim()
    if (v === '') return true
    return v.split(',').every((tok) => {
        const m = /^(\d{3})(-(\d{3}))?$/.exec(tok.trim())
        if (!m) return false
        const lo = Number(m[1])
        const hi = m[3] !== undefined ? Number(m[3]) : lo
        return lo >= 100 && lo <= 599 && hi >= 100 && hi <= 599 && lo <= hi
    })
}

function pollNumOk(s) {
    const n = Number(s)
    return Number.isInteger(n) && n >= 1
}

function bodyPresent() {
    return body.value.trim() !== ''
}

/* The same rules the api enforces, shown where the typist can read
 * them; the regex compile is NOT checked here on purpose — the api
 * matches with Perl qr and the agent with the same engine, so a JS
 * RegExp verdict would only disagree with the real one. */
const errs = computed(() => ({
    agent: agentId.value ? '' : 'Agent is required',
    target: targetId.value ? '' : 'Target is required',
    port: portNumOk(port.value) ? '' : 'Port must be blank (scheme default) or an integer between 1 and 65535',
    uripath: pathOk(uriPath.value) ? '' : 'Path must be empty or start with /',
    timeout: timeoutOk(timeout.value) ? '' : 'Timeout must be an integer between 1 and 120',
    status: statusOk(expectedStatus.value) ? '' : 'Expected status must be codes or ranges like 200 or 200-299,301',
    bodyenc: (!bodyPresent() || bodyEncoding.value !== 'none') ? '' : 'Body needs an encoding — text, json, base64, or form',
    both: (sendString.value.trim() === '' || body.value.trim() === '') ? '' : 'Send string and body are the same slot — the send string IS the request body; set one',
    authhdr: (authType.value !== 'header' || authHeaderName.value.trim()) ? '' : 'Header name is required for header auth',
    authcred: (authType.value === 'none' || authCredentialId.value) ? '' : 'Pick a credential for this auth type',
    pollcount: (isEdit.value || pollNumOk(pollcount.value)) ? '' : 'Poll count must be a positive integer',
    pollinterval: (isEdit.value || pollNumOk(pollinterval.value)) ? '' : 'Poll interval must be a positive integer'
}))

const hasErrs = computed(() => Object.values(errs.value).some(Boolean))

/*
 * The selects only carry active records — a check should not be pointed
 * at something switched off. If the record being edited references one
 * that is currently disabled, it is bolted back on with a "(disabled)"
 * label rather than silently swapped on save.
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
const credentialOptions = computed(() =>
    mergeDisabled(credentialList.value.slice(), authCredentialId.value, loadedCredentialName.value))

/*
 * Three lists, loaded together: agents and targets for the pickers,
 * credentials for the auth section. The vault list response carries
 * id, name and site and keeps its secrets to itself (§5.4), so the
 * picker exposes exactly what the service row stores — which credential
 * to use — and nothing that looks like a secret input. Inactive vault
 * entries drop out of the picker: the api refuses them at delivery
 * anyway, and pointing a check at a secret it cannot receive just
 * schedules an auth_error.
 */
async function loadLists() {
    listError.value = ''
    const [a, t, c] = await Promise.allSettled([
        getJson('/cgi-bin/api/agents'),
        getJson('/cgi-bin/api/targets'),
        getJson('/cgi-bin/api/credentials')
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
    if (c.status === 'fulfilled') {
        credentialList.value = (c.value.credentials || [])
            .filter((x) => Number(x.is_active) === 1)
            .map((x) => ({ id: x.id, label: x.name + (x.site ? ' (' + x.site + ')' : '') }))
    } else {
        failed.push('credentials')
    }
    if (failed.length) listError.value = 'could not load ' + failed.join(' and ') + ' — the picks below may be stale'
}

/* The stored http_headers come back decoded as {name: value}; an array
 * of pairs is tolerated so an older payload shape never blanks the
 * editor. The editor itself works on rows for the v-for keys. */
function headersToRows(h) {
    let rows = []
    if (h && typeof h === 'object' && !Array.isArray(h)) {
        rows = Object.entries(h).map(([name, value]) => ({ name: String(name), value: String(value ?? '') }))
    } else if (Array.isArray(h)) {
        rows = h.map((x) => Array.isArray(x)
            ? { name: String(x[0] ?? ''), value: String(x[1] ?? '') }
            : { name: String((x && x.name) ?? ''), value: String((x && x.value) ?? '') })
    }
    rows = rows.filter((r) => r.name !== '')
    return rows.length ? rows : [{ name: '', value: '' }]
}

/* Blank-name rows drop out; nothing left posts as null, which is what
 * tells the api to clear the column on an edit — deleting the last
 * header row must actually delete it, not keep a stale one. */
function headersOut() {
    const out = {}
    let any = false
    for (const r of headerRows.value) {
        const name = r.name.trim()
        if (!name) continue
        out[name] = r.value
        any = true
    }
    return any ? out : null
}

/* Fill from the service detail endpoint. */
async function fill() {
    if (!uuidOk(props.id)) {
        fillError.value = 'no service id found in the url'
        return
    }
    fillLoading.value = true
    fillError.value = ''
    try {
        const json = await getJson('/cgi-bin/api/service/' + encodeURIComponent(props.id))
        const m = json.service || null
        if (!m) {
            fillError.value = 'service payload was empty'
            return
        }
        description.value = m.description || ''
        agentId.value = m.agent_id || ''
        targetId.value = m.target_id || ''
        scheme.value = SCHEMES.includes(m.scheme) ? m.scheme : 'http'
        port.value = m.port && Number(m.port) ? String(m.port) : ''
        uriPath.value = (m.uri_path === null || m.uri_path === undefined) ? '/' : m.uri_path
        uriQuery.value = m.uri_query || ''
        httpMethod.value = METHODS.includes(m.http_method) ? m.http_method : 'GET'
        headerRows.value = headersToRows(m.http_headers)
        bodyEncoding.value = ENCODINGS.includes(m.body_encoding) ? m.body_encoding : 'none'
        body.value = m.body || ''
        sendString.value = m.send_string || ''
        receiveString.value = m.receive_string || ''
        receiveRegex.value = Number(m.receive_regex) === 1
        disableString.value = m.disable_string || ''
        disableRegex.value = Number(m.disable_regex) === 1
        expectedStatus.value = m.expected_status || ''
        followRedirects.value = Number(m.follow_redirects) === 1
        /* Anything but an explicit 0 reads as on: the column is NOT
         * NULL default 1, and an unverified check must never appear by
         * accident of a missing field. */
        verifyTls.value = Number(m.verify_tls) !== 0
        timeout.value = String(m.timeout ?? 10)
        authType.value = AUTH_TYPES.includes(m.auth_type) ? m.auth_type : 'none'
        authHeaderName.value = m.auth_header_name || ''
        authCredentialId.value = m.auth_credential_id || ''
        loadedAgentName.value = m.agent_name || ''
        loadedTargetName.value = m.target_address || ''
        loadedCredentialName.value = m.credential_name || ''
    } catch (err) {
        fillError.value = humanErr(err, 'service not found')
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
 * service. */
watch(() => props.id, () => {
    if (isEdit.value) fill()
})

function addHeader() {
    headerRows.value.push({ name: '', value: '' })
}

function removeHeader(i) {
    headerRows.value.splice(i, 1)
}

/*
 * Optional strings post as null, not '', because the api's NULL columns
 * mean "not configured" while '' would read as an empty-but-set match
 * string. The uri fields are the exception: '' is a legal path there
 * (§5.1) and the query starts empty by design.
 */
async function save() {
    if (busy.value) return
    attempted.value = true
    if (hasErrs.value) return
    if (!await requireSession()) return

    busy.value = true
    saveError.value = ''
    forbidden.value = false

    const send = sendString.value.trim()
    const recv = receiveString.value.trim()
    const dis = disableString.value.trim()
    const status = expectedStatus.value.trim()
    const credential = authType.value === 'none' ? null : (authCredentialId.value || null)

    const payload = {
        description: description.value.trim(),
        agent_id: agentId.value,
        target_id: targetId.value,
        scheme: scheme.value,
        port: String(port.value).trim() === '' ? 0 : Number(port.value),
        uri_path: uriPath.value.trim(),
        uri_query: uriQuery.value.trim(),
        http_method: httpMethod.value,
        http_headers: headersOut(),
        body_encoding: bodyEncoding.value === 'none' ? null : bodyEncoding.value,
        body: bodyPresent() ? body.value : null,
        send_string: send === '' ? null : send,
        receive_string: recv === '' ? null : recv,
        receive_regex: receiveRegex.value,
        disable_string: dis === '' ? null : dis,
        disable_regex: disableRegex.value,
        expected_status: status === '' ? null : status,
        follow_redirects: followRedirects.value,
        verify_tls: verifyTls.value,
        timeout: Number(timeout.value),
        auth_type: authType.value,
        auth_header_name: authType.value === 'header' ? authHeaderName.value.trim() : null,
        auth_credential_id: credential
    }
    if (!isEdit.value) {
        payload.pollcount = Number(pollcount.value)
        payload.pollinterval = Number(pollinterval.value)
    }

    try {
        const r = isEdit.value
            ? await putJson('/cgi-bin/api/service/' + encodeURIComponent(props.id), payload)
            : await postJson('/cgi-bin/api/service', payload)
        /* A create goes back to wherever the form was opened from —
         * usually the listing; an edit keeps landing on the record's
         * detail page. */
        if (!isEdit.value) {
            leaveForm(router, 'services')
        } else {
            const rid = (r && r.id) || (r && r.service && r.service.id) || props.id
            if (rid) {
                router.push({ name: 'service', params: { id: rid } })
            } else {
                router.push({ name: 'services' })
            }
        }
    } catch (err) {
        if (isForbidden(err)) {
            forbidden.value = true
            saveError.value = 'admin rights required — only admins can save services'
        } else {
            saveError.value = (err && err.message) || 'saving failed'
        }
    } finally {
        busy.value = false
    }
}

const showForm = computed(() =>
    !redirecting.value && !fillLoading.value && !fillError.value)

/* Cancel obeys the same exit rule as a create: back to wherever the
 * form was opened from, the listing when there is no history. */
function cancel() {
    leaveForm(router, 'services')
}
</script>

<template>
    <header class="bar">
        <div class="bar-title">
            <h1>{{ isEdit ? 'edit service' : 'new service' }}</h1>
        </div>
        <div class="bar-right">
            <router-link class="btn" :to="{ name: 'services' }">back to services</router-link>
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

    <p v-else-if="fillLoading" class="muted block-note">loading service…</p>

    <section v-if="showForm" class="panel form-panel">
        <h2>service details</h2>
        <form novalidate @submit.prevent="save">
            <div class="field">
                <label class="field-label" for="service-desc">description</label>
                <input id="service-desc" v-model="description" class="field-input" type="text">
            </div>

            <div class="field">
                <label class="field-label" for="service-agent">agent *</label>
                <select id="service-agent" v-model="agentId" class="field-input"
                        :class="{ invalid: attempted && errs.agent }">
                    <option value="" disabled>pick an agent</option>
                    <option v-for="a in agentOptions" :key="a.id" :value="a.id">
                        {{ a.label || a.name }}
                    </option>
                </select>
                <p v-if="attempted && errs.agent" class="err-note">{{ errs.agent }}</p>
            </div>

            <div class="field">
                <label class="field-label" for="service-target">target *</label>
                <select id="service-target" v-model="targetId" class="field-input"
                        :class="{ invalid: attempted && errs.target }">
                    <option value="" disabled>pick a target</option>
                    <option v-for="t in targetOptions" :key="t.id" :value="t.id">
                        {{ t.label || t.address }}
                    </option>
                </select>
                <p class="field-note muted">the host — path, port and scheme live on the service, so one host can back many checks.</p>
                <p v-if="attempted && errs.target" class="err-note">{{ errs.target }}</p>
            </div>

            <div class="field">
                <label class="field-label" for="service-scheme">scheme</label>
                <select id="service-scheme" v-model="scheme" class="field-input">
                    <option v-for="s in SCHEMES" :key="s" :value="s">{{ s }}</option>
                </select>
            </div>

            <div class="field">
                <label class="field-label" for="service-port">port</label>
                <input id="service-port" v-model="port" class="field-input" type="number" min="1" max="65535"
                       :class="{ invalid: attempted && errs.port }">
                <p class="field-note muted">blank = scheme default (http 80, https 443).</p>
                <p v-if="attempted && errs.port" class="err-note">{{ errs.port }}</p>
            </div>

            <div class="field">
                <label class="field-label" for="service-path">uri path</label>
                <input id="service-path" v-model="uriPath" class="field-input" type="text" spellcheck="false"
                       :class="{ invalid: attempted && errs.uripath }">
                <p class="field-note muted">starts with / — the host comes from the target, never from here.</p>
                <p v-if="attempted && errs.uripath" class="err-note">{{ errs.uripath }}</p>
            </div>

            <div class="field">
                <label class="field-label" for="service-query">uri query</label>
                <input id="service-query" v-model="uriQuery" class="field-input" type="text" spellcheck="false">
                <p class="field-note muted">everything after the ? — two services differing only by query are two distinct checks.</p>
            </div>

            <div class="field">
                <label class="field-label" for="service-method">http method</label>
                <select id="service-method" v-model="httpMethod" class="field-input">
                    <option v-for="m in METHODS" :key="m" :value="m">{{ m }}</option>
                </select>
            </div>

            <div class="field">
                <label class="field-label">headers</label>
                <div class="header-row" v-for="(h, i) in headerRows" :key="'hdr' + i">
                    <input v-model="h.name" class="field-input hd-name" type="text" spellcheck="false"
                           placeholder="name" :aria-label="'Header name ' + (i + 1)">
                    <input v-model="h.value" class="field-input hd-value" type="text" spellcheck="false"
                           placeholder="value" :aria-label="'Header value ' + (i + 1)">
                    <button class="btn" type="button" @click="removeHeader(i)">remove</button>
                </div>
                <button class="btn" type="button" @click="addHeader">add header</button>
                <p class="field-note muted">sent with the request — rows without a name are dropped.</p>
            </div>

            <div class="field">
                <label class="field-label" for="service-encoding">body encoding</label>
                <select id="service-encoding" v-model="bodyEncoding" class="field-input">
                    <option v-for="e in ENCODINGS" :key="e" :value="e">{{ e }}</option>
                </select>
            </div>

            <div class="field">
                <label class="field-label" for="service-body">body</label>
                <textarea id="service-body" v-model="body" class="field-input" rows="3" spellcheck="false"
                          :class="{ invalid: attempted && (errs.bodyenc || errs.both) }"></textarea>
                <p class="field-note muted">sent as the request body — the encoding says how.</p>
                <p v-if="attempted && errs.bodyenc" class="err-note">{{ errs.bodyenc }}</p>
                <p v-if="attempted && errs.both" class="err-note">{{ errs.both }}</p>
            </div>

            <div class="field">
                <label class="field-label" for="service-send">send string</label>
                <input id="service-send" v-model="sendString" class="field-input" type="text" spellcheck="false"
                       :class="{ invalid: attempted && errs.both }">
                <p class="field-note muted">F5 model — when set, it IS the request body; leave body empty.</p>
                <p v-if="attempted && errs.both" class="err-note">{{ errs.both }}</p>
            </div>

            <div class="field">
                <label class="field-label" for="service-receive">receive string</label>
                <input id="service-receive" v-model="receiveString" class="field-input" type="text" spellcheck="false">
                <label class="check">
                    <input id="service-receive-regex" v-model="receiveRegex" type="checkbox">
                    regex
                </label>
                <p class="field-note muted">up requires a match against the response body — substring unless regex is ticked.</p>
            </div>

            <div class="field">
                <label class="field-label" for="service-disable">disable string</label>
                <input id="service-disable" v-model="disableString" class="field-input" type="text" spellcheck="false">
                <label class="check">
                    <input id="service-disable-regex" v-model="disableRegex" type="checkbox">
                    regex
                </label>
                <p class="field-note muted">a match forces DOWN even when the receive string also matched.</p>
            </div>

            <div class="field">
                <label class="field-label" for="service-status">expected status</label>
                <input id="service-status" v-model="expectedStatus" class="field-input" type="text" spellcheck="false"
                       :class="{ invalid: attempted && errs.status }">
                <p class="field-note muted">blank = any 2xx/3xx — e.g. 200 or 200-299,301.</p>
                <p v-if="attempted && errs.status" class="err-note">{{ errs.status }}</p>
            </div>

            <div class="field">
                <label class="check">
                    <input id="service-redirects" v-model="followRedirects" type="checkbox">
                    follow redirects
                </label>
            </div>

            <div class="field">
                <label class="check">
                    <input id="service-tls" v-model="verifyTls" type="checkbox">
                    verify tls certificate
                </label>
                <p class="field-note muted">on by default; an unverified check is marked on the service and logged.</p>
            </div>

            <div class="field">
                <label class="field-label" for="service-timeout">timeout (s)</label>
                <input id="service-timeout" v-model="timeout" class="field-input" type="number" min="1" max="120"
                       :class="{ invalid: attempted && errs.timeout }">
                <p v-if="attempted && errs.timeout" class="err-note">{{ errs.timeout }}</p>
            </div>

            <div class="field">
                <label class="field-label" for="service-auth-type">auth type</label>
                <select id="service-auth-type" v-model="authType" class="field-input">
                    <option v-for="a in AUTH_TYPES" :key="a" :value="a">{{ a }}</option>
                </select>
            </div>

            <div class="field" v-if="authType === 'header'">
                <label class="field-label" for="service-auth-header">auth header name</label>
                <input id="service-auth-header" v-model="authHeaderName" class="field-input" type="text" spellcheck="false"
                       :class="{ invalid: attempted && errs.authhdr }">
                <p class="field-note muted">the credential's secret is sent under this header.</p>
                <p v-if="attempted && errs.authhdr" class="err-note">{{ errs.authhdr }}</p>
            </div>

            <div class="field" v-if="authType !== 'none'">
                <label class="field-label" for="service-auth-cred">credential *</label>
                <select id="service-auth-cred" v-model="authCredentialId" class="field-input"
                        :class="{ invalid: attempted && errs.authcred }">
                    <option value="" disabled>pick a credential</option>
                    <option v-for="c in credentialOptions" :key="c.id" :value="c.id">
                        {{ c.label }}
                    </option>
                </select>
                <p class="field-note muted">the secret stays in the vault — the service stores only which credential to use.</p>
                <p v-if="attempted && errs.authcred" class="err-note">{{ errs.authcred }}</p>
            </div>

            <template v-if="!isEdit">
                <div class="field">
                    <label class="field-label" for="service-pollcount">poll count</label>
                    <input id="service-pollcount" v-model="pollcount" class="field-input" type="number" min="1"
                           :class="{ invalid: attempted && errs.pollcount }">
                    <p class="field-note muted">probes per cycle — set once, the rrd file is sized by it.</p>
                    <p v-if="attempted && errs.pollcount" class="err-note">{{ errs.pollcount }}</p>
                </div>

                <div class="field">
                    <label class="field-label" for="service-pollinterval">poll interval (s)</label>
                    <input id="service-pollinterval" v-model="pollinterval" class="field-input" type="number" min="1"
                           :class="{ invalid: attempted && errs.pollinterval }">
                    <p class="field-note muted">seconds between cycles — services default to 300 (5 min); set once, the rrd file is sized by it.</p>
                    <p v-if="attempted && errs.pollinterval" class="err-note">{{ errs.pollinterval }}</p>
                </div>
            </template>
            <p v-else class="field-note muted">
                poll schedule is fixed after create ({{ pollcount }}x every {{ pollinterval }}s) —
                the rrd file is sized by it.
            </p>

            <div class="actions">
                <button class="btn submit" type="submit" :disabled="busy">
                    {{ busy ? 'saving…' : (isEdit ? 'save service' : 'create service') }}
                </button>
                <button class="btn" type="button" @click="cancel">cancel</button>
            </div>
        </form>
    </section>

</template>

<style scoped>
/* Form styling stays local, same as the other editors: the shared
 * stylesheet is dashboard-only. */
.form-panel {
    max-width: 640px;
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

/* Header rows sit on one line: name, value, remove door. Name is the
 * narrower column because values (tokens, json types) run longer. */
.header-row {
    display: flex;
    gap: 8px;
    margin-bottom: 6px;
}

.hd-name {
    flex: 0 0 30%;
}

.hd-value {
    flex: 1 1 auto;
}

.check {
    align-items: center;
    cursor: pointer;
    display: inline-flex;
    gap: 7px;
    margin-top: 6px;
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