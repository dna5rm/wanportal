<!--
  Search, ported from search.php. It runs the same public /monitors?q=
  sweep the classic page calls through api_get(), so both front ends
  always see the same hits. Read-only by design: the app lists what
  the API returns and links into its own detail routes; the classic
  console still owns everything that changes data.
-->
<script setup>
import { computed, ref } from 'vue'
import { getJson } from '../api'
import { lossClass } from '../format'

const q = ref('')
const rows = ref([])
const error = ref(null)
const loading = ref(false)
const searched = ref(false)   // separates "nothing searched yet" from "no hits"

async function runSearch() {
    // The classic page just redirects home on an empty term; here an
    // empty box simply does nothing.
    const term = q.value.trim()
    if (!term) return
    loading.value = true
    error.value = null
    try {
        const res = await getJson('/cgi-bin/api/monitors?q=' + encodeURIComponent(term))
        rows.value = res.monitors || []
        searched.value = true
    } catch (e) {
        error.value = (e && e.message) || 'search failed'
        rows.value = []
        searched.value = true
    } finally {
        loading.value = false
    }
}

/* is_active on a row is already the effective flag (monitor AND agent
 * AND target), so the count needs no extra math. */
const stats = computed(() => {
    const total = rows.value.length
    const active = rows.value.filter((m) => Number(m.is_active) === 1).length
    return { total, active, inactive: total - active }
})

function isActiveRow(m) {
    return Number(m.is_active) === 1
}

function protocolLabel(m) {
    const proto = String(m.protocol || '').toUpperCase()
    return proto === 'ICMP' ? proto : proto + '/' + (m.port ?? '-')
}
</script>

<template>
    <section class="panel">
        <h2>search</h2>

        <form class="search-form" @submit.prevent="runSearch">
            <input v-model="q" type="search" placeholder="search monitors..."
                   aria-label="Search monitors">
            <button class="btn" type="submit" :disabled="loading">
                {{ loading ? 'searching…' : 'search' }}
            </button>
        </form>

        <div v-if="error" class="err-note block">search failed — {{ error }}</div>

        <p v-if="searched && !error" class="muted">
            {{ stats.total }} results · {{ stats.active }} effectively active ·
            {{ stats.inactive }} effectively inactive
        </p>

        <p v-if="!searched" class="muted search-hint">
            Sweeps monitor descriptions, agent names and addresses, and target
            addresses — the same sweep the classic page runs. The classic form
            is still at <a href="/search.php">/search.php</a>.
        </p>

        <table v-if="searched && !error">
            <thead>
            <tr>
                <th>monitor</th>
                <th>agent</th>
                <th>target</th>
                <th>protocol</th>
                <th class="num">median</th>
                <th class="num">loss</th>
                <th>last update</th>
            </tr>
            </thead>
            <tbody>
            <tr v-if="!rows.length">
                <td colspan="7" class="muted">no results found</td>
            </tr>
            <!-- Detail cells ride the SPA routes; a cell with no id to
                 aim at stays plain text instead of a dead link. -->
            <tr v-for="m in rows" :key="m.id" :class="{ dim: !isActiveRow(m) }">
                <td>
                    <router-link v-if="m.id" :to="{ name: 'monitor', params: { id: m.id } }" :title="m.id">
                        {{ m.description || m.id }}
                    </router-link>
                    <template v-else>{{ m.description || '-' }}</template>
                </td>
                <td>
                    <router-link v-if="m.agent_id" :to="{ name: 'agent', params: { id: m.agent_id } }">
                        {{ m.agent_name || '-' }}
                    </router-link>
                    <template v-else>{{ m.agent_name || '-' }}</template>
                </td>
                <td>
                    <router-link v-if="m.target_id" :to="{ name: 'target', params: { id: m.target_id } }">
                        {{ m.target_address || '-' }}
                    </router-link>
                    <template v-else>{{ m.target_address || '-' }}</template>
                </td>
                <td><span :title="'DSCP: ' + (m.dscp ?? '-')">{{ protocolLabel(m) }}</span></td>
                <td class="num">{{ m.current_median }} ms</td>
                <td class="num"><span class="chip" :class="lossClass(m.current_loss)">{{
                    (Number(m.current_loss) || 0).toFixed(1) }}%</span></td>
                <td :title="'last down: ' + (m.last_down || '-')">{{ m.last_update || '-' }}</td>
            </tr>
            </tbody>
        </table>
    </section>
</template>

<style scoped>
.search-form {
    display: flex;
    gap: 8px;
    margin: 4px 0 12px;
    max-width: 420px;
}

.search-form input {
    flex: 1;
    background: var(--bg);
    color: var(--text);
    border: 1px solid var(--panel-edge);
    border-radius: 6px;
    padding: 4px 8px;
    font-size: 12.5px;
}

/* Effectively inactive rows keep their place but read as greyed out. */
tr.dim td {
    color: var(--muted);
}
</style>