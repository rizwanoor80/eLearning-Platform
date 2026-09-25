<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';

const page = usePage();
</script>

<template>
    <p v-if="page.props.paymentTestMode" class="rounded-md border border-dashed p-3 text-sm" data-test="test-mode-banner">
        Test mode — no real card is charged.
        <slot />
    </p>
</template>
