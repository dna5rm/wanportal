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

  No second title: the account bar already links this page as API,
  so the bar opens with just the raw-spec door on the right.
-->
<script setup>
import { onBeforeUnmount, onMounted, ref } from 'vue'
import SwaggerUIBundle from 'swagger-ui-dist/swagger-ui-bundle'
import 'swagger-ui-dist/swagger-ui.css'

/* Same-origin spec document, served by Apache next to the classic
 * swagger page. swagger-ui fetches it itself — and retries through its
 * own UI — so this view has no fetch of its own. */
const DOC_URL = '/api-docs/openapi.yaml'

const host = ref(null)

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
})

/* No public destroy to call: emptying the host node is the reset that
 * keeps a second visit from stacking a second console in the panel. */
onBeforeUnmount(() => {
    if (host.value) host.value.innerHTML = ''
})
</script>

<template>
    <header class="bar">
        <div class="bar-right">
            <a class="btn" href="/api-docs/openapi.yaml" target="_blank" rel="noopener" title="raw OpenAPI spec">openapi.yaml</a>
        </div>
    </header>

    <section class="panel swagger-panel">
        <div ref="host" id="swagger-ui"></div>
    </section>
</template>

<style scoped>
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