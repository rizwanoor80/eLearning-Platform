<script setup lang="ts">
// R186: every onboarding field says whether it is required or optional, in words (not just an asterisk).
defineProps<{
    required?: boolean;
    // A short qualifier, e.g. "a CV or LinkedIn — one is enough".
    note?: string;
}>();
</script>

<template>
    <span class="text-muted-foreground ms-1 text-xs font-normal" :data-field-tag="required ? 'required' : 'optional'">
        ({{ required ? 'Required' : 'Optional' }}<template v-if="note"> — {{ note }}</template>)
    </span>
</template>
