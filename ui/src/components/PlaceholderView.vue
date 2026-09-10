<!--
  Stand-in for a page that has not been migrated yet. Says so plainly
  — the app is the UI now, so there is no hand-off link to the classic
  console from here. The optional note lets a wrapper explain what the
  page is waiting on.
-->
<script setup>
defineProps({
    name: { type: String, required: true },
    legacy: { type: String, default: '' },
    note: { type: String, default: null }
})
</script>

<template>
    <section class="panel">
        <h2>{{ name }}</h2>
        <div class="placeholder-body">
            <p>Not migrated yet — this page is not part of the app.</p>
            <p v-if="note">{{ note }}</p>
        </div>
    </section>
</template>