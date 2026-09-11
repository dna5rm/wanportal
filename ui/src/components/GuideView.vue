<!--
  GuideView: the SPA-side reader for the markdown guides that sit next
  to openapi.yaml in /api-docs. The classic page rendered these files
  through Parsedown (index.php?file=...); here the SPA is native — the
  file is fetched straight from Apache as text (a plain static GET of
  the same document, no PHP renderer in the loop) and parsed in the
  browser with the bundled marked, imported like any other dependency
  rather than pulled off a CDN.

  The door only opens for bare *.md file names: anything with a path
  separator, a backslash, a NUL or a double dot is rejected before a
  single byte goes on the wire, so the file prop can never be walked
  out of the docroot. The files themselves are same-origin and
  committed next to the spec — trusted content — and marked's defaults
  keep inline and fenced code escaped; raw inline HTML passes through,
  which is the deliberate trade for documents the repo authors, not a
  sanitizer bypass to guard against strangers.

  Honesty rules match the rest of the app: a missing or failed fetch
  shows a banner and no body — never a fabricated document — and the
  bar stays slim with no h1 of its own, because every guide carries
  its title as the markdown's first heading.
-->
<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { marked } from 'marked'

const props = defineProps({
    file: { type: String, default: '' }
})

/* Apache serves the raw file, so the api's json envelope rules do not
 * apply and this view owns its fetch instead of borrowing getJson:
 * no cookies ride along (credentials omit), and a stalled apache is
 * cut loose after ten seconds like every other same-origin read. */
const GUIDE_BASE = '/api-docs/'
const FETCH_MS = 10000

/* Name guard: a bare *.md file name or nothing. The separator ban is
 * the traversal kill — '..' and the .md tail are the belt's second
 * strap, kept explicit so the contract reads the same as the docs. */
function guideName(raw) {
    if (typeof raw !== 'string' || raw === '') return null
    if (/[/\\\0]/.test(raw)) return null
    if (raw.includes('..')) return null
    if (!/\.md$/i.test(raw)) return null
    return raw
}

const loading = ref(false)
const failed = ref(null)
const empty = ref(false)
const html = ref('')

/* Supersede counter: only the newest load may paint results or errors,
 * so a quick prop change (route reuse) never lets a stale read win. */
let seq = 0
let ctrl = null

async function load() {
    const mine = ++seq
    if (ctrl) ctrl.abort() // a superseded read stops costing a socket
    const name = guideName(props.file)
    if (!name) {
        failed.value = 'guide name rejected: a bare *.md file name is required'
        empty.value = false
        html.value = ''
        loading.value = false
        return
    }
    const myCtrl = new AbortController()
    ctrl = myCtrl
    const killer = setTimeout(() => myCtrl.abort(), FETCH_MS)
    loading.value = true
    failed.value = null
    empty.value = false
    html.value = ''
    try {
        const res = await fetch(GUIDE_BASE + encodeURIComponent(name), {
            method: 'GET',
            credentials: 'omit',
            headers: { Accept: 'text/markdown, text/plain, */*' },
            signal: myCtrl.signal
        })
        if (mine !== seq) return // a newer load took over
        if (!res.ok) {
            failed.value = res.status === 404
                ? 'guide not found: ' + name + ' (HTTP 404)'
                : 'guide request failed (HTTP ' + res.status + ')'
            return
        }
        const text = await res.text()
        if (mine !== seq) return
        if (!String(text).trim()) {
            empty.value = true
            return
        }
        /* marked's defaults, no options: inline and fenced code stay
         * escaped, and the trusted same-origin document renders as the
         * author wrote it. */
        html.value = marked.parse(String(text))
    } catch (err) {
        if (mine !== seq) return
        failed.value = err && err.name === 'AbortError'
            ? 'guide request timed out after 10s'
            : 'guide request failed (' + (err && err.message ? err.message : 'unknown') + ')'
    } finally {
        clearTimeout(killer)
        if (mine === seq) loading.value = false
    }
}

onMounted(load)

/* Route reuse hands the same component a new :file param; reload. */
watch(() => props.file, load)

/* Unmount drops any in-flight read; the seq bump keeps its catch from
 * painting an error into a dead component. */
onBeforeUnmount(() => {
    seq++
    if (ctrl) ctrl.abort()
})

const label = computed(() => props.file || 'guide')
</script>

<template>
    <header class="bar">
        <div class="bar-title">
            <span class="muted">{{ label }}</span>
        </div>
        <div class="bar-right">
            <router-link class="btn" to="/api">back to API</router-link>
        </div>
    </header>

    <div v-if="failed" class="banner banner-warn">
        {{ failed }}. no guide body is shown for a guide that did not load.
    </div>

    <section class="panel guide-panel">
        <div v-if="loading" class="muted">loading guide…</div>
        <div v-else-if="failed" class="muted">no guide body: the request did not succeed.</div>
        <div v-else-if="empty" class="muted">the file answered but carried no content.</div>
        <div v-else class="guide-body" v-html="html"></div>
    </section>
</template>

<style scoped>
/* The markdown is styled back into the app's chrome: code blocks reuse
 * the .code look (bg + panel edge), the type scale stays in the
 * panel's range, and :deep() reaches the v-html content. */
.guide-body {
    font-size: 13px;
    line-height: 1.6;
    color: var(--text);
    overflow-wrap: break-word;
}

.guide-body :deep(h1),
.guide-body :deep(h2),
.guide-body :deep(h3),
.guide-body :deep(h4) {
    color: var(--text);
    margin: 18px 0 8px;
    line-height: 1.3;
}

.guide-body :deep(h1) { font-size: 20px; }
.guide-body :deep(h2) { font-size: 16px; }
.guide-body :deep(h3) { font-size: 14px; }

.guide-body :deep(p) { margin: 8px 0; }

.guide-body :deep(ul),
.guide-body :deep(ol) {
    margin: 8px 0;
    padding-left: 22px;
}

.guide-body :deep(code) {
    font-family: ui-monospace, monospace;
    font-size: 12px;
    background: var(--bg);
    border: 1px solid var(--panel-edge);
    border-radius: 4px;
    padding: 1px 5px;
}

.guide-body :deep(pre) {
    background: var(--bg);
    border: 1px solid var(--panel-edge);
    border-radius: 6px;
    padding: 10px 12px;
    margin: 10px 0;
    overflow-x: auto;
}

.guide-body :deep(pre code) {
    background: none;
    border: 0;
    padding: 0;
    line-height: 1.5;
}

.guide-body :deep(a) {
    color: var(--text);
    text-decoration: underline;
}

.guide-body :deep(table) {
    border-collapse: collapse;
    margin: 10px 0;
    width: auto;
}

.guide-body :deep(th),
.guide-body :deep(td) {
    border: 1px solid var(--panel-edge);
    padding: 4px 10px;
    text-align: left;
}

/* base.css styles bare th app-wide; the markdown's own tables speak
 * with the document's voice, so lift that inside the body. */
.guide-body :deep(th) {
    text-transform: none;
    letter-spacing: 0;
    color: var(--text);
}

.guide-body :deep(blockquote) {
    border-left: 3px solid var(--panel-edge);
    margin: 10px 0;
    padding: 2px 14px;
    color: var(--muted);
}
</style>