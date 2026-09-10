<!--
  Latency report, ported from latency.php. The classic page pulls
  /monitors and keeps the rows whose numbers blow past their own
  baseline; the API now stamps that verdict on every row as
  latency_flag (computed in public_api.pm with the same rules the PHP
  helper applies), so this view is just a filter and a render. Like
  the classic page, it refreshes itself every five minutes.
-->
<script setup>
import { onBeforeUnmount, onMounted, ref } from 'vue'
import { getJson } from '../api'
import { fmtClock } from '../format'

/* latency.php meta-refreshes every 300s; same rhythm here. */
const REFRESH_SECONDS = 300

const rows = ref([])
const error = ref(null)
const loading = ref(false)
const fetchedAt = ref(null)

let timer = null

async function fetchIssues() {
    loading.value = true
    error.value = null
    try {
        const res = await getJson('/cgi-bin/api/monitors')
        rows.value = (res.monitors || []).filter((m) => Number(m.latency_flag) === 1)
        fetchedAt.value = Date.now()
    } catch (e) {
        error.value = (e && e.message) || 'fetch failed'
    } finally {
        loading.value = false
    }
}

function fmtMs(v) {
    const n = Number(v)
    return isNaN(n) ? '-' : n.toFixed(2) + ' ms'
}

/* Severity tint, same bands the classic page tints by: how far the
 * current median sits over its own threshold. */
function rowClass(m) {
    const current = Number(m.current_median)
    const threshold = Number(m.latency_threshold_ms)
    if (!threshold || threshold <= 0 || isNaN(current)) return ''
    const overPct = ((current - threshold) / threshold) * 100
    if (overPct >= 100) return 'row-danger'
    if (overPct >= 50) return 'row-warn'
    return ''
}

onMounted(() => {
    fetchIssues()
    timer = setInterval(fetchIssues, REFRESH_SECONDS * 1000)
})

onBeforeUnmount(() => {
    if (timer) clearInterval(timer)
})
</script>

<template>
    <header class="bar">
        <div class="bar-title">
            <h1>latency report</h1>
        </div>
        <div class="bar-right">
            <span v-if="fetchedAt" class="muted">updated {{ fmtClock(fetchedAt) }}</span>
            <button class="btn" type="button" :disabled="loading" @click="fetchIssues">
                {{ loading ? 'loading…' : 'refresh now' }}
            </button>
        </div>
    </header>

    <div v-if="error" class="err-note block">
        latency fetch failed — this table is not trustworthy
    </div>

    <section class="panel">
        <h2>monitors over their own baseline</h2>
        <table>
            <thead>
            <tr>
                <th>monitor</th>
                <th>agent</th>
                <th>target</th>
                <th class="num">current</th>
                <th class="num">average</th>
                <th class="num">threshold</th>
            </tr>
            </thead>
            <tbody>
            <tr v-if="!rows.length && !error">
                <td colspan="6" class="muted">no latency issues detected</td>
            </tr>
            <!-- Detail cells ride the SPA routes; a cell with no id to
                 aim at stays plain text instead of a dead link. -->
            <tr v-for="m in rows" :key="m.id" :class="rowClass(m)">
                <td>
                    <router-link v-if="m.id" :to="{ name: 'monitor', params: { id: m.id } }">
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
                <td class="num">{{ fmtMs(m.current_median) }}</td>
                <td class="num">{{ fmtMs(m.avg_median) }}</td>
                <td class="num">{{ fmtMs(m.latency_threshold_ms) }}</td>
            </tr>
            </tbody>
        </table>
    </section>
</template>