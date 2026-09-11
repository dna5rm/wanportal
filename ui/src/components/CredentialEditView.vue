<!--
  Credential create/edit, ported from htdocs/credential_edit.php. One
  component serves both doors: /credentials/new starts an empty form,
  /credentials/:id/edit prefills from the detail api. Writing is
  admin-only on the api (403 otherwise), so the form only assembles
  for an admin token, and a signed-out visitor is walked to /login.

  Two rules carried over from the classic editor, on purpose:
  - blank fields are dropped from the payload, so an empty password
    leaves the stored secret untouched instead of wiping it;
  - metadata is parsed client-side and sent as JSON — the api
    re-encodes it server-side, so a raw string would arrive
    double-encoded.

  One deliberate divergence: the classic editor prefills the stored
  password into the form. Here the field starts blank and blank always
  means "unchanged", so the secret never needs to enter the DOM at all
  on a page whose only job is to change it.
-->
<script setup>
import { computed, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { getJson } from '../api'
import { authHeaders, clearToken, getToken, getSession } from '../session'
import { humanErr, idFromLocation } from './detailShared'
import { leaveForm } from './goBack'

const props = defineProps({
    id: { type: String, default: '' }
})

const router = useRouter()

const credentialId = computed(() => idFromLocation(props.id))
const isEdit = computed(() => !!credentialId.value)

const session = ref(null)
const sessionReady = ref(false)
const redirected = ref(false)
const loading = ref(false)
const loadError = ref(null)   // prefill failure (bad id, dead api)
const formError = ref(null)   // validation + save errors
const busy = ref(false)

/* Form state. The password field holds only a new secret: it starts
 * blank, is never prefilled, and is dropped from the payload when
 * left blank on save. */
const name = ref('')
const type = ref('')
const site = ref('')
const username = ref('')
const password = ref('')
const url = ref('')
const owner = ref('')
const comment = ref('')
const sensitivity = ref('MEDIUM')
const expiry = ref('')
const metaText = ref('')
const isActive = ref(true)

const reveal = ref(false)

/* The same five types and four levels the api validates against;
 * keeping the lists here means a typo fails on the spot, not at 400. */
const TYPES = ['ACCOUNT', 'CERTIFICATE', 'API', 'PSK', 'CODE']
const SENSITIVITIES = ['LOW', 'MEDIUM', 'HIGH', 'CRITICAL']

/*
 * api.js only speaks GET and POST, and this view also needs PUT.
 * Rather than grow the shared fetcher for one caller, the write
 * plumbing lives here with the same rules: Bearer header from the
 * stored token, credentials:'omit', a 10s timeout, and the api's
 * error body surfaced as a message instead of a bare status code.
 * A 401 drops the expired token exactly like api.js does.
 */
async function sendJson(method, url, body) {
    const ctrl = new AbortController()
    const killer = setTimeout(() => ctrl.abort(), 10000)
    try {
        const res = await fetch(url, {
            method,
            credentials: 'omit',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                ...authHeaders()
            },
            body: JSON.stringify(body)
        })
        if (res.status === 401 && getToken()) clearToken()
        const json = await res.json().catch(() => null)
        if (!res.ok || !json || json.status !== 'success') {
            throw new Error((json && json.message) || 'HTTP ' + res.status)
        }
        return json
    } finally {
        clearTimeout(killer)
    }
}

/* MySQL hands the expiry back as 'YYYY-MM-DD HH:MM:SS'; the
 * datetime-local input wants 'YYYY-MM-DDTHH:MM'. */
function toLocalInput(s) {
    const m = /^(\d{4}-\d{2}-\d{2})[ T](\d{2}:\d{2})/.exec(String(s || ''))
    return m ? m[1] + 'T' + m[2] : ''
}

/* Metadata arrives as a json string from the DB; pretty-print it for
 * the textarea like the classic editor does. Anything that is not
 * valid JSON stays visible verbatim rather than vanishing. */
function prettyMeta(m) {
    if (m === null || m === undefined || m === '') return ''
    try {
        return JSON.stringify(typeof m === 'string' ? JSON.parse(m) : m, null, 2)
    } catch {
        return String(m)
    }
}

async function loadCred() {
    loading.value = true
    loadError.value = null
    try {
        const json = await getJson('/cgi-bin/api/credentials/' + encodeURIComponent(credentialId.value))
        const c = json.credential
        if (!c) {
            loadError.value = 'credential payload was empty'
            return
        }
        name.value = c.name || ''
        type.value = c.type || ''
        site.value = c.site || ''
        username.value = c.username || ''
        url.value = c.url || ''
        owner.value = c.owner || ''
        comment.value = c.comment || ''
        sensitivity.value = c.sensitivity || 'MEDIUM'
        expiry.value = toLocalInput(c.expiry_date)
        metaText.value = prettyMeta(c.metadata)
        isActive.value = Number(c.is_active) !== 0
    } catch (e) {
        loadError.value = humanErr(e, 'credential not found')
    } finally {
        loading.value = false
    }
}

/*
 * Build the payload the way the classic editor does: blank strings
 * are dropped, so a field left empty simply is not sent and the api
 * keeps the stored value. The password rides that rule — blank means
 * unchanged. is_active always goes out (the checkbox is a real
 * statement, true or false), and metadata goes out parsed, as the
 * object the api expects.
 */
function buildPayload() {
    const payload = {}
    const fields = {
        name: name.value,
        type: type.value,
        site: site.value,
        username: username.value,
        url: url.value,
        owner: owner.value,
        comment: comment.value
    }
    for (const k in fields) {
        if (fields[k] !== '') payload[k] = fields[k]
    }
    if (password.value !== '') payload.password = password.value
    if (expiry.value) payload.expiry_date = expiry.value
    const metaTrim = metaText.value.trim()
    if (metaTrim) payload.metadata = JSON.parse(metaTrim)
    payload.is_active = isActive.value
    return payload
}

async function save() {
    if (busy.value) return
    formError.value = null
    if (!name.value.trim() || !type.value) {
        formError.value = 'name and type are required.'
        return
    }
    if (metaText.value.trim()) {
        try {
            JSON.parse(metaText.value)
        } catch {
            formError.value = 'invalid JSON in metadata field.'
            return
        }
    }
    busy.value = true
    try {
        const payload = buildPayload()
        if (isEdit.value) {
            await sendJson('PUT', '/cgi-bin/api/credentials/' + encodeURIComponent(credentialId.value), payload)
            router.push({ name: 'credentials' })
        } else {
            /* A create goes back to wherever the form was opened from —
             * usually the listing; an edit keeps landing on the
             * listing as before. */
            await sendJson('POST', '/cgi-bin/api/credentials', payload)
            leaveForm(router, 'credentials')
        }
    } catch (e) {
        formError.value = (e && e.message) || 'save failed'
    } finally {
        busy.value = false
    }
}

/* Cancel obeys the same exit rule as a create: back to wherever the
 * form was opened from, the listing when there is no history. */
function cancel() {
    leaveForm(router, 'credentials')
}

function toLogin() {
    redirected.value = true
    router.replace({ name: 'login' })
}

const gateNote = computed(() => {
    if (!sessionReady.value || !session.value) return null
    if (session.value.authenticated) return null
    if (session.value.reason === 'unavailable') {
        return 'session check failed (' + (session.value.error || 'unknown') + ') — the form stays hidden rather than guessed'
    }
    return null
})

onMounted(async () => {
    const s = await getSession()
    session.value = s
    sessionReady.value = true
    if (!s.authenticated) {
        if (s.reason === 'signed-out') toLogin()
        return
    }
    if (!s.isAdmin) return // the admin gate explains; nothing loads
    if (isEdit.value) loadCred()
})
</script>

<template>
    <header class="bar">
        <div class="bar-title">
            <h1>{{ isEdit ? 'edit credential' : 'new credential' }}</h1>
            <span class="muted">saving needs an admin token · blank password keeps the stored one</span>
        </div>
        <div class="bar-right">
            <span v-if="loading" class="muted">loading&hellip;</span>
            <router-link class="btn" :to="{ name: 'credentials' }">back to list</router-link>
            <router-link v-if="isEdit && credentialId" class="btn"
                         :to="{ name: 'credential', params: { id: credentialId } }">view record</router-link>
        </div>
    </header>

    <div v-if="redirected" class="panel gate muted">
        signed out — sending you to the sign-in page&hellip;
    </div>

    <div v-if="gateNote" class="panel gate">
        <p>{{ gateNote }}</p>
        <p>back to the dashboard: <a href="#/">/</a></p>
    </div>

    <div v-if="sessionReady && session && session.authenticated && !session.isAdmin" class="panel gate">
        <p>
            <span class="chip chip-danger">admin only</span>
            <span class="muted">
                signed in as {{ session.username }} — creating and updating credentials needs an admin token.
                The listing itself stays open to every signed-in user.
            </span>
        </p>
        <p>back to the listing: <router-link :to="{ name: 'credentials' }">/credentials</router-link></p>
    </div>

    <template v-if="session && session.authenticated && session.isAdmin">
        <div v-if="loadError" class="banner banner-error">{{ loadError }}</div>

        <form v-if="!loadError" class="cred-form" @submit.prevent="save">
            <section class="panel">
                <h2>basic information</h2>
                <div class="field">
                    <label class="field-label" for="cred-name">name *</label>
                    <input id="cred-name" v-model="name" type="text" required>
                </div>
                <div class="field">
                    <label class="field-label" for="cred-type">type *</label>
                    <select id="cred-type" v-model="type" required>
                        <option value="">select type...</option>
                        <option v-for="t in TYPES" :key="t" :value="t">{{ t }}</option>
                    </select>
                </div>
                <div class="field">
                    <label class="field-label" for="cred-site">site</label>
                    <input id="cred-site" v-model="site" type="text">
                </div>
                <div class="field">
                    <label class="field-label" for="cred-username">username</label>
                    <input id="cred-username" v-model="username" type="text">
                </div>
                <div class="field">
                    <label class="field-label" for="cred-password">password / key</label>
                    <div class="copy-row">
                        <input id="cred-password" v-model="password" :type="reveal ? 'text' : 'password'"
                               :placeholder="isEdit ? 'blank = leave the stored one unchanged' : ''"
                               autocomplete="new-password">
                        <button class="btn" type="button" @click="reveal = !reveal">
                            {{ reveal ? 'hide' : 'show' }}
                        </button>
                    </div>
                </div>
                <div class="field">
                    <label class="field-label" for="cred-url">url</label>
                    <input id="cred-url" v-model="url" type="url">
                </div>
            </section>

            <section class="panel">
                <h2>additional information</h2>
                <div class="field">
                    <label class="field-label" for="cred-owner">owner</label>
                    <input id="cred-owner" v-model="owner" type="text">
                </div>
                <div class="field">
                    <label class="field-label" for="cred-sensitivity">sensitivity</label>
                    <select id="cred-sensitivity" v-model="sensitivity">
                        <option v-for="s in SENSITIVITIES" :key="s" :value="s">{{ s }}</option>
                    </select>
                </div>
                <div class="field">
                    <label class="field-label" for="cred-expiry">expiry date</label>
                    <input id="cred-expiry" v-model="expiry" type="datetime-local">
                </div>
                <div class="field">
                    <label class="field-label" for="cred-comment">comment</label>
                    <textarea id="cred-comment" v-model="comment" rows="3"></textarea>
                </div>
                <div class="field">
                    <label class="field-label" for="cred-metadata">metadata (json)</label>
                    <textarea id="cred-metadata" v-model="metaText" rows="5" class="mono"></textarea>
                </div>
                <label class="check">
                    <input v-model="isActive" type="checkbox">
                    active
                </label>
            </section>

            <p v-if="formError" class="err-note block" role="alert">{{ formError }}</p>

            <div class="actions">
                <button class="btn btn-save" type="submit" :disabled="busy">
                    {{ busy ? 'saving…' : 'save credential' }}
                </button>
                <button class="btn" type="button" @click="cancel">cancel</button>
            </div>
        </form>
    </template>

</template>

<style scoped>
/* Dark form controls in the base palette, same family as the login
 * card; kept local so the shared stylesheet stays dashboard-only. */
.field {
    display: block;
    margin-bottom: 12px;
    max-width: 420px;
}

.field-label {
    color: var(--muted);
    display: block;
    font-size: 11px;
    letter-spacing: .6px;
    margin-bottom: 3px;
    text-transform: uppercase;
}

.field input,
.field select,
.field textarea {
    background: var(--bg);
    border: 1px solid var(--panel-edge);
    border-radius: 6px;
    color: var(--text);
    font: inherit;
    padding: 6px 9px;
    width: 100%;
}

.field input:focus,
.field select:focus,
.field textarea:focus {
    border-color: var(--up);
    outline: none;
}

.field textarea {
    resize: vertical;
}

.mono {
    font-family: ui-monospace, "SF Mono", Menlo, Consolas, monospace;
    font-size: 12px;
}

.copy-row {
    display: flex;
    align-items: center;
    gap: 6px;
}

.check {
    align-items: center;
    color: var(--muted);
    cursor: pointer;
    display: inline-flex;
    font-size: 12px;
    gap: 5px;
}

.actions {
    display: flex;
    align-items: center;
    gap: 10px;
    margin-bottom: 14px;
}

.btn-save {
    background: var(--up-bg);
    border-color: var(--up);
}

.btn-save:disabled {
    cursor: default;
    opacity: .6;
}

.gate {
    max-width: 640px;
    line-height: 1.6;
}

.gate p { margin: 0 0 6px; }
.gate p:last-child { margin-bottom: 0; }
</style>