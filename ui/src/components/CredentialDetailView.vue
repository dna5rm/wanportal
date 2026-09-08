<!--
  Credential detail, ported from htdocs/credential_view.php. The api
  hands the stored secret back to admin tokens only, so the password
  row exists only when the response actually carries one — a non-admin
  sees the dash the classic page showed for an unset secret. Every
  detail fetch also stamps last_accessed on the record server-side;
  that is the "view" action the classic page performed, and the muted
  note under the title says so rather than hiding it.

  The secret renders into a readonly input with reveal and copy
  buttons, the same interaction as the classic page. It is never
  logged and never printed anywhere else — and since this page is
  read-only, nothing here sends it anywhere either.
-->
<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { getJson } from '../api'
import { getSession } from '../session'
import { humanErr, idFromLocation } from './detailShared'

const props = defineProps({
    id: { type: String, default: '' }
})

const router = useRouter()

/* The route hands the id over as a prop; the shared fallback keeps
 * '?id=...' style links working, same as the sibling detail pages. */
const credentialId = computed(() => idFromLocation(props.id))

const session = ref(null)
const sessionReady = ref(false)
const cred = ref(null)
const error = ref(null)
const loading = ref(false)
const redirected = ref(false)

/* Reveal flips the input type only; copied flashes the button text
 * for a second like the classic page does. */
const reveal = ref(false)
const copiedKey = ref('')
let copiedTimer = null

function flashCopied(key) {
    copiedKey.value = key
    clearTimeout(copiedTimer)
    copiedTimer = setTimeout(() => { copiedKey.value = '' }, 1000)
}

/* Click-to-copy like the classic page; a no-op where the clipboard
 * api is missing (hardened webviews) rather than an error. */
function copyText(key, v) {
    if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(String(v)).then(
            () => flashCopied(key),
            () => {} // clipboard refused — the value stays readable on screen
        )
    }
}

const hasSecret = computed(() => !!(cred.value && cred.value.password))

/* The metadata column comes back as a json string from the DB; pretty
 * print it like the classic page does. Whatever arrives that is not
 * valid JSON stays visible verbatim rather than vanishing. */
const prettyMeta = computed(() => {
    const m = cred.value && cred.value.metadata
    if (m === null || m === undefined || m === '') return ''
    try {
        return JSON.stringify(typeof m === 'string' ? JSON.parse(m) : m, null, 2)
    } catch {
        return String(m)
    }
})

/* Active/inactive uses danger for the off state, matching the classic
 * view page's badges (the listings use warn; here it is a single
 * record, and the classic page calls an inactive vault entry danger). */
function statusCls(c) {
    return Number(c.is_active) === 1 ? 'chip chip-ok' : 'chip chip-danger'
}

/* LOW green, MEDIUM amber, HIGH and CRITICAL red — the classic
 * sensitivity badges without the CRITICAL-is-black pitfall. */
function sensCls(s) {
    if (s === 'LOW') return 'chip chip-ok'
    if (s === 'HIGH' || s === 'CRITICAL') return 'chip chip-danger'
    return 'chip chip-warn'
}

/* Same per-type chips as the listing. */
const TYPE_CHIPS = ['ACCOUNT', 'CERTIFICATE', 'API', 'PSK']
function typeCls(t) {
    return 'chip ' + (TYPE_CHIPS.includes(t) ? 'type-' + t : 'type-CODE')
}

function stampRow(s, by) {
    if (!s) return 'Never'
    return String(s) + (by ? ' by ' + by : '')
}

function toLogin() {
    redirected.value = true
    router.replace({ name: 'login' })
}

const gateNote = computed(() => {
    if (!sessionReady.value || !session.value) return null
    if (session.value.authenticated) return null
    if (session.value.reason === 'unavailable') {
        return 'session check failed (' + (session.value.error || 'unknown') + ') — the record stays hidden rather than guessed'
    }
    return null
})

async function fetchCred() {
    if (!credentialId.value) {
        error.value = 'no credential id found in the url'
        return
    }
    loading.value = true
    error.value = null
    try {
        const json = await getJson('/cgi-bin/api/credentials/' + encodeURIComponent(credentialId.value))
        cred.value = json.credential || null
        if (!cred.value) error.value = 'credential payload was empty'
    } catch (e) {
        cred.value = null
        error.value = humanErr(e, 'credential not found')
    } finally {
        loading.value = false
    }
}

onMounted(async () => {
    const s = await getSession()
    session.value = s
    sessionReady.value = true
    if (!s.authenticated) {
        if (s.reason === 'signed-out') toLogin()
        return
    }
    fetchCred()
})

onBeforeUnmount(() => {
    clearTimeout(copiedTimer)
})
</script>

<template>
    <header class="bar">
        <div class="bar-title">
            <h1>credential</h1>
            <span v-if="cred" class="muted">{{ cred.name }}</span>
            <span class="muted">· each view stamps last-accessed on the record</span>
        </div>
        <div class="bar-right">
            <span v-if="loading" class="muted">loading&hellip;</span>
            <router-link class="btn" :to="{ name: 'credentials' }">back to list</router-link>
            <router-link v-if="cred" class="btn" :to="{ name: 'credential-edit', params: { id: credentialId } }">
                edit
            </router-link>
        </div>
    </header>

    <div v-if="redirected" class="panel gate muted">
        signed out — sending you to the sign-in page&hellip;
    </div>

    <div v-if="gateNote" class="panel gate">
        <p>{{ gateNote }}</p>
        <p>back to the dashboard: <a href="#/">/</a></p>
    </div>

    <div v-if="error" class="banner banner-error">{{ error }}</div>

    <template v-if="cred">
        <section class="panel">
            <h2>basic information</h2>
            <table class="fields">
                <tbody>
                <tr>
                    <th>Name</th>
                    <td>{{ cred.name }}</td>
                </tr>
                <tr>
                    <th>Site</th>
                    <td>{{ cred.site || '-' }}</td>
                </tr>
                <tr>
                    <th>Type</th>
                    <td><span :class="typeCls(cred.type)">{{ cred.type }}</span></td>
                </tr>
                <tr>
                    <th>Username</th>
                    <td>
                        <div v-if="cred.username" class="copy-row">
                            <input type="text" :value="cred.username" readonly>
                            <button class="btn" type="button" @click="copyText('username', cred.username)">
                                {{ copiedKey === 'username' ? 'copied' : 'copy' }}
                            </button>
                        </div>
                        <span v-else class="muted">-</span>
                    </td>
                </tr>
                <tr v-if="hasSecret">
                    <th>Password / Key</th>
                    <td>
                        <div class="copy-row">
                            <input :type="reveal ? 'text' : 'password'" :value="cred.password" readonly
                                   aria-label="stored secret">
                            <button class="btn" type="button" @click="reveal = !reveal">
                                {{ reveal ? 'hide' : 'show' }}
                            </button>
                            <button class="btn" type="button" @click="copyText('secret', cred.password)">
                                {{ copiedKey === 'secret' ? 'copied' : 'copy' }}
                            </button>
                        </div>
                    </td>
                </tr>
                <tr>
                    <th>URL</th>
                    <td>
                        <a v-if="cred.url" :href="cred.url" target="_blank" rel="noopener">{{ cred.url }}</a>
                        <span v-else class="muted">-</span>
                    </td>
                </tr>
                <tr>
                    <th>Owner</th>
                    <td>{{ cred.owner || '-' }}</td>
                </tr>
                <tr>
                    <th>Sensitivity</th>
                    <td><span :class="sensCls(cred.sensitivity)">{{ cred.sensitivity || '-' }}</span></td>
                </tr>
                </tbody>
            </table>
        </section>

        <section class="panel">
            <h2>record information</h2>
            <table class="fields">
                <tbody>
                <tr>
                    <th>Expires</th>
                    <td>{{ cred.expiry_date || '-' }}</td>
                </tr>
                <tr>
                    <th>Comment</th>
                    <td class="pre-wrap">{{ cred.comment || '-' }}</td>
                </tr>
                <tr>
                    <th>Metadata</th>
                    <td>
                        <pre v-if="prettyMeta" class="meta-block">{{ prettyMeta }}</pre>
                        <span v-else class="muted">-</span>
                    </td>
                </tr>
                <tr>
                    <th>Created</th>
                    <td>{{ stampRow(cred.created_at, cred.created_by) }}</td>
                </tr>
                <tr>
                    <th>Updated</th>
                    <td>{{ stampRow(cred.updated_at, cred.updated_by) }}</td>
                </tr>
                <tr>
                    <th>Last Accessed</th>
                    <td>{{ stampRow(cred.last_accessed_at, cred.last_accessed_by) }}</td>
                </tr>
                <tr>
                    <th>Status</th>
                    <td>
                        <span :class="statusCls(cred)">{{ Number(cred.is_active) === 1 ? 'Active' : 'Inactive' }}</span>
                    </td>
                </tr>
                </tbody>
            </table>
        </section>
    </template>

    <footer class="muted">
        vue detail · editing lives in the app, deletes on the classic console
    </footer>
</template>

<style scoped>
.fields th {
    width: 130px;
    vertical-align: top;
    white-space: nowrap;
}

.fields td {
    word-break: break-word;
}

/* Readonly value + copy button, the classic input-group in this
 * app's vocabulary. Inputs stay dark-on-dark like the login form. */
.copy-row {
    display: flex;
    align-items: center;
    gap: 6px;
}

.copy-row input {
    background: var(--bg);
    border: 1px solid var(--panel-edge);
    border-radius: 6px;
    color: var(--text);
    font: inherit;
    max-width: 320px;
    padding: 4px 8px;
}

.pre-wrap { white-space: pre-wrap; }

.meta-block {
    background: var(--bg);
    border: 1px solid var(--panel-edge);
    border-radius: 6px;
    font-size: 11.5px;
    margin: 0;
    max-width: 480px;
    overflow: auto;
    padding: 8px 10px;
}

.gate {
    max-width: 640px;
    line-height: 1.6;
}

.gate p { margin: 0 0 6px; }
.gate p:last-child { margin-bottom: 0; }

/* Same per-type chips as the listing. */
.type-ACCOUNT { background: rgba(96, 165, 250, .12); border-color: rgba(96, 165, 250, .55); }
.type-CERTIFICATE { background: var(--up-bg); border-color: rgba(76, 195, 138, .45); }
.type-API { background: rgba(34, 211, 238, .1); border-color: rgba(34, 211, 238, .5); }
.type-PSK { background: var(--warn-bg); border-color: var(--warn); }
.type-CODE { color: var(--muted); }
</style>