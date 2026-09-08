<!--
  Agents listing, ported from htdocs/agents.php. Same columns and
  status wording; the row title opens the Vue detail route. Creating
  happens in the app now — New opens the agent form — while per-row
  edits still go to the classic console, and there is simply no
  delete button here at all.
-->
<script setup>
import { computed, onMounted, ref } from 'vue'
import { getJson } from '../api'
import { fmtClock } from '../format'
import { activeChipCls, editLink } from './detailShared'

const rows = ref([])
const error = ref(null)
const loadedAt = ref(null)

function isActive(a) {
    return Number(a.is_active) === 1
}

/* PHP renders stamps as Y-m-d H:i and keeps the full value in a tooltip. */
function fmtStamp(s) {
    const m = /^(\d{4}-\d{2}-\d{2})[ T](\d{2}:\d{2})/.exec(s || '')
    return m ? m[1] + ' ' + m[2] : (s || '')
}

/* Status descending, like the classic table's default order: problems
 * on top, ties in API order. */
const sorted = computed(() =>
    rows.value
        .map((a, i) => ({ a, i }))
        .sort((x, y) => (isActive(y.a) ? 0 : 1) - (isActive(x.a) ? 0 : 1) || x.i - y.i)
        .map((x) => x.a)
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
        const json = await getJson('/cgi-bin/api/agents')
        rows.value = json.agents || []
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
            <h1>agents</h1>
            <span class="muted">bundled vue · row edits stay on the classic console</span>
        </div>
        <div class="bar-right">
            <span v-if="loadedAt" class="muted">updated {{ fmtClock(loadedAt) }}</span>
            <button class="btn" type="button" @click="fetchRows">refresh now</button>
            <router-link class="btn" :to="{ name: 'agent-new' }">New Agent</router-link>
        </div>
    </header>

    <div v-if="banner" :class="banner.kind">{{ banner.text }}</div>

    <section class="panel">
        <h2>agents</h2>
        <table>
            <thead>
            <tr>
                <th>Name</th>
                <th>Address</th>
                <th>Description</th>
                <th>Status</th>
                <th>Last Seen</th>
                <th>Actions</th>
            </tr>
            </thead>
            <tbody>
            <tr v-if="!sorted.length">
                <td colspan="6" class="muted">no agents</td>
            </tr>
            <tr v-for="a in sorted" :key="a.id" :class="{ 'row-inactive': !isActive(a) }">
                <td>
                    <router-link :to="{ name: 'agent', params: { id: a.id } }">
                        {{ a.name }}
                    </router-link>
                </td>
                <td>{{ a.address }}</td>
                <td>{{ a.description }}</td>
                <td>
                    <span class="chip" :class="activeChipCls(isActive(a) ? 1 : 0)">
                        {{ isActive(a) ? 'Active' : 'Inactive' }}
                    </span>
                </td>
                <td :title="a.last_seen || ''">
                    {{ a.last_seen ? fmtStamp(a.last_seen) : 'Never' }}
                </td>
                <td>
                    <a class="btn" :href="editLink('agent', a.id)" title="Edit">edit</a>
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