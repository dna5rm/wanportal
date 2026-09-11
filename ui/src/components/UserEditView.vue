<!--
  User create/edit for the bundled app, ported from htdocs/user_edit.php.

  One component serves both doors: no id prop means "new user" (POST to
  /cgi-bin/api/users), an id prop means "edit user" (GET then PUT to
  /cgi-bin/api/users/:id). The field set mirrors the classic form —
  username, full name, email, the admin/active checkboxes, and a
  password that only rides along when typed (blank on edit means
  "keep the current one").

  The api owns every rule that matters: password complexity, duplicate
  usernames, and the guard rails around the built-in admin account.
  Error answers carry a human message, so the form shows exactly what
  the api said instead of a bare status code. Passwords are never
  logged, never rendered, and dropped from form state once saved.

  One quirk carried over honestly: the api's PUT updates profile fields
  only and ignores username, so a renamed username silently keeps its
  old value — same as the classic page today.
-->
<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import { useRouter } from 'vue-router'
import { getJson } from '../api'
import { authHeaders, clearToken, getToken, getSession } from '../session'
import { humanErr } from './detailShared'
import { leaveForm } from './goBack'

const props = defineProps({
    id: { type: String, default: '' }
})

const router = useRouter()

const session = ref(null)
const sessionReady = ref(false)
const adminOnly = ref(false)   // api reachable but refuses this caller
const loading = ref(false)     // loading the user being edited
const loadError = ref('')
const busy = ref(false)        // a save is in flight
const saveError = ref('')
const user = ref(null)         // the loaded record, edit mode only
const showPassword = ref(false)

/* Form state. Create defaults match the classic page: a plain active
 * non-admin. The password starts empty in both modes — on edit, blank
 * means keep the current password. */
const form = reactive({
    username: '',
    full_name: '',
    email: '',
    password: '',
    is_admin: false,
    is_active: true
})

const editing = computed(() => !!props.id)

/* The built-in admin account is untouchable from the form: its name
 * freezes and the role/active switches go dark, because locking
 * yourself out of admin here is how you lose the console. The api
 * enforces the same rule server-side. */
const lockedAdmin = computed(() => editing.value && form.username === 'admin')

const canWork = computed(() =>
    !adminOnly.value && !!session.value && session.value.authenticated && session.value.isAdmin
)
const showForm = computed(() => canWork.value && !loading.value && !loadError.value)

/*
 * PUT lives beside the shared fetchers here because api.js ships only
 * GET/POST helpers. The envelope rules are copied from there: 10s
 * timeout, Bearer header when a token is stored, a 401 drops an
 * expired token, and an api error body wins over the bare status code
 * so the form can say "Username already exists" instead of "HTTP 400".
 * If api.js ever grows a putJson, this helper folds away.
 */
async function sendJson(method, url, body) {
    const ctrl = new AbortController()
    const killer = setTimeout(() => ctrl.abort(), 10000)
    let res
    try {
        res = await fetch(url, {
            method,
            credentials: 'omit', // the api takes Bearer, not cookies
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                ...authHeaders()
            },
            body: JSON.stringify(body),
            signal: ctrl.signal
        })
    } finally {
        clearTimeout(killer)
    }
    if (res.status === 401 && getToken()) clearToken()
    let payload = null
    try {
        payload = await res.json()
    } catch {
        // empty or non-JSON body — the status code below tells the story
    }
    if (!res.ok || !payload || payload.status !== 'success') {
        const err = new Error((payload && payload.message) || 'HTTP ' + res.status)
        err.status = res.status
        throw err
    }
    return payload
}

/* A refusal because the token is dead (401, or the api's bare
 * 'Unauthorized') folds to the admin gate like UsersView does. A
 * policy refusal — 'Cannot modify admin user' — is a save error with
 * words, and belongs on the form, not behind a gate. */
function isAuthRefusal(e) {
    return e.status === 401 || (e.status === 403 && e.message === 'Unauthorized')
}

async function loadUser() {
    loading.value = true
    loadError.value = ''
    user.value = null
    try {
        const res = await getJson('/cgi-bin/api/users/' + encodeURIComponent(props.id))
        const u = res.user
        if (!u) {
            loadError.value = 'user not found'
            return
        }
        user.value = u
        form.username = u.username || ''
        form.full_name = u.full_name || ''
        form.email = u.email || ''
        form.password = ''
        form.is_admin = !!Number(u.is_admin)
        form.is_active = !!Number(u.is_active)
    } catch (e) {
        const msg = (e && e.message) || 'unknown error'
        if (msg === 'HTTP 401' || msg === 'HTTP 403') {
            // The probe said admin but the api disagrees — expired or
            // demoted token mid-flight. Gate on the api's answer.
            adminOnly.value = true
        } else {
            loadError.value = humanErr(e, 'user not found')
        }
    } finally {
        loading.value = false
    }
}

async function save() {
    if (busy.value || !canWork.value) return
    if (!form.username) {
        saveError.value = 'username is required'
        return
    }
    /* The body mirrors the classic form: profile fields always, the
     * password only when typed. Values go out as typed — no trimming,
     * the api owns the rules. */
    const payload = {
        username: form.username,
        full_name: form.full_name,
        email: form.email,
        is_admin: !!form.is_admin,
        is_active: !!form.is_active
    }
    if (form.password) {
        payload.password = form.password
    } else if (!editing.value) {
        saveError.value = 'password is required for new users'
        return
    }
    busy.value = true
    saveError.value = ''
    try {
        await sendJson(
            editing.value ? 'PUT' : 'POST',
            editing.value
                ? '/cgi-bin/api/users/' + encodeURIComponent(props.id)
                : '/cgi-bin/api/users',
            payload
        )
        form.password = '' // the secret leaves form state on the way out
        /* A create goes back to wherever the form was opened from —
         * usually the listing; an edit keeps landing on the listing
         * as before. */
        if (editing.value) {
            router.push({ name: 'users' })
        } else {
            leaveForm(router, 'users')
        }
    } catch (e) {
        if (isAuthRefusal(e)) {
            adminOnly.value = true
        } else {
            saveError.value = (e && e.message) || 'save failed'
        }
    } finally {
        busy.value = false
    }
}

/* Api stamps are 'YYYY-MM-DD HH:MM:SS'; the classic page renders them
 * at minute resolution, so the same slice keeps the two consoles
 * looking alike. */
function minute(s) {
    return s ? String(s).slice(0, 16) : ''
}

/* Cancel obeys the same exit rule as a create: back to wherever the
 * form was opened from, the listing when there is no history. */
function cancel() {
    leaveForm(router, 'users')
}

onMounted(async () => {
    session.value = await getSession()
    sessionReady.value = true
    if (!session.value.authenticated) {
        // Signed out means the sign-in page, not a dead end. An
        // unknown session state stays put and shows the gate instead
        // of guessing.
        if (session.value.reason === 'signed-out') router.push({ name: 'login' })
        return
    }
    if (session.value.isAdmin && editing.value) loadUser()
})
</script>

<template>
    <header class="bar">
        <div class="bar-title">
            <h1>{{ editing ? 'edit user' : 'new user' }}</h1>
        </div>
        <div class="bar-right">
            <span v-if="loading" class="muted">loading&hellip;</span>
            <router-link class="btn" :to="{ name: 'users' }">back to users</router-link>
        </div>
    </header>

    <!-- Session gate: this form drives admin-only endpoints, so it is
         only ever wired up after the probe says admin — and it folds
         back to the gate if the api itself refuses (401/403), e.g. a
         token that expired between probe and save. -->
    <div v-if="sessionReady && (adminOnly || !session || !session.authenticated || !session.isAdmin)"
         class="panel gate">
        <p>
            <span class="chip chip-danger">admin only</span>
            <span v-if="adminOnly && session && session.authenticated" class="muted">
                the users API refused this request ({{ session.username ? 'signed in as ' + session.username : 'token state unknown' }}) — sign in again if the token expired.
            </span>
            <span v-else-if="session && session.authenticated" class="muted">
                signed in as {{ session.username }} — this form needs an admin token.
            </span>
            <span v-else-if="session && session.reason === 'signed-out'" class="muted">
                off to the sign-in page.
            </span>
            <span v-else-if="session" class="muted" :title="session.error || ''">
                session check failed ({{ session.error || 'unknown' }}) — the form stays hidden rather than guessed.
            </span>
        </p>
        <p>back to the dashboard: <a href="#/">/</a></p>
    </div>

    <div v-if="loadError" class="banner banner-error">user fetch failed — {{ loadError }}</div>

    <template v-if="showForm">
        <section class="panel">
            <p v-if="saveError" class="err-note block" role="alert">save failed — {{ saveError }}</p>
            <form novalidate @submit.prevent="save">
                <div class="field">
                    <label for="f-username">username *</label>
                    <input id="f-username" v-model="form.username" type="text"
                           :readonly="lockedAdmin" autocomplete="off">
                </div>

                <div class="field">
                    <label for="f-password">
                        {{ editing ? 'password — leave blank to keep the current one' : 'password *' }}
                    </label>
                    <div class="pw-row">
                        <input id="f-password" v-model="form.password"
                               :type="showPassword ? 'text' : 'password'"
                               :required="!editing" autocomplete="new-password">
                        <button type="button" class="btn pw-toggle" @click="showPassword = !showPassword">
                            {{ showPassword ? 'hide' : 'show' }}
                        </button>
                    </div>
                    <p class="hint">at least 8 characters with letters and numbers</p>
                </div>

                <div class="field">
                    <label for="f-full_name">full name</label>
                    <input id="f-full_name" v-model="form.full_name" type="text" autocomplete="off">
                </div>

                <div class="field">
                    <label for="f-email">email</label>
                    <input id="f-email" v-model="form.email" type="email" autocomplete="off">
                </div>

                <div class="field">
                    <label class="check">
                        <input id="f-is_admin" v-model="form.is_admin" type="checkbox" :disabled="lockedAdmin">
                        administrator
                    </label>
                    <label class="check">
                        <input id="f-is_active" v-model="form.is_active" type="checkbox" :disabled="lockedAdmin">
                        active
                    </label>
                    <p v-if="lockedAdmin" class="hint">
                        the built-in admin account is protected — its name, role and status cannot change here
                    </p>
                </div>

                <div class="actions">
                    <button type="submit" class="btn" :disabled="busy">
                        {{ busy ? 'saving…' : 'save user' }}
                    </button>
                    <button type="button" class="btn" @click="cancel">cancel</button>
                </div>
            </form>
        </section>

        <!-- Record facts from the detail endpoint, read-only: the same
             panel the classic page shows next to the form. -->
        <section v-if="editing && user" class="panel">
            <h2>account info</h2>
            <div class="facts">
                <div class="fact">
                    <span class="k">created</span>
                    <span class="v">{{ minute(user.created_at) || '-' }}<template v-if="user.created_by"> by {{ user.created_by }}</template></span>
                </div>
                <div class="fact">
                    <span class="k">updated</span>
                    <span class="v">{{ minute(user.updated_at) || 'never' }}<template v-if="user.updated_by"> by {{ user.updated_by }}</template></span>
                </div>
                <div class="fact">
                    <span class="k">last login</span>
                    <span class="v">{{ minute(user.last_login) || 'never' }}</span>
                </div>
                <div v-if="Number(user.failed_attempts) > 0" class="fact">
                    <span class="k">failed attempts</span>
                    <span class="v"><span class="chip chip-warn">{{ user.failed_attempts }}</span></span>
                </div>
                <div v-if="user.locked_until" class="fact">
                    <span class="k">locked until</span>
                    <span class="v"><span class="chip chip-danger">{{ minute(user.locked_until) }}</span></span>
                </div>
            </div>
        </section>
    </template>

</template>

<style scoped>
.field {
    margin-bottom: 12px;
}

.field label {
    color: var(--muted);
    display: block;
    font-size: 11px;
    letter-spacing: .6px;
    margin-bottom: 3px;
    text-transform: uppercase;
}

.field input[type="text"],
.field input[type="email"],
.field input[type="password"] {
    background: var(--bg);
    border: 1px solid var(--panel-edge);
    border-radius: 6px;
    color: var(--text);
    font: inherit;
    max-width: 360px;
    padding: 6px 9px;
    width: 100%;
}

.field input:focus {
    border-color: var(--up);
    outline: none;
}

.field input[readonly] {
    color: var(--muted);
    cursor: not-allowed;
}

.pw-row {
    align-items: center;
    display: flex;
    gap: 6px;
    max-width: 360px;
}

.pw-row input {
    flex: 1;
    min-width: 0;
}

.pw-toggle {
    font-size: 11px;
    padding: 4px 8px;
}

.hint {
    color: var(--muted);
    font-size: 11px;
    margin: 4px 0 0;
}

.check {
    align-items: center;
    color: var(--text);
    cursor: pointer;
    display: inline-flex;
    font-size: 12px;
    gap: 5px;
    margin-right: 16px;
}

.check input:disabled {
    cursor: not-allowed;
}

.actions {
    display: flex;
    gap: 8px;
    margin-top: 14px;
}

.facts {
    margin: 8px 0 0;
}

.fact {
    display: flex;
    gap: 14px;
    line-height: 1.7;
}

.fact .k {
    color: var(--muted);
    font-size: 11px;
    letter-spacing: .6px;
    min-width: 110px;
    padding-top: 2px;
    text-transform: uppercase;
}

.gate {
    max-width: 640px;
    line-height: 1.6;
}

.gate p { margin: 0 0 6px; }
.gate p:last-child { margin-bottom: 0; }
</style>