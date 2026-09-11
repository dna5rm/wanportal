<!--
  Agent netping / install page, reached from the agent detail page.
  The page serves the probe script itself now: GET
  /cgi-bin/api/netping-script answers the perl source as a json
  payload, and the pre below carries copy + download buttons so a host
  without docker can drop netping-agent.pl next to its crontab. That
  endpoint is jwt-gated (the api counterpart of the classic dump's
  sign-in rule), so the fetch waits for the session probe and a
  signed-out tab gets a sign-in note instead of the source. The docker
  notes keep the established netping-<name> container naming and leave
  the agent password a placeholder — the bundle never carries it.
-->
<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { getJson } from '../api'
import { getSession } from '../session'
import { humanErr, idFromLocation } from './detailShared'

const props = defineProps({
    id: { type: String, default: '' }
})

const agentId = computed(() => idFromLocation(props.id))

const session = ref(null)
const sessionReady = ref(false)
const agent = ref(null)
const error = ref(null)
const loading = ref(false)

/* The script source plus its own fetch state, kept apart from the
 * agent lookup so one failing says nothing about the other. */
const script = ref(null)
const scriptError = ref(null)
const scriptLoading = ref(false)

/*
 * One lookup, for the agent's name: the header shows it and the
 * container commands below mirror the established netping-<name>
 * naming. The lookup is a public read, so it runs signed-out too;
 * when it fails the steps keep working with the generic container
 * name, and the banner says as much instead of hiding them.
 */
async function fetchAgent() {
    if (!agentId.value) {
        error.value = 'no agent id found in the url'
        return
    }
    error.value = null
    loading.value = true
    try {
        const r = await getJson('/cgi-bin/api/agents/' + encodeURIComponent(agentId.value))
        agent.value = r.agent || null
        if (!agent.value) error.value = 'agent payload was empty'
    } catch (err) {
        agent.value = null
        error.value = humanErr(err, 'agent not found')
    }
    loading.value = false
}

/*
 * The script fetch. The endpoint sits inside the api's jwt group, so
 * this only runs after the session probe reports signed-in — a bare
 * request would just collect a 401. The perl text rides the content
 * field (with the filename for the download); script stays accepted
 * as an alias so the endpoint can rename its field without breaking
 * the page.
 */
async function fetchScript() {
    scriptError.value = null
    scriptLoading.value = true
    try {
        const r = await getJson('/cgi-bin/api/netping-script')
        const text = r.content ?? r.script
        if (text === undefined || text === null || String(text) === '') {
            scriptError.value = 'script payload was empty'
        } else {
            script.value = String(text)
        }
    } catch (err) {
        script.value = null
        scriptError.value = humanErr(err, 'script not served')
    }
    scriptLoading.value = false
}

onMounted(async () => {
    session.value = await getSession()
    sessionReady.value = true
    await fetchAgent()
    if (session.value.authenticated) await fetchScript()
})

/*
 * Note shown instead of the script when the session probe says this
 * tab is not authenticated. A dead api is its own case — the honesty
 * rule says unknown stays unknown rather than reading as "signed out".
 */
const sessionNote = computed(() => {
    if (!sessionReady.value || !session.value) return 'checking sign-in…'
    if (session.value.authenticated) return null
    if (session.value.reason === 'unavailable') {
        return 'session check failed (' + (session.value.error || 'unknown') +
            ') — the script stays hidden rather than guessed'
    }
    return null // signed out; the template renders the sign-in door
})

const signedOut = computed(() =>
    !!(sessionReady.value && session.value && !session.value.authenticated)
    && session.value.reason !== 'unavailable'
)

const copied = ref(false)
let copiedTimer = null

/* Same copy wiring as the credential detail page: flash the button for
 * a second on success, stay quiet where the clipboard api is missing
 * or refuses (hardened webviews) — the text stays readable on screen. */
function copyScript() {
    if (!navigator.clipboard || !navigator.clipboard.writeText) return
    navigator.clipboard.writeText(script.value || '').then(
        () => {
            copied.value = true
            clearTimeout(copiedTimer)
            copiedTimer = setTimeout(() => { copied.value = false }, 1000)
        },
        () => {} // clipboard refused — the value stays readable on screen
    )
}

/* The download builds the file from the same text the pre shows, so
 * what was copied is exactly what lands on disk under the name the
 * cron line below refers to. */
const SCRIPT_FILE = 'netping-agent.pl'

function downloadScript() {
    if (!script.value) return
    const blob = new Blob([script.value], { type: 'text/x-perl' })
    const url = URL.createObjectURL(blob)
    const a = document.createElement('a')
    a.href = url
    a.download = SCRIPT_FILE
    document.body.appendChild(a)
    a.click()
    a.remove()
    URL.revokeObjectURL(url)
}

onBeforeUnmount(() => clearTimeout(copiedTimer))

/* API base for the container/cron env to phone home to. Same host this
 * page was loaded from — the browser-side stand-in for SERVER_NAME,
 * which only the server knows for sure. */
const serverUrl = computed(() =>
    window.location.protocol + '//' + window.location.host + '/cgi-bin/api'
)

/* The container is named after the agent (lowercased), mirroring the
 * naming the docker image flow established, so the ps and logs
 * commands agree across pages; fall back to the plain default when the
 * name is unknown. */
const containerName = computed(() =>
    agent.value && agent.value.name
        ? 'netping-' + String(agent.value.name).toLowerCase()
        : 'netping-agent'
)

/*
 * The run command with SERVER and AGENT_ID filled in. The password is
 * deliberately left a placeholder: the API only hands it to admins and
 * the bundle should never carry it.
 */
const runCommand = computed(() => [
    'docker run -d --name ' + containerName.value + ' --network host --restart unless-stopped \\',
    '    -e SERVER="' + serverUrl.value + '" \\',
    '    -e AGENT_ID="' + (agentId.value || '<AGENT_ID>') + '" \\',
    '    -e PASSWORD="***" \\',
    '    netping:latest'
].join('\n'))

/*
 * Cron line for a host without docker: the same three env vars ride
 * the crontab entry exactly like the container's -e flags, because the
 * perl script reads the same environment. Password stays a placeholder
 * for the same reason as the run command; the script path is wherever
 * the download above was saved.
 */
const cronCommand = computed(() =>
    '* * * * * SERVER="' + serverUrl.value + '" AGENT_ID="' +
    (agentId.value || '<AGENT_ID>') + '" PASSWORD="***" perl netping-agent.pl'
)

const scriptBytes = computed(() =>
    script.value == null ? 0 : new Blob([script.value]).size
)
</script>

<template>
    <header class="bar">
        <div class="bar-title">
            <h1>netping</h1>
            <span v-if="agent" class="muted">{{ agent.name || agent.id }}</span>
        </div>
        <div class="bar-right">
            <span v-if="loading" class="muted">loading…</span>
            <a v-if="agentId" class="btn" :href="'#/agents/' + encodeURIComponent(agentId)">back to agent</a>
        </div>
    </header>

    <div v-if="error" class="banner banner-warn">
        agent lookup: {{ error }}. The steps below are built from the agent id in the
        url and still hold; the container name falls back to the generic one.
    </div>

    <div class="cols">
        <div class="col-side">
            <section class="panel">
                <h2>script</h2>
                <div v-if="script" class="kv">
                    <span class="k">size</span>
                    <span class="v">{{ scriptBytes.toLocaleString() }} bytes</span>
                </div>
                <div v-if="sessionNote" class="muted">{{ sessionNote }}</div>
                <div v-else-if="signedOut">
                    <p class="muted">Sign in to view, copy, and download the perl source.</p>
                    <a class="btn" href="#/login">sign in</a>
                </div>
                <template v-else-if="script">
                    <div class="script-actions">
                        <button class="btn" type="button" @click="copyScript">{{ copied ? 'copied ✓' : 'copy script' }}</button>
                        <button class="btn" type="button" @click="downloadScript">download netping-agent.pl</button>
                    </div>
                </template>
                <p v-else-if="scriptError" class="muted">script fetch: {{ scriptError }}</p>
                <p v-else class="muted">loading script…</p>
            </section>

            <section class="panel">
                <h2>docker image</h2>
                <p>
                    <a href="/assets/netping_latest.tar.gz">netping_latest.tar.gz</a>
                </p>
                <p class="muted small">load</p>
                <pre class="code"><code>gunzip -c netping_latest.tar.gz | docker load</code></pre>
                <p class="muted small">run</p>
                <pre class="code"><code>{{ runCommand }}</code></pre>
                <p class="muted small">verify / logs</p>
                <pre class="code"><code>docker ps | grep {{ containerName }}</code></pre>
                <pre class="code"><code>docker logs {{ containerName }}</code></pre>
                <p class="muted small">PASSWORD placeholder — replace before starting. -e DEBUG=1 for verbose logs.</p>
            </section>

            <section class="panel">
                <h2>cron (no docker)</h2>
                <pre class="code"><code>{{ cronCommand }}</code></pre>
            </section>
        </div>

        <div class="col-main">
            <section class="panel script-panel">
                <h2>netping-agent.pl</h2>
                <div v-if="scriptError" class="banner banner-warn">script fetch: {{ scriptError }}</div>
                <pre v-else-if="script" class="code script-body"><code>{{ script }}</code></pre>
                <p v-else class="muted">loading script…</p>
            </section>
        </div>
    </div>
</template>

<style scoped>
.mono { font-family: ui-monospace, monospace; font-size: 11.5px; }
.small { font-size: 11.5px; }

.cols {
    display: grid;
    grid-template-columns: 300px 1fr;
    gap: 14px;
    align-items: start;
}

@media (max-width: 760px) {
    .cols { grid-template-columns: 1fr; }
}

.kv {
    display: flex;
    justify-content: space-between;
    gap: 10px;
    padding: 3px 0;
    border-bottom: 1px solid var(--panel-edge);
    font-size: 12.5px;
}

.script-actions {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    margin: 10px 0;
}

.code {
    background: var(--bg);
    border: 1px solid var(--panel-edge);
    border-radius: 6px;
    padding: 10px 12px;
    margin: 10px 0;
    overflow-x: auto;
    font: 12px/1.5 ui-monospace, monospace;
}

.script-body {
    min-height: 60vh;
    max-height: calc(100vh - 140px);
    overflow: auto;
}
</style>