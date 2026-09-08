<!--
  Agent netping / install page, reached from the agent detail page.
  Serving the probe script and the docker-image notes is the classic
  console's job (netping.php): it renders the perl with syntax
  highlighting and fills the docker run command with the agent's
  server, id and password server-side. This page only explains the
  install and hands off to that page, so the script source and the
  agent password never travel through the JS bundle.
-->
<script setup>
import { computed, onMounted, ref } from 'vue'
import { getJson } from '../api'
import { humanErr, idFromLocation } from './detailShared'

const props = defineProps({
    id: { type: String, default: '' }
})

const agentId = computed(() => idFromLocation(props.id))

const agent = ref(null)
const error = ref(null)
const loading = ref(false)

/*
 * One lookup, for the agent's name: the header shows it and the
 * container commands below mirror the classic page's netping-<name>
 * naming. The page is built from the url id either way — when this
 * lookup fails the steps keep working with the generic container
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

onMounted(fetchAgent)

/* The classic page with the docker notes pre-filled for this agent;
 * without an id in the url it still shows the script and the generic
 * command, so the link is safe to render unconditionally. */
const classicUrl = computed(() =>
    '/netping.php' + (agentId.value ? '?id=' + encodeURIComponent(agentId.value) : '')
)

/* API base for the container to phone home to. Same host this page
 * was loaded from — the browser-side stand-in for SERVER_NAME, which
 * the classic page fills in on the server. */
const serverUrl = computed(() =>
    window.location.protocol + '//' + window.location.host + '/cgi-bin/api'
)

/* The classic page names the container after the agent (lowercased);
 * mirror that so the ps and logs commands agree across both pages,
 * falling back to the plain default when the name is unknown. */
const containerName = computed(() =>
    agent.value && agent.value.name
        ? 'netping-' + String(agent.value.name).toLowerCase()
        : 'netping-agent'
)

/*
 * The run command with SERVER and AGENT_ID filled in. The password is
 * deliberately left a placeholder: the API only hands it to admins and
 * the bundle should never carry it. The classic netping.php page
 * prints this same command with the real value already in place, so
 * the hand-off link stays the honest path to a copy-paste command.
 */
const runCommand = computed(() => [
    'docker run -d --name ' + containerName.value + ' --network host --restart unless-stopped \\',
    '    -e SERVER="' + serverUrl.value + '" \\',
    '    -e AGENT_ID="' + (agentId.value || '<AGENT_ID>') + '" \\',
    '    -e PASSWORD="<PASSWORD>" \\',
    '    netping:latest'
].join('\n'))
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
            <a class="btn" :href="classicUrl">netping.php</a>
        </div>
    </header>

    <div v-if="error" class="banner banner-warn">
        agent lookup: {{ error }}. The steps below are built from the agent id in the
        url and still hold; the container name falls back to the generic one and the
        ready-to-paste command is on <a :href="classicUrl">netping.php</a>.
    </div>

    <section class="panel">
        <h2>agent script</h2>
        <p>
            The probe script (<span class="mono">netping-agent.pl</span>) is not bundled
            into this app — the classic console serves it with syntax highlighting and
            its docker notes, pre-filled for this agent.
        </p>
        <p><a class="btn" :href="classicUrl">view / copy the script on netping.php</a></p>
        <p class="muted small">
            Fetching it with a curl user agent dumps the raw source as text/plain; that
            path needs a signed-in session — anonymous curl gets a 401.
        </p>
    </section>

    <section class="panel">
        <h2>docker image</h2>
        <p>
            The agent ships as a self-contained cron container (alpine + perl, one probe
            cycle a minute), built by <span class="mono">./build_agent.sh</span> and
            published as
            <a href="/assets/netping_latest.tar.gz">/assets/netping_latest.tar.gz</a>.
            Size and md5 of the current build are shown on the
            <a :href="classicUrl">classic page</a>.
        </p>
        <pre class="code"><code>gunzip -c netping_latest.tar.gz | docker load</code></pre>
        <p class="muted small">
            The tarball carries the architecture of the host that built it (an arm64
            image on the Pi). For an amd64 host, build on that host from a repo checkout
            with <span class="mono">./build_agent.sh</span> rather than loading this file.
        </p>
    </section>

    <section class="panel">
        <h2>run the agent</h2>
        <p>
            Run the container on the host that will do the probing, with
            <span class="mono">--network host</span> so the numbers reflect what that
            host itself sees.
        </p>
        <pre class="code"><code>{{ runCommand }}</code></pre>
        <p class="muted small">
            Replace the password placeholder before starting — or copy the command with
            it already filled in from <a :href="classicUrl">netping.php</a>. Add
            <span class="mono">-e DEBUG=1</span> for verbose logging.
        </p>
        <pre class="code"><code>docker ps | grep {{ containerName }}</code></pre>
        <pre class="code"><code>docker logs {{ containerName }}</code></pre>
    </section>

    <footer class="muted">
        vue netping page; the script itself stays at
        <a :href="classicUrl">netping.php</a>
    </footer>
</template>

<style scoped>
.mono { font-family: ui-monospace, monospace; font-size: 11.5px; }
.small { font-size: 11.5px; }

.code {
    background: var(--bg);
    border: 1px solid var(--panel-edge);
    border-radius: 6px;
    padding: 10px 12px;
    margin: 10px 0;
    overflow-x: auto;
    font: 12px/1.5 ui-monospace, monospace;
}
</style>