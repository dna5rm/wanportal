<!--
  Users listing (admin only), ported from htdocs/users.php.

  Same data source and same filter contract as the classic page:
  GET /cgi-bin/api/users carries q, is_admin and is_active as query
  params and the API filters server-side - no client-side row hiding
  here either. "Show inactive" maps the same way: checked == no
  is_active param (all users), unchecked == is_active=1.

  The checkbox is the shared show-inactive flag (prefs.js) — the
  same key the agent and target detail pages bind, because the
  classic pages kept one session flag across them. That makes the
  SPA default to active-only where the classic users.php GET
  mapping defaulted to all users; a URL ?show_inactive=true
  restores the all-users view for a visit.

  The q + role filters persist like the trio listings' text filter
  (listingFilter.js, key 'wanportal-filter-users'): re-read on
  mount and saved as the user types (debounced) or picks a role,
  until the clear button wipes both the boxes and the key.

  The listing sits behind the login wall, so this view checks the
  session probe first and shows an "admin only" note instead of a
  table for anyone the API will not answer. Create and edit moved
  into the app: New/Edit open the /users/new and /users/:id/edit
  routes, and nothing here POSTs. Each row also carries the admin
  delete door (confirm first, then the singular api path, then a
  refetch) — the api refuses the built-in admin account with a 403,
  and that refusal shows as a banner instead of a fake success.
-->
<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { delJson, getJson } from '../api'
import { getSession } from '../session'
import { resolveShowInactive, setShowInactive } from '../prefs'
import { clearFilter, loadFilter, saveFilter } from '../listingFilter'

const session = ref(null)        // session probe result
const sessionReady = ref(false)  // probe done (success or failure)
const users = ref([])
const error = ref(null)          // users fetch error, shown as a banner
const loading = ref(false)

// Filters mirror htdocs/users.php: free-text q, role select, and the
// inactive checkbox (checked == show all users). The checkbox is the
// shared show-inactive flag (prefs.js) — the same key the agent and
// target detail pages bind, because the classic pages kept one
// session flag across them — so a choice made here follows to the
// agent detail and back. Resolved like wanportal_get_show_inactive():
// URL query wins, then the stored choice, else false (active only) —
// which is why this starts unchecked rather than all-users.
//
// q + role ride the listingFilter key 'wanportal-filter-users' and
// are seeded from it: a stored role other than the select's three
// values reads as the all-users default, so junk storage cannot
// narrow the listing by accident.
const FILTER_PAGE = 'users'
const savedFilter = loadFilter(FILTER_PAGE)
const q = ref(savedFilter.q)
const role = ref(['0', '1'].includes(savedFilter.role) ? savedFilter.role : '')
const showInactive = ref(resolveShowInactive())
watch(showInactive, (value) => setShowInactive(value))

/* Persist q + role as the user changes them: typing debounces to the
 * same 500ms the fetch debounce uses, a role pick saves at once (and
 * supersedes a pending text save — the write snapshots both refs).
 * The clear button swallows the one watch tick its resets trigger,
 * so the key it just removed is not rewritten with the defaults. */
let saveTimer = null
let swallowSave = false
watch(
    () => ({ q: q.value, role: role.value }),
    (val, old) => {
        if (swallowSave) { swallowSave = false; return }
        clearTimeout(saveTimer)
        if (val.q !== old.q) {
            saveTimer = setTimeout(
                () => saveFilter(FILTER_PAGE, { q: q.value, role: role.value }),
                500
            )
        } else {
            saveFilter(FILTER_PAGE, { q: val.q, role: val.role })
        }
    }
)

let searchTimer = null
let requestSeq = 0               // guards against out-of-order responses

const adminOnly = ref(false)     // API reachable but refuses this caller

function resetGates() {
    adminOnly.value = false
}

/* Build the query string exactly like the classic page does: empty
 * values are dropped so the API sees no filter for them. */
function usersUrl() {
    const params = new URLSearchParams()
    const needle = q.value.trim()
    if (needle) params.set('q', needle)
    if (role.value === '1' || role.value === '0') params.set('is_admin', role.value)
    if (!showInactive.value) params.set('is_active', '1')
    const qs = params.toString()
    return '/cgi-bin/api/users' + (qs ? '?' + qs : '')
}

async function loadUsers() {
    const seq = ++requestSeq
    loading.value = true
    error.value = null
    try {
        const res = await getJson(usersUrl())
        if (seq !== requestSeq) return // a newer request already answered
        resetGates()
        users.value = res.users || []
    } catch (e) {
        if (seq !== requestSeq) return
        users.value = []
        const msg = (e && e.message) || 'unknown error'
        if (msg === 'HTTP 401' || msg === 'HTTP 403') {
            // The probe said admin but the listing API disagrees -
            // expired or demoted token mid-flight. Gate the page on
            // the API's answer, not on the stale probe.
            adminOnly.value = true
        } else {
            error.value = msg
        }
    } finally {
        if (seq === requestSeq) loading.value = false
    }
}

function applyNow() {
    clearTimeout(searchTimer)
    searchTimer = null
    if (session.value && session.value.authenticated && session.value.isAdmin) {
        loadUsers()
    }
}

/* Free-text search: debounce like the classic page (500ms), Enter
 * applies immediately. */
function onSearchInput() {
    clearTimeout(searchTimer)
    searchTimer = setTimeout(applyNow, 500)
}

function toggleInactive() {
    applyNow()
}

/* The clear button only renders while q or role is set. It wipes the
 * boxes, removes the stored key, and refetches — the watch tick the
 * resets trigger is swallowed so the removal sticks. */
function clearFilters() {
    clearTimeout(searchTimer)
    clearTimeout(saveTimer)
    saveTimer = null
    swallowSave = true
    q.value = ''
    role.value = ''
    clearFilter(FILTER_PAGE)
    if (session.value && session.value.authenticated && session.value.isAdmin) {
        loadUsers()
    }
}

function roleClass(u) {
    return Number(u.is_admin) ? 'chip chip-danger' : 'chip'
}

/* The delete button keeps the same explicit admin claim as the other
 * listings' delete doors (canAdmin, like TargetsView and friends) —
 * the table template is already admin-gated, so the gate never bites
 * in practice, but it stays named and uniform across the listings. */
const canAdmin = computed(() =>
    !!(session.value && session.value.authenticated && session.value.isAdmin))

/* Delete door: confirm with the username first (the classic users.php
 * wording), then the singular api path, then the listing refetches so
 * the row actually leaves. A failure keeps the rows as they
 * are and says so. The api refuses the built-in admin account with a
 * 403; delJson throws the bare status ('HTTP 403') on a refusal, and
 * that is what the banner shows — the api's message text never
 * survives it. */
const deleting = ref(false)
const deleteError = ref(null)

async function deleteUserRow(u) {
    if (deleting.value) return
    if (!window.confirm('Are you sure you want to delete user "' + u.username + '"?')) return
    deleting.value = true
    deleteError.value = null
    try {
        await delJson('/cgi-bin/api/users/' + encodeURIComponent(u.id))
        await loadUsers()
    } catch (err) {
        deleteError.value = (err && err.message) || 'unknown error'
    } finally {
        deleting.value = false
    }
}

function statusClass(u) {
    return Number(u.is_active) ? 'chip chip-ok' : 'chip chip-warn'
}

/* "2026-09-07 18:00:00" from the API becomes "2026-09-07 18:00",
 * matching the classic page's Y-m-d H:i rendering. */
function lastLogin(s) {
    return s ? String(s).slice(0, 16) : 'never'
}

onMounted(async () => {
    session.value = await getSession()
    sessionReady.value = true
    if (session.value.authenticated && session.value.isAdmin) {
        loadUsers()
    }
})

onBeforeUnmount(() => {
    clearTimeout(searchTimer)
    // A debounce still running at leave time holds the user's last
    // edit — flush it so the filter survives the navigation intact.
    if (saveTimer) {
        clearTimeout(saveTimer)
        saveTimer = null
        saveFilter(FILTER_PAGE, { q: q.value, role: role.value })
    }
})
</script>

<template>
    <header class="bar">
        <div class="bar-title">
            <h1>users</h1>
        </div>
        <div class="bar-right">
            <span v-if="loading" class="muted">loading&hellip;</span>
            <router-link class="btn" :to="{ name: 'user-new' }">new user</router-link>
        </div>
    </header>

    <!-- Session gate: the data behind this page is admin-only, so the
         table is only ever wired up after the probe says admin - and
         it folds back to the gate if the listing API itself refuses
         (401/403), e.g. a token that expired between probe and fetch. -->
    <div v-if="sessionReady && (adminOnly || !session || !session.authenticated || !session.isAdmin)"
         class="panel gate">
        <p>
            <span class="chip chip-danger">admin only</span>
            <span v-if="adminOnly && session && session.authenticated" class="muted">
                the listing API refused this request ({{ session.username ? 'signed in as ' + session.username : 'token state unknown' }}) — sign in again if the token expired.
            </span>
            <span v-else-if="session && session.authenticated" class="muted">
                signed in as {{ session.username }} — this listing needs an admin token.
            </span>
            <span v-else-if="session && session.reason === 'signed-out'" class="muted">
                this listing needs a signed-in admin.
            </span>
            <span v-else-if="session" class="muted" :title="session.error || ''">
                session check failed ({{ session.error || 'unknown' }}) — the listing stays hidden rather than guessed.
            </span>
        </p>
        <p>
            back to the dashboard: <a href="#/">/</a>
        </p>
    </div>

    <div v-if="error" class="banner banner-error">users fetch failed — {{ error }}</div>

    <!-- Delete refusals (the built-in admin account's 403 among them)
         keep the rows and say so, exactly like the targets listing. -->
    <div v-if="deleteError" class="banner banner-warn">
        delete failed: {{ deleteError }} — the user is still listed.
    </div>

    <template v-if="session && session.authenticated && session.isAdmin && !adminOnly">
        <section class="panel">
            <div class="filters">
                <input v-model="q" type="search" placeholder="search users..."
                       aria-label="search users" @input="onSearchInput"
                       @keydown.enter.prevent="applyNow">
                <select v-model="role" aria-label="role filter" @change="applyNow">
                    <option value="">all users</option>
                    <option value="1">admins only</option>
                    <option value="0">non-admins only</option>
                </select>
                <button v-if="q || role" class="btn" type="button"
                        aria-label="clear filters" @click="clearFilters">clear</button>
                <label class="check">
                    <input v-model="showInactive" type="checkbox" @change="toggleInactive">
                    show inactive
                </label>
            </div>
        </section>

        <section class="panel">
            <h2>users <span class="muted">({{ users.length }})</span></h2>
            <div v-if="error" class="err-note block">listing not trustworthy while the fetch fails</div>
            <table>
                <thead>
                <tr>
                    <th>username</th>
                    <th>full name</th>
                    <th>email</th>
                    <th>role</th>
                    <th>status</th>
                    <th class="num">last login</th>
                    <th>actions</th>
                </tr>
                </thead>
                <tbody>
                <tr v-if="!users.length && !error">
                    <td colspan="7" class="muted">no users match the current filters</td>
                </tr>
                <tr v-for="u in users" :key="u.id" :class="{ 'row-inactive': !Number(u.is_active) }">
                    <td>{{ u.username }}</td>
                    <td>{{ u.full_name || '-' }}</td>
                    <td>
                        <a v-if="u.email" :href="'mailto:' + u.email">{{ u.email }}</a>
                        <span v-else class="muted">-</span>
                    </td>
                    <td><span :class="roleClass(u)">{{ Number(u.is_admin) ? 'admin' : 'user' }}</span></td>
                    <td><span :class="statusClass(u)">{{ Number(u.is_active) ? 'active' : 'inactive' }}</span></td>
                    <td class="num muted">{{ lastLogin(u.last_login) }}</td>
                    <td>
                        <!-- Edit opens the in-app editor for this row;
                             delete mirrors the other listings' door. -->
                        <router-link class="btn" :to="{ name: 'user-edit', params: { id: u.id } }">edit</router-link>
                        <button v-if="canAdmin" class="btn" type="button" :disabled="deleting"
                                @click="deleteUserRow(u)">delete</button>
                    </td>
                </tr>
                </tbody>
            </table>
        </section>
    </template>

</template>

<style scoped>
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
    min-width: 220px;
}

.check {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    color: var(--muted);
    font-size: 12px;
    cursor: pointer;
}

.gate {
    max-width: 640px;
    line-height: 1.6;
}

.gate p { margin: 0 0 6px; }
.gate p:last-child { margin-bottom: 0; }

.row-inactive td { opacity: .55; }
</style>