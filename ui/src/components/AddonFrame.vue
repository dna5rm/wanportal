<!--
  AddonFrame: a sidecar page rendered inside the SPA shell. The
  topnav stays the only header — brand, public links, NavMenu, API,
  ThemeToggle, SessionChip — and the iframe fills every pixel below
  it, so a sidecar visit (the classic console, the sites report, the
  vip-api explorer) never leaves the app shell and never opens a new
  tab. src is an absolute path on this origin; embed=1 is appended
  once so sidecar pages that honor the parameter can drop their own
  chrome, and a src that already carries embed is passed through
  untouched.

  The SPA's theme is pushed into the frame: sidecar pages style
  themselves, so on load — and whenever the ThemeToggle flips
  data-theme on <html> — a wanportal-theme postMessage carries the
  active mode into the frame so the sidecar recolors in step.
-->
<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'

const props = defineProps({
    /* Absolute path on this origin: '/nb/', '/nb/reports/sites.php'. */
    src: { type: String, required: true },
    /* Accessible frame title; the sidecar name rides in via the route. */
    title: { type: String, default: 'wanportal addon' }
})

/*
 * embed=1 is the sidecars' bare-render switch. Appended as the query
 * (or a further query on a src that already has one) exactly once —
 * a src carrying embed already is left alone.
 */
const frameSrc = computed(() => {
    if (/[?&]embed=/.test(props.src)) return props.src
    return props.src + (props.src.includes('?') ? '&' : '?') + 'embed=1'
})

const frame = ref(null)

/*
 * The frame speaks the same message protocol the SPA does: light is
 * carried by data-theme="light" on <html>, anything else (absent,
 * junk) is the dark default. targetOrigin is this window's own
 * origin — the src is same-origin by contract, so nothing is
 * broadcast wider than it needs to be.
 */
function postTheme() {
    const win = frame.value?.contentWindow
    if (!win) return
    const theme =
        document.documentElement.getAttribute('data-theme') === 'light'
            ? 'light'
            : 'dark'
    win.postMessage({ type: 'wanportal-theme', theme }, window.location.origin)
}

/*
 * ThemeToggle (and theme.js generally) only ever sets or removes
 * data-theme on <html>, so an attribute-filtered observer on the
 * document element catches every flip. The load listener pushes the
 * mode that is active when the frame document appears, so a frame
 * mounted after a flip (or navigated by the router) lands themed.
 */
let themeObserver = null

onMounted(() => {
    frame.value?.addEventListener('load', postTheme)
    themeObserver = new MutationObserver(postTheme)
    themeObserver.observe(document.documentElement, {
        attributes: true,
        attributeFilter: ['data-theme']
    })
})

onBeforeUnmount(() => {
    themeObserver?.disconnect()
    frame.value?.removeEventListener('load', postTheme)
})
</script>

<template>
    <div class="addon-frame-wrap">
        <iframe ref="frame" class="addon-frame" :src="frameSrc" :title="title"></iframe>
    </div>
</template>

<style scoped>
/*
 * The frame is the whole viewport under the topnav: borderless, full
 * width, no gap, no scroll chrome of its own beyond what the sidecar
 * page needs. The wrap owns the height so the page itself never
 * scrolls: above the frame sit the topnav block (12px + ~25.4px bar
 * line + 10px + 1px border), its 14px margin and the body's 30px
 * bottom padding — 92.4px live — so the wrap subtracts 93px of
 * viewport and clips, and the iframe fills that box at 100%. The old
 * bar-only 56px calc ignored the bottom padding and left the page
 * with a ~36px scrollbar on every addon route.
 */
.addon-frame-wrap {
    height: calc(100vh - 93px);
    overflow: hidden;
}

.addon-frame {
    display: block;
    width: 100%;
    height: 100%;
    border: 0;
}
</style>