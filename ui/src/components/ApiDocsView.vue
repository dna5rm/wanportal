<!--
  API view: the SPA-side swagger console. The classic page pulled
  swagger-ui off unpkg — a CDN this app never touches — so the console
  here is swagger-ui-dist, imported and bundled by vite: the only
  network read is the live same-origin /api-docs/openapi.yaml that
  drives it. No parser of our own and no endpoint list in code — the
  yaml is the source of truth, so a new CGI route appears here the
  moment the spec grows it. Try it out is on from the first paint,
  matching the classic console's feel. swagger-ui exposes no destroy(),
  so unmount empties the host node instead — a revisit boots a fresh
  console rather than stacking a second one.

  Beside the raw-spec door, the bar lists the markdown guides that sit
  next to openapi.yaml in /api-docs. Each door is an in-app router-link
  to the /guides/:file route — GuideView fetches the file itself, so
  the classic Parsedown page is out of the loop. The list still comes
  from the /cgi-bin/api/docs glob; when that call fails (route
  missing, Apache hiccup) the bar says so in a muted note rather than
  inventing doors — a dead link to a file that does not exist is worse
  than no link.
-->
<script setup>
import { onBeforeUnmount, onMounted, ref } from 'vue'
import SwaggerUIBundle from 'swagger-ui-dist/swagger-ui-bundle'
import 'swagger-ui-dist/swagger-ui.css'
import { getJson } from '../api'

/* Same-origin spec document, served by Apache next to the classic
 * swagger page. swagger-ui fetches it itself — and retries through its
 * own UI — so this view has no fetch of its own. The guide list is
 * different: that one is a CGI route, fetched through the shared
 * getJson so the auth and envelope rules match every other view. */
const DOC_URL = '/api-docs/openapi.yaml'
const DOCS_URL = '/cgi-bin/api/docs'

const host = ref(null)
const guides = ref([])
const guidesFailed = ref(false)

/* Label rule: the title wins when the glob hands one over, else the
 * filename sheds its .md — the same wording the classic page shows. */
function docLabel(file, title) {
    if (title) return String(title)
    return String(file).replace(/\.md$/i, '')
}

/* The route globs *.md next to openapi.yaml, but the SPA only owns
 * its side of the contract: entries arrive as filenames or {file,name}
 * objects with an optional title. Anything without a usable .md name
 * is skipped — nothing is ever made up to fill the bar. The door is
 * the in-app guide route; the view builds no URLs of its own. */
function docEntry(entry) {
    let file = null
    let title = null
    if (typeof entry === 'string') {
        file = entry
    } else if (entry && typeof entry === 'object') {
        file = entry.file || entry.name || entry.filename
        title = entry.title
    }
    if (!file || typeof file !== 'string') return null
    const base = file.replace(/^.*\//, '')
    if (!/\.md$/i.test(base)) return null
    return {
        file: base,
        label: docLabel(base, title)
    }
}

async function loadGuides() {
    try {
        const json = await getJson(DOCS_URL)
        const list = json && (Array.isArray(json.files) ? json.files : Array.isArray(json.docs) ? json.docs : [])
        guides.value = list.map(docEntry).filter(Boolean)
    } catch {
        /* Missing route or failed call: say so, list nothing. */
        guidesFailed.value = true
    }
}

/* dom_id over domNode keeps the boot config identical to the classic
 * page's; deep linking keeps the operation anchors shareable and
 * tryItOutEnabled opens the request editor without a first click. */
onMounted(() => {
    SwaggerUIBundle({
        url: DOC_URL,
        dom_id: '#swagger-ui',
        deepLinking: true,
        tryItOutEnabled: true
    })
    loadGuides()
})

/* No public destroy to call: emptying the host node is the reset that
 * keeps a second visit from stacking a second console in the panel. */
onBeforeUnmount(() => {
    if (host.value) host.value.innerHTML = ''
})
</script>

<template>
    <header class="bar">
        <div class="bar-right docs-bar">
            <span v-if="guidesFailed" class="muted">api docs unavailable</span>
            <template v-for="(g, i) in guides" :key="g.file">
                <span v-if="i" class="doc-sep" aria-hidden="true">|</span>
                <router-link
                    class="doc-door"
                    :to="{ name: 'guide', params: { file: g.file } }"
                >{{ g.label }}</router-link>
            </template>
        </div>
    </header>

    <section class="panel swagger-panel">
        <div ref="host" id="swagger-ui"></div>
    </section>
</template>

<style scoped>
/* The guide doors read as light text links, not buttons — the raw-spec
 * door keeps its .btn chrome as the bar's last item. */
.docs-bar {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 10px;
    justify-content: flex-end;
}

.doc-door {
    color: var(--muted);
    text-decoration: none;
}

.doc-door:hover {
    color: var(--text);
    text-decoration: underline;
}

.doc-sep {
    color: var(--muted);
    user-select: none;
}

/* base.css styles bare table/th app-wide; the console paints its own
 * tables, so lift that bleed inside the panel only and let
 * swagger-ui's own css rule there. */
.swagger-panel :deep(table) {
    width: auto;
}

.swagger-panel :deep(th) {
    text-transform: none;
    letter-spacing: 0;
}
</style>