<!--
  The light/dark toggle in the top bar's right cluster, between the
  API door and the account chip. It shares the .btn chrome of the
  cluster and the classic console's localStorage key (see theme.js),
  so a choice made here holds on /classic and the other way round.
  Like the classic footer toggle, the button offers the mode it would
  switch to: the sun shows while dark is active (click for light), the
  moon shows while light is active (click for dark).
-->
<script setup>
import { ref } from 'vue'
import { isLight, toggleTheme } from '../theme'

/* The boot restore in main.js ran before the shell mounted, so the
 * attribute is already in its final state at setup time. */
const light = ref(isLight())

function onClick() {
    light.value = toggleTheme()
}
</script>

<template>
    <button type="button" class="btn theme-toggle" aria-label="Toggle light/dark theme"
            :title="light ? 'Switch to dark theme' : 'Switch to light theme'"
            @click="onClick">
        <span v-if="light">&#9789; Dark</span>
        <span v-else>&#9728; Light</span>
    </button>
</template>