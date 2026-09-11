<!--
  New/Edit form for agents — the bundled app's first write path. One
  component does both jobs, exactly like the classic agents_edit.php:
  no id means create, an id means edit with the record filled in from
  the public detail endpoint.

  The field rules follow the classic page: name and address are
  required, the address must be a routable IPv4/IPv6 address (never a
  DNS name), the password is required only on create, and on an edit a
  blank password keeps the stored one. LOCAL is the built-in agent on
  this host, so its name and active flag are locked just like the
  classic form — but the loaded active flag still goes out with the
  save, where the classic page's disabled checkbox silently dropped it
  and saved LOCAL as inactive. That bug does not get ported.

  Writes need an admin JWT, so without a token in this tab the view
  redirects to the sign-in route instead of rendering a form that can
  only ever 401.
-->
<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import { getJson } from '../api'
import { authHeaders, clearToken, getToken } from '../session'
import { humanErr, uuidOk } from './detailShared'
import { leaveForm } from './goBack'

const props = defineProps({
    id: { type: String, default: '' }
})

const router = useRouter()

/* Two jobs, one component: an id off the route flips us into edit
 * mode, same split the classic page makes with $_GET['id']. */
const isEdit = computed(() => !!props.id)

const name = ref('')
const address = ref('')
const description = ref('')
const password = ref('')        // only ever set by typing; blank = keep stored
const isActive = ref(true)
const showPass = ref(false)
const busy = ref(false)
const attempted = ref(false)    // field notes stay hidden until first submit
const saveError = ref('')
const fillLoading = ref(false)
const fillError = ref('')
const loadedName = ref('')      // name as the API last returned it
const redirecting = ref(false)

/* LOCAL is the portal's own agent; the classic page locks its name and
 * active flag, and this form keeps that rule. */
const nameLocked = computed(() => isEdit.value && loadedName.value === 'LOCAL')

/*
 * Address must be IPv4 or IPv6 — the agent polls by IP, and the API's
 * CHECK constraint rejects anything else. IPv4 gets real octet bounds;
 * IPv6 gets a structural check: hex groups, at most one '::' squeeze,
 * an optional link-local zone suffix.
 */
function validAddress(s) {
    const v = String(s || '').trim()
    if (/^(\d{1,3})\.(\d{1,3})\.(\d{1,3})\.(\d{1,3})$/.test(v)) {
        return v.split('.').every((o) => Number(o) <= 255)
    }
    if (!v.includes(':')) return false
    const [addr, zone] = v.split('%')
    if (zone !== undefined && !/^[0-9a-zA-Z]+$/.test(zone)) return false
    const sides = addr.split('::')
    if (sides.length > 2) return false
    const groups = (side) => (side === '' ? [] : side.split(':'))
    const all = [...groups(sides[0]), ...(sides.length === 2 ? groups(sides[1]) : [])]
    if (all.some((g) => !/^[0-9a-fA-F]{1,4}$/.test(g))) return false
    return sides.length === 2 ? all.length < 8 : all.length === 8
}

/* The same field rules the classic form enforces, phrased with its
 * wording. Empty address reads as invalid rather than getting its own
 * "required" note — one message per field keeps it honest. */
const errs = computed(() => ({
    name: name.value.trim() ? '' : 'Name is required',
    address: validAddress(address.value) ? '' : 'Valid IP address is required',
    password: (isEdit.value || password.value) ? '' : 'Password is required'
}))

/* api.js carries the GET/POST helpers and is a shared file, so the
 * write calls live here: same same-origin fetch, same 10s timeout,
 * same envelope rules — but the API's own message (duplicate name,
 * invalid IP, ...) is surfaced instead of a bare HTTP code, the way
 * the classic page showed it. */
async function sendJson(method, url, body) {
    const ctrl = new AbortController()
    const killer = setTimeout(() => ctrl.abort(), 10000)
    try {
        const res = await fetch(url, {
            method,
            credentials: 'omit', // the API takes the Bearer header, not cookies
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                ...authHeaders()
            },
            body: JSON.stringify(body),
            signal: ctrl.signal
        })
        if (res.status === 401 && getToken()) clearToken()
        let json = null
        try { json = await res.json() } catch { /* non-JSON body: report the status */ }
        if (!res.ok || !json || json.status !== 'success') {
            const err = new Error((json && json.message) || 'HTTP ' + res.status)
            err.status = res.status
            throw err
        }
        return json
    } catch (err) {
        if (err.name === 'AbortError') throw new Error('request timed out')
        throw err
    } finally {
        clearTimeout(killer)
    }
}

/* Fill from the public detail endpoint — no token needed to read one
 * agent, though the page itself is already token-gated. */
async function fill() {
    if (!uuidOk(props.id)) {
        fillError.value = 'no agent id found in the url'
        return
    }
    fillLoading.value = true
    fillError.value = ''
    try {
        const json = await getJson('/cgi-bin/api/agents/' + encodeURIComponent(props.id))
        const a = json.agent || null
        if (!a) {
            fillError.value = 'agent payload was empty'
            return
        }
        loadedName.value = a.name || ''
        name.value = a.name || ''
        address.value = a.address || ''
        description.value = a.description || ''
        isActive.value = Number(a.is_active) === 1
    } catch (err) {
        fillError.value = humanErr(err, 'agent not found')
    } finally {
        fillLoading.value = false
    }
}

/* No token, no form: a doomed submit helps nobody. The muted note
 * below covers the moment before the redirect lands. */
function requireToken() {
    if (getToken()) return true
    redirecting.value = true
    router.replace('/login')
    return false
}

onMounted(() => {
    if (!requireToken()) return
    if (isEdit.value) fill()
})

/* The router reuses this component when only the id changes, so an
 * open edit form must refetch or it would keep showing the previous
 * agent. */
watch(() => props.id, () => {
    if (isEdit.value) fill()
})

async function save() {
    if (busy.value) return
    attempted.value = true
    if (errs.value.name || errs.value.address || errs.value.password) return
    if (!requireToken()) return

    busy.value = true
    saveError.value = ''
    /* Same body the classic page posts. The password rides along only
     * when typed: the update endpoint changes exactly the fields it
     * receives, so a blank field on an edit leaves the stored one
     * untouched. */
    const payload = {
        name: name.value.trim(),
        address: address.value.trim(),
        description: description.value,
        is_active: isActive.value
    }
    if (password.value) payload.password = password.value

    try {
        if (isEdit.value) {
            await sendJson('PUT', '/cgi-bin/api/agent/' + encodeURIComponent(props.id), payload)
        } else {
            await sendJson('POST', '/cgi-bin/api/agent', payload)
        }
        password.value = '' // the secret went out in the call, not into state
        /* A create goes back to wherever the form was opened from —
         * usually the listing; an edit keeps landing on the listing
         * as before. */
        if (isEdit.value) {
            router.push({ name: 'agents' })
        } else {
            leaveForm(router, 'agents')
        }
    } catch (err) {
        if (err && err.status === 401) {
            // The token expired mid-edit; sign in again and come back.
            redirecting.value = true
            router.replace('/login')
        } else {
            saveError.value = (err && err.message) || 'saving failed'
        }
    } finally {
        busy.value = false
    }
}

const showForm = computed(() =>
    !redirecting.value && !fillLoading.value && !fillError.value)

/* Cancel obeys the same exit rule as a create: back to the page the
 * form was opened from, the listing when there is no history. */
function cancel() {
    leaveForm(router, 'agents')
}

const banner = computed(() => fillError.value || saveError.value)
</script>

<template>
    <header class="bar">
        <div class="bar-title">
            <h1>{{ isEdit ? 'edit agent' : 'new agent' }}</h1>
            <span v-if="loadedName" class="muted">{{ loadedName }}</span>
        </div>
        <div class="bar-right">
            <router-link class="btn" :to="{ name: 'agents' }">back to agents</router-link>
        </div>
    </header>

    <div v-if="banner" class="banner banner-error" role="alert">
        {{ banner }} — nothing was saved.
    </div>

    <p v-if="redirecting" class="muted block-note">
        sign-in required — taking you to the sign-in page…
    </p>

    <p v-else-if="fillLoading" class="muted block-note">loading agent…</p>

    <section v-if="showForm" class="panel form-panel">
        <h2>agent details</h2>
        <form novalidate @submit.prevent="save">
            <div class="field">
                <label class="field-label" for="agent-name">name *</label>
                <input id="agent-name" v-model="name" class="field-input" type="text"
                       :readonly="nameLocked" :class="{ invalid: attempted && errs.name }">
                <p v-if="attempted && errs.name" class="err-note">{{ errs.name }}</p>
            </div>

            <div class="field">
                <label class="field-label" for="agent-address">address *</label>
                <input id="agent-address" v-model="address" class="field-input" type="text"
                       :class="{ invalid: attempted && errs.address }">
                <p class="field-note muted">IPv4 or IPv6 — the agent needs a routable address, not a DNS name.</p>
                <p v-if="attempted && errs.address" class="err-note">{{ errs.address }}</p>
            </div>

            <div class="field">
                <label class="field-label" for="agent-desc">description</label>
                <input id="agent-desc" v-model="description" class="field-input" type="text">
            </div>

            <div class="field">
                <label class="field-label" for="agent-pass">
                    password{{ isEdit ? '' : ' *' }}
                </label>
                <div class="pass-row">
                    <input id="agent-pass" v-model="password" class="field-input"
                           autocomplete="new-password"
                           :type="showPass ? 'text' : 'password'"
                           :class="{ invalid: attempted && errs.password }">
                    <button class="btn" type="button" @click="showPass = !showPass">
                        {{ showPass ? 'hide' : 'show' }}
                    </button>
                </div>
                <p v-if="isEdit" class="field-note muted">leave blank to keep the stored password</p>
                <p v-if="attempted && errs.password" class="err-note">{{ errs.password }}</p>
            </div>

            <div class="field">
                <label class="check">
                    <input id="agent-active" v-model="isActive" type="checkbox" :disabled="nameLocked">
                    active
                </label>
                <p v-if="nameLocked" class="field-note muted">
                    LOCAL is the built-in agent — its name and active flag are fixed.
                </p>
            </div>

            <div class="actions">
                <button class="btn submit" type="submit" :disabled="busy">
                    {{ busy ? 'saving…' : (isEdit ? 'save agent' : 'create agent') }}
                </button>
                <button class="btn" type="button" @click="cancel">cancel</button>
            </div>
        </form>
    </section>

</template>

<style scoped>
/* Form styling stays local, same as the login card: the shared
 * stylesheet is dashboard-only and this is the first form in the app. */
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

.field-input:disabled,
.field-input[readonly] {
    opacity: .6;
}

.field-input.invalid {
    border-color: var(--danger);
}

.field-note {
    font-size: 11.5px;
    margin: 4px 0 0;
}

.pass-row {
    display: flex;
    gap: 8px;
}

.pass-row .field-input { flex: 1; }
.pass-row .btn { white-space: nowrap; }

.check {
    align-items: center;
    cursor: pointer;
    display: inline-flex;
    gap: 7px;
}

.check input:disabled { cursor: default; }

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