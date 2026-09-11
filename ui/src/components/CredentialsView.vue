<!--
  Credentials listing, ported from htdocs/credentials.php. The whole
  vault sits behind the JWT wall — no public endpoint serves it — so
  the page probes the session first and a signed-out visitor is walked
  to the in-app /login before the table ever loads. The list api keeps
  passwords out of its responses by design, so this page never has a
  secret to protect: the columns are identity and metadata only, the
  same set the classic page shows.

  Filters split the way the classic page splits them: type and site
  narrow the already-loaded rows client-side, while active/inactive is
  the one filter the api answers (is_active only takes 0 or 1, and
  there is no combined listing), so that one refetches. All three
  persist like the trio listings' text filter (listingFilter.js, key
  'wanportal-filter-credentials'): re-read on mount and saved as the
  user changes them — site typing debounced, the selects at once —
  until the clear button wipes the boxes and the key. View and edit
  moved into the app, and admins get a delete door beside edit
  (confirm first, then the soft-delete aware api path, then a
  refetch) — the classic console no longer owns that step.
-->
<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import { delJson, getJson } from '../api'
import { getSession } from '../session'
import { fmtClock } from '../format'
import { humanErr } from './detailShared'
import { clearFilter, loadFilter, saveFilter } from '../listingFilter'

const router = useRouter()

const session = ref(null)        // session probe result
const sessionReady = ref(false)  // probe done (success or failure)
const rows = ref([])
const error = ref(null)
const loading = ref(false)
const loadedAt = ref(null)
const redirected = ref(false)    // signed-out walk to /login in flight

/* Type + site narrow what is already on the page; active/inactive is
 * answered by the api, so picking it refetches. All three ride the
 * listingFilter key 'wanportal-filter-credentials' and are seeded
 * from it — a stored type outside the select's values reads as the
 * all-types default and a non-'0' active reads as '1', so junk
 * storage cannot blank the listing by accident. */
const FILTER_PAGE = 'credentials'
const CRED_TYPES = ['', 'ACCOUNT', 'CERTIFICATE', 'API', 'PSK', 'CODE']
const savedFilter = loadFilter(FILTER_PAGE)
const typeFilter = ref(CRED_TYPES.includes(savedFilter.typeFilter) ? savedFilter.typeFilter : '')
const siteFilter = ref(typeof savedFilter.siteFilter === 'string' ? savedFilter.siteFilter : '')
const activeFilter = ref(savedFilter.activeFilter === '0' ? '0' : '1')

/* Persist all three as the user changes them: site typing debounces
 * (500ms, same cadence the users listing gives its text filter), the
 * selects save at once and supersede a pending site save. The clear
 * button swallows the one watch tick its resets trigger, so the key
 * it just removed is not rewritten with the defaults. */
let saveTimer = null
let swallowSave = false
watch(
    () => ({
        typeFilter: typeFilter.value,
        siteFilter: siteFilter.value,
        activeFilter: activeFilter.value
    }),
    (val, old) => {
        if (swallowSave) { swallowSave = false; return }
        clearTimeout(saveTimer)
        if (val.siteFilter !== old.siteFilter) {
            saveTimer = setTimeout(() => saveCredsFilter(), 500)
        } else {
            saveCredsFilter()
        }
    }
)

function saveCredsFilter() {
    saveTimer = null
    saveFilter(FILTER_PAGE, {
        typeFilter: typeFilter.value,
        siteFilter: siteFilter.value,
        activeFilter: activeFilter.value
    })
}

let requestSeq = 0               // guards against out-of-order responses

/* Per-type chips: the classic page's badge palette (account blue,
 * certificate green, api cyan, psk amber, code gray) translated into
 * this app's chip vocabulary. Unknown types fall back to the gray. */
const TYPE_CHIPS = ['ACCOUNT', 'CERTIFICATE', 'API', 'PSK']
function typeCls(t) {
    return 'chip ' + (TYPE_CHIPS.includes(t) ? 'type-' + t : 'type-CODE')
}

/* PHP renders stamps as Y-m-d H:i and keeps the full value in a
 * tooltip; the owner of the last change rides the tooltip too. */
function fmtStamp(s) {
    const m = /^(\d{4}-\d{2}-\d{2})[ T](\d{2}:\d{2})/.exec(s || '')
    return m ? m[1] + ' ' + m[2] : (s || '')
}

/* A signed-out visitor goes to the in-app login — both doors agree on
 * this: the probe (a 401 while a token is stored means it expired)
 * and a listing that answers 401 mid-flight for the same reason. */
function toLogin() {
    redirected.value = true
    router.replace({ name: 'login' })
}

/* Gate copy covers only the honest unknowns: a signed-out visitor is
 * already on the way to /login, so what is left here is a dead probe. */
const gateNote = computed(() => {
    if (!sessionReady.value || !session.value) return null
    if (session.value.authenticated) return null
    if (session.value.reason === 'unavailable') {
        return 'session check failed (' + (session.value.error || 'unknown') + ') — the vault stays hidden rather than guessed'
    }
    return null
})

async function fetchRows() {
    const seq = ++requestSeq
    loading.value = true
    error.value = null
    try {
        const json = await getJson('/cgi-bin/api/credentials?is_active=' + encodeURIComponent(activeFilter.value))
        if (seq !== requestSeq) return
        rows.value = json.credentials || []
        loadedAt.value = Date.now()
    } catch (e) {
        if (seq !== requestSeq) return
        const msg = (e && e.message) || 'unknown error'
        if (msg === 'HTTP 401') {
            // Token died between probe and fetch; api.js already
            // dropped it, so walk to the sign-in like the probe does.
            toLogin()
        } else {
            error.value = msg
        }
    } finally {
        if (seq === requestSeq) loading.value = false
    }
}

function refetch() {
    if (session.value && session.value.authenticated) fetchRows()
}

/* The clear button only renders while a filter is off its default. It
 * resets the boxes, removes the stored key, and refetches — but only
 * when the active select actually moved, because that is the one
 * filter the api answers; type and site narrow loaded rows alone. */
function clearCredFilters() {
    clearTimeout(saveTimer)
    saveTimer = null
    const wasActive = activeFilter.value
    swallowSave = true
    typeFilter.value = ''
    siteFilter.value = ''
    activeFilter.value = '1'
    clearFilter(FILTER_PAGE)
    if (wasActive !== '1') refetch()
}

/* Same honesty rule as the other listings: a dead api with nothing to
 * show gets a loud banner, a dead refresh keeps the last good rows. */
const banner = computed(() => {
    if (!rows.value.length && error.value) {
        return { kind: 'banner banner-error', text: 'credentials fetch failed — ' + error.value }
    }
    if (error.value) {
        return { kind: 'banner banner-warn', text: 'refresh failed — showing older data' }
    }
    return null
})

/* Delete door, admin-only: the api soft-deletes an active credential
 * (it just flips is_active) and hard-deletes an already inactive one,
 * so the confirm says exactly that. Confirm first, then the singular
 * api path CredentialDetailView also deletes through, then the listing
 * refetches so the row actually leaves. A failure keeps the rows as
 * they are and says so; delJson throws the bare status ('HTTP 403') on
 * a refusal, and that is what the banner shows — the api's message
 * text never survives it. */
const deleting = ref(false)
const deleteError = ref(null)

async function deleteCredentialRow(c) {
    if (deleting.value || !(session.value && session.value.isAdmin)) return
    if (!window.confirm('Delete credential "' + c.name + '"? The first delete only hides the entry; deleting it again removes it permanently.')) return
    deleting.value = true
    deleteError.value = null
    try {
        await delJson('/cgi-bin/api/credentials/' + encodeURIComponent(c.id))
        await fetchRows()
    } catch (err) {
        deleteError.value = (err && err.message) || 'unknown error'
    } finally {
        deleting.value = false
    }
}

/* Type matches exactly (the select carries canonical values), site is
 * a substring match — the same narrowing the classic table does. */
const visible = computed(() => rows.value.filter((c) => {
    const t = typeFilter.value.toLowerCase()
    if (t && String(c.type || '').toLowerCase() !== t) return false
    const s = siteFilter.value.trim().toLowerCase()
    if (s && String(c.site || '').toLowerCase().includes(s) === false) return false
    return true
}))

onMounted(async () => {
    const s = await getSession()
    session.value = s
    sessionReady.value = true
    if (!s.authenticated) {
        if (s.reason === 'signed-out') toLogin()
        return // dead probe: the gate explains, nothing loads
    }
    fetchRows()
})

onBeforeUnmount(() => {
    // A site-debounce still running at leave time holds the user's
    // last edit — flush it so the filter survives navigation intact.
    if (saveTimer) {
        saveCredsFilter()
    }
})
</script>

<template>
    <header class="bar">
        <div class="bar-title">
            <h1>credentials</h1>
            <span class="muted">vault listing · secrets never ride the list api</span>
        </div>
        <div class="bar-right">
            <span v-if="loading" class="muted">loading&hellip;</span>
            <span v-else-if="loadedAt" class="muted">updated {{ fmtClock(loadedAt) }}</span>
            <button class="btn" type="button" @click="refetch">refresh now</button>
            <!-- The bar renders before the probe lands, so New is gated
                 on the session itself; the table below is already inside
                 the signed-in template. -->
            <router-link v-if="session && session.authenticated" class="btn"
                         :to="{ name: 'credential-new' }">new credential</router-link>
        </div>
    </header>

    <div v-if="redirected" class="panel gate muted">
        signed out — sending you to the sign-in page&hellip;
    </div>

    <div v-if="gateNote" class="panel gate">
        <p>{{ gateNote }}</p>
        <p>back to the dashboard: <a href="#/">/</a></p>
    </div>

    <div v-if="banner" :class="banner.kind">{{ banner.text }}</div>
    <div v-if="deleteError" class="banner banner-warn">
        delete failed: {{ deleteError }} — the credential is still listed.
    </div>

    <template v-if="session && session.authenticated">
        <section class="panel">
            <div class="filters">
                <select v-model="typeFilter" aria-label="type filter">
                    <option value="">all types</option>
                    <option value="ACCOUNT">account</option>
                    <option value="CERTIFICATE">certificate</option>
                    <option value="API">api key</option>
                    <option value="PSK">pre-shared key</option>
                    <option value="CODE">code/license</option>
                </select>
                <input v-model="siteFilter" type="search" placeholder="filter by site..."
                       aria-label="site filter">
                <select v-model="activeFilter" aria-label="active filter" @change="refetch">
                    <option value="1">active</option>
                    <option value="0">inactive</option>
                </select>
                <button v-if="typeFilter || siteFilter || activeFilter !== '1'"
                        class="btn" type="button"
                        aria-label="clear filters" @click="clearCredFilters">clear</button>
            </div>
        </section>

        <section class="panel">
            <h2>credentials <span class="muted">({{ visible.length }})</span></h2>
            <div v-if="error" class="err-note block">listing not trustworthy while the fetch fails</div>
            <table>
                <thead>
                <tr>
                    <th>Name</th>
                    <th>Type</th>
                    <th>Site</th>
                    <th>Username</th>
                    <th>Owner</th>
                    <th>Last Updated</th>
                    <th>Actions</th>
                </tr>
                </thead>
                <tbody>
                <tr v-if="!visible.length && !error">
                    <td colspan="7" class="muted">no credentials match the current filters</td>
                </tr>
                <tr v-for="c in visible" :key="c.id" :class="{ 'row-inactive': !Number(c.is_active) }">
                    <td>
                        <router-link :to="{ name: 'credential', params: { id: c.id } }"
                                     :title="c.comment || ''">
                            {{ c.name }}
                        </router-link>
                    </td>
                    <td><span :class="typeCls(c.type)">{{ c.type }}</span></td>
                    <td>{{ c.site || '-' }}</td>
                    <td>{{ c.username || '-' }}</td>
                    <td>{{ c.owner || '-' }}</td>
                    <td :title="c.updated_by ? 'updated by ' + c.updated_by : ''">
                        {{ c.updated_at ? fmtStamp(c.updated_at) : '' }}
                    </td>
                    <td>
                        <router-link class="btn" :to="{ name: 'credential', params: { id: c.id } }">view</router-link>
                        <router-link class="btn" :to="{ name: 'credential-edit', params: { id: c.id } }">edit</router-link>
                        <button v-if="session && session.isAdmin" class="btn" type="button" :disabled="deleting"
                                @click="deleteCredentialRow(c)">delete</button>
                    </td>
                </tr>
                </tbody>
            </table>
        </section>
    </template>

</template>

<style scoped>
/* Stand-in for Bootstrap's table-secondary: inactive rows read dimmer. */
.row-inactive td { opacity: .55; }

.filters {
    display: flex;
    align-items: center;
    gap: 10px;
    flex-wrap: wrap;
}

.filters input[type="search"],
.filters select {
    background: var(--bg);
    color: var(--text);
    border: 1px solid var(--panel-edge);
    border-radius: 6px;
    padding: 4px 8px;
    font: inherit;
}

.filters input[type="search"] {
    min-width: 200px;
}

/* Per-type chips, dark-mode-safe rgba tints in the base palette's
 * spirit: blue account, green certificate, cyan api, amber psk, gray
 * everything else (the classic page's CODE secondary). */
.type-ACCOUNT { background: rgba(96, 165, 250, .12); border-color: rgba(96, 165, 250, .55); }
.type-CERTIFICATE { background: var(--up-bg); border-color: rgba(76, 195, 138, .45); }
.type-API { background: rgba(34, 211, 238, .1); border-color: rgba(34, 211, 238, .5); }
.type-PSK { background: var(--warn-bg); border-color: var(--warn); }
.type-CODE { color: var(--muted); }

.gate {
    max-width: 640px;
    line-height: 1.6;
}

.gate p { margin: 0 0 6px; }
.gate p:last-child { margin-bottom: 0; }
</style>