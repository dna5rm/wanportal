<!--
  Targets listing, ported from htdocs/targets.php. The classic table is
  intentionally shorter than the others — address, description, status,
  and the edit hand-off — so this one stays that way too. Row titles
  open the Vue detail route; editing goes back to the classic form.
-->
<script setup>
import { computed, onMounted, ref } from 'vue'
import { getJson } from '../api'
import { fmtClock } from '../format'
import { activeChipCls, editLink } from './detailShared'

const rows = ref([])
const error = ref(null)
const loadedAt = ref(null)

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

onMounted(fetchRows)
</script>

<template>
    <header class="bar">
        <div class="bar-title">
            <h1>targets</h1>
            <span class="muted">bundled vue · edits stay on the classic console</span>
        </div>
        <div class="bar-right">
            <span v-if="loadedAt" class="muted">updated {{ fmtClock(loadedAt) }}</span>
            <button class="btn" type="button" @click="fetchRows">refresh now</button>
            <router-link class="btn" :to="{ name: 'target-new' }">New Target</router-link>
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
                    <a class="btn" :href="editLink('target', t.id)" title="Edit">edit</a>
                </td>
            </tr>
            </tbody>
        </table>
    </section>

    <footer class="muted">
        vue listing; deletes and edits live on the classic console
    </footer>
</template>

<style scoped>
/* Stand-in for Bootstrap's table-secondary: inactive rows read dimmer. */
.row-inactive td { color: var(--muted); }
</style>