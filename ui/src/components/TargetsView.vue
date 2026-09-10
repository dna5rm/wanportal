<!--
  Targets listing, ported from htdocs/targets.php. The classic table is
  intentionally shorter than the others — address, description, status,
  and the edit button — so this one stays that way too. Row titles
  open the Vue detail route; editing opens the in-app target form.
-->
<script setup>
import { computed, onMounted, ref } from 'vue'
import { getJson } from '../api'
import { getSession } from '../session'
import { fmtClock } from '../format'
import { activeChipCls } from './detailShared'

const rows = ref([])
const error = ref(null)
const loadedAt = ref(null)
const session = ref(null)

/* Write doors need the SPA session: the classic console enforces its
 * login server-side, but this listing should not even offer New/Edit
 * to a signed-out visitor. Reads stay public, so the data loads for
 * everyone; only the buttons wait for the probe. */
const canEdit = computed(() => !!(session.value && session.value.authenticated))

function isActive(t) {
    return Number(t.is_active) === 1
}

/* Status descending, like the classic table's default order: problems
 * on top, ties in API order. */
const sorted = computed(() =>
    rows.value
        .map((t, i) => ({ t, i }))
        .sort((x, y) => (isActive(y.t) ? 0 : 1) - (isActive(x.t) ? 0 : 1) || x.i - y.i)
        .map((x) => x.t)
)

const banner = computed(() => {
    if (!rows.value.length && error.value) {
        return { kind: 'banner banner-error', text: 'api unreachable — ' + error.value }
    }
    if (error.value) {
        return { kind: 'banner banner-warn', text: 'refresh failed — showing older data' }
    }
    return null
})

async function fetchRows() {
    error.value = null
    try {
        const json = await getJson('/cgi-bin/api/targets')
        rows.value = json.targets || []
        loadedAt.value = Date.now()
    } catch (e) {
        error.value = (e && e.message) || 'unknown error'
    }
}

onMounted(async () => {
    session.value = await getSession()
    await fetchRows()
})
</script>

<template>
    <header class="bar">
        <div class="bar-title">
            <h1>targets</h1>
        </div>
        <div class="bar-right">
            <span v-if="loadedAt" class="muted">updated {{ fmtClock(loadedAt) }}</span>
            <button class="btn" type="button" @click="fetchRows">refresh now</button>
            <router-link v-if="canEdit" class="btn" :to="{ name: 'target-new' }">New Target</router-link>
        </div>
    </header>

    <div v-if="banner" :class="banner.kind">{{ banner.text }}</div>

    <section class="panel">
        <h2>targets</h2>
        <table>
            <thead>
            <tr>
                <th>Address</th>
                <th>Description</th>
                <th>Status</th>
                <th>Actions</th>
            </tr>
            </thead>
            <tbody>
            <tr v-if="!sorted.length">
                <td colspan="4" class="muted">no targets</td>
            </tr>
            <tr v-for="t in sorted" :key="t.id" :class="{ 'row-inactive': !isActive(t) }">
                <td>
                    <router-link :to="{ name: 'target', params: { id: t.id } }">
                        {{ t.address }}
                    </router-link>
                </td>
                <td>{{ t.description }}</td>
                <td>
                    <span class="chip" :class="activeChipCls(isActive(t) ? 1 : 0)">
                        {{ isActive(t) ? 'Active' : 'Inactive' }}
                    </span>
                </td>
                <td>
                    <router-link v-if="canEdit" class="btn" :to="{ name: 'target-edit', params: { id: t.id } }" title="Edit">edit</router-link>
                </td>
            </tr>
            </tbody>
        </table>
    </section>

</template>

<style scoped>
/* Stand-in for Bootstrap's table-secondary: inactive rows read dimmer. */
.row-inactive td { color: var(--muted); }
</style>