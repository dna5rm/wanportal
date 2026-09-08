<!--
  Stand-in for a page that has not been migrated yet. Says so plainly
  and hands the visitor back to the classic console page that still
  does the job. The optional note lets a wrapper explain why a page
  is expected to stay classic (credentials, say) rather than merely
  not ported yet.
-->
<script setup>
defineProps({
    name: { type: String, required: true },
    legacy: { type: String, required: true },
    note: { type: String, default: null }
})
</script>

<template>
    <section class="panel">
        <h2>{{ name }}</h2>
        <div class="placeholder-body">
            <p>Not migrated yet — this page still lives on the classic console.</p>
            <p>
                Until this page is ported, keep using the classic console:
                <a :href="legacy">{{ legacy }}</a>
            </p>
            <p v-if="note">{{ note }}</p>
        </div>
    </section>
</template>