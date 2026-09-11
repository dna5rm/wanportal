<!--
  NavMenu: renders the site-config menu in the top bar, after the
  built-in public pages. A flat item is a router-link when it carries
  `to`, an external door (target=_blank rel=noopener) when it carries
  `href`, and an inert label when it carries neither. An item with
  children renders its label as a hover/click dropdown and recurses
  into NavMenu for the children, so deeper nesting costs no extra
  branch — each level opens its own panel the same way. The panel is
  absolutely positioned, so the bar's flex layout never moves.
-->
<script setup>
import { ref } from 'vue'

defineProps({
    items: { type: Array, default: () => [] }
})

/* One open dropdown per menu instance: hover opens it, leaving
 * closes it, a click toggles it (touch / keyboard). */
const openAt = ref(null)

function open(i) {
    openAt.value = i
}
function close() {
    openAt.value = null
}
function toggle(i) {
    openAt.value = openAt.value === i ? null : i
}
</script>

<template>
    <template v-for="(it, i) in items" :key="it.label || i">
        <div v-if="it.children && it.children.length" class="nav-drop"
             @mouseenter="open(i)" @mouseleave="close()">
            <button type="button" class="nav-link nav-drop-label" @click="toggle(i)">
                {{ it.label }}
                <span class="nav-caret" aria-hidden="true">▾</span>
            </button>
            <div v-if="openAt === i" class="nav-drop-menu">
                <NavMenu :items="it.children" />
            </div>
        </div>
        <router-link v-else-if="it.to" class="nav-link" :to="it.to">{{ it.label }}</router-link>
        <a v-else-if="it.href" class="nav-link" :href="it.href" target="_blank" rel="noopener">{{ it.label }}</a>
        <span v-else class="nav-inert">{{ it.label }}</span>
    </template>
</template>

<style scoped>
/* The dropdown wrapper takes the place of a plain link in the bar's
 * flex row; the panel hangs below the label and never shifts layout. */
.nav-drop {
    position: relative;
    display: inline-flex;
}

.nav-drop-label {
    background: none;
    border: none;
    font: inherit;
    font-size: 13px;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 4px;
}

.nav-caret {
    font-size: 9px;
    color: var(--muted);
}

/* Same panel chrome as the account dropdown, opened left-aligned
 * under the label. Nested levels open to the right of their parent
 * row instead of below it, so recursion needs no extra branch. */
.nav-drop-menu {
    position: absolute;
    top: calc(100% + 4px);
    left: 0;
    z-index: 30;
    min-width: 150px;
    display: flex;
    flex-direction: column;
    align-items: stretch;
    background: var(--panel);
    border: 1px solid var(--panel-edge);
    border-radius: 8px;
    padding: 5px 0;
    box-shadow: var(--shadow);
}

.nav-drop-menu .nav-drop {
    position: relative;
    display: block;
}

.nav-drop-menu :deep(.nav-link) {
    display: block;
    padding: 5px 14px;
    color: var(--text);
    border-bottom: none;
    white-space: nowrap;
    text-align: left;
}

.nav-drop-menu :deep(.nav-link:hover) {
    background: var(--hover);
}

.nav-drop-menu .nav-drop-menu {
    top: 0;
    left: 100%;
}

/* A label-only entry (neither to nor href) still belongs to the
 * operator's menu — render it as inert text, not a dead link. */
.nav-inert {
    color: var(--muted);
    font-size: 13px;
}
</style>