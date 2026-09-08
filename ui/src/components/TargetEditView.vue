<!--
  Target create/edit form — the in-app answer to targets_edit.php.
  New posts /cgi-bin/api/target, edit PUTs /cgi-bin/api/target/:id,
  and both writes are admin-only at the api, so the form sits behind
  the same session probe the users listing uses and folds back into
  that gate when the api refuses a save. The fill-up read goes through
  the public GET /cgi-bin/api/targets/:id the detail page already
  uses, so the form fills even before the probe answers.

  Address rules are ported verbatim from the classic form (ipv4,
  ipv6, or hostname — target.pm is the law behind them). The api
  re-checks and 400s anything else, so this side is politeness,
  not authority.
-->
<script setup>
import { computed, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { getJson, postJson, putJson } from '../api'
import { getSession } from '../session'
import { humanErr } from './detailShared'

const props = defineProps({
    id: { type: String, default: '' }
})

const router = useRouter()

/* Edit mode is just "the route carried an id"; new mode is the same
 * form with an empty one. */
const editing = computed(() => !!props.id)

const address = ref('')
const description = ref('')
const isActive = ref(true)      // the classic form starts a new target active
const busy = ref(false)
const loadError = ref(null)
const saveError = ref(null)
const addressProblem = ref('')

/* Session gate, same contract as UsersView: the api's own answer
 * outranks the probe whenever the two disagree. */
const session = ref(null)
const sessionReady = ref(false)
const adminOnly = ref(false)

const canEdit = computed(() =>
    sessionReady.value && !adminOnly.value
    && !!session.value && session.value.authenticated && session.value.isAdmin)

/* The three regexes targets_edit.php ships, kept character-for-character
 * so the two forms can never drift apart. */
const ipv4Re = /^(\d{1,3}\.){3}\d{1,3}$/
const ipv6Re = __IPV6_PHP_PORT__
const hostRe = /^[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?(?:\.[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?)*$/

function addressOk(v) {
    return ipv4Re.test(v) || ipv6Re.test(v) || hostRe.test(v)
}

/* humanErr maps 404s to plain wording; a garbage id gets folded in
 * too because the api answers 400 'Invalid target ID format' for
 * those, which reads as the same thing. */
function loadErrText(e) {
    const msg = humanErr(e, 'target not found')
    return msg === 'HTTP 400' ? 'target not found' : msg
}

async function loadTarget() {
    loadError.value = null
    try {
        const json = await getJson('/cgi-bin/api/targets/' + encodeURIComponent(props.id))
        const t = json.target
        if (!t) {
            loadError.value = 'target payload was empty'
            return
        }
        address.value = t.address || ''
        description.value = t.description || ''
        /* is_active arrives as whatever DBI wraps a tinyint in (1,
         * '1', true) — Number() settles all of them. */
        isActive.value = Number(t.is_active) === 1
    } catch (e) {
        loadError.value = loadErrText(e)
    }
}

onMounted(async () => {
    session.value = await getSession()
    sessionReady.value = true
    /* The fill-up read is public, so it runs regardless of what the
     * probe said; the gate only decides whether the form shows. */
    if (editing.value) await loadTarget()
})

/* Keystrokes only clear an existing complaint — full checking waits
 * for submit, so half-typed addresses never get nagged. */
function onAddressInput() {
    if (addressProblem.value && addressOk(address.value.trim())) {
        addressProblem.value = ''
    }
}

async function submit() {
    if (busy.value) return
    saveError.value = null

    const addr = address.value.trim()
    if (!addr) {
        addressProblem.value = 'address is required'
        return
    }
    if (!addressOk(addr)) {
        addressProblem.value = 'address must be an ipv4, ipv6 address, or hostname'
        return
    }
    addressProblem.value = ''

    busy.value = true
    try {
        const body = {
            address: addr,
            description: description.value.trim(),
            is_active: isActive.value ? 1 : 0
        }
        const reply = editing.value
            ? await putJson('/cgi-bin/api/target/' + encodeURIComponent(props.id), body)
            : await postJson('/cgi-bin/api/target', body)

        /* The api answers with the record's id either way; land on the
         * detail page so the save is visibly real, like the classic
         * redirect to targets.php only one hop more specific. */
        const id = (reply && reply.id) || props.id
        if (id) {
            router.push({ name: 'target', params: { id } })
        } else {
            router.push({ name: 'targets' })
        }
    } catch (e) {
        const msg = (e && e.message) || 'unknown error'
        if (msg === 'HTTP 401' || msg === 'HTTP 403') {
            // Token expired or demoted between probe and save — the
            // api's answer gates, not the stale probe result.
            adminOnly.value = true
        } else if (msg === 'HTTP 400') {
            // The body's message never survives the envelope check,
            // and 400 covers both a bad address and a duplicate one.
            saveError.value = 'the api rejected the values — the address may be invalid or already in use'
        } else {
            saveError.value = msg
        }
    } finally {
        busy.value = false
    }
}
</script>

<template>
    <header class="bar">
        <div class="bar-title">
            <h1>{{ editing ? 'edit target' : 'new target' }}</h1>
            <span class="muted">bundled vue · writes go straight to the json api</span>
        </div>
        <div class="bar-right">
            <span v-if="!sessionReady" class="muted">checking session…</span>
            <router-link class="btn" :to="{ name: 'targets' }">back to targets</router-link>
            <router-link v-if="editing" class="btn" :to="{ name: 'target', params: { id } }">open target</router-link>
        </div>
    </header>

    <!-- Session gate: both writes are admin-only, so the form only
         ever renders after the probe says admin — and it folds back
         into the gate if the api itself refuses (401/403), e.g. a
         token that expired between probe and submit. -->
    <div v-if="sessionReady && !canEdit" class="panel gate">
        <p>
            <span class="chip chip-danger">admin only</span>
            <span v-if="adminOnly && session && session.authenticated" class="muted">
                the api refused the save ({{ session.username ? 'signed in as ' + session.username : 'token state unknown' }}) — sign in again if the token expired.
            </span>
            <span v-else-if="session && session.authenticated" class="muted">
                signed in as {{ session.username }} — targets are edited with an admin token.
            </span>
            <span v-else-if="session && session.reason === 'signed-out'" class="muted">
                this form needs a signed-in admin.
            </span>
            <span v-else class="muted" :title="(session && session.error) || ''">
                session check failed ({{ (session && session.error) || 'unknown' }}) — the form stays hidden rather than guessed.
            </span>
        </p>
        <p>
            back to the targets list: <router-link :to="{ name: 'targets' }">/targets</router-link>
            <template v-if="session && session.reason === 'signed-out'">
                &nbsp;·&nbsp; sign in on the classic console: <a href="/login.php">/login.php</a>
            </template>
        </p>
    </div>

    <div v-if="loadError" class="banner banner-error">
        target fetch failed — {{ loadError }}
    </div>
    <div v-if="saveError" class="banner banner-error">{{ saveError }}</div>

    <section v-if="canEdit" class="panel form-panel">
        <h2>{{ editing ? 'edit target' : 'new target' }}</h2>
        <form novalidate @submit.prevent="submit">
            <label class="field-label" for="address">address *</label>
            <input id="address" v-model="address" class="field-input" type="text"
                   maxlength="255" :disabled="busy" @input="onAddressInput">
            <p class="field-hint muted">ipv4, ipv6 address, or hostname</p>
            <p v-if="addressProblem" class="err-note block" role="alert">{{ addressProblem }}</p>

            <label class="field-label" for="description">description</label>
            <input id="description" v-model="description" class="field-input" type="text"
                   maxlength="255" :disabled="busy">

            <label class="check-row" for="is_active">
                <input id="is_active" v-model="isActive" type="checkbox" :disabled="busy">
                active
            </label>

            <div class="form-actions">
                <button class="btn" type="submit" :disabled="busy">
                    {{ busy ? 'saving…' : (editing ? 'save target' : 'create target') }}
                </button>
                <router-link class="btn" :to="{ name: 'targets' }">cancel</router-link>
            </div>
        </form>
    </section>

    <footer class="muted">
        the classic form still lives at <a href="/targets_edit.php">targets_edit.php</a> —
        both write to the same api
    </footer>
</template>

<style scoped>
/* Form chrome borrowed from the login card's palette; kept local so
 * the shared stylesheet stays dashboard-only. */
.form-panel {
    max-width: 520px;
}

.field-label {
    color: var(--muted);
    display: block;
    font-size: 11px;
    letter-spacing: .6px;
    margin: 12px 0 3px;
    text-transform: uppercase;
}

.field-input {
    background: var(--bg);
    border: 1px solid var(--panel-edge);
    border-radius: 6px;
    color: var(--text);
    font: inherit;
    padding: 6px 9px;
    width: 100%;
}

.field-input:focus {
    border-color: var(--up);
    outline: none;
}

.field-hint {
    font-size: 11.5px;
    margin: 3px 0 0;
}

.check-row {
    cursor: pointer;
    display: inline-block;
    margin-top: 12px;
}

.form-actions {
    display: flex;
    gap: 8px;
    margin-top: 16px;
}

.form-actions .btn:disabled,
.field-input:disabled {
    cursor: default;
    opacity: .6;
}

.gate p { margin: 6px 0; }
</style>