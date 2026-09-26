<script setup lang="ts">
import BrandLockup from '@/components/BrandLockup.vue';
</script>

<template>
    <BrandLockup
        variant="wordmark"
        class="group-data-[collapsible=icon]:hidden"
    />
    <BrandLockup
        variant="symbol"
        class="hidden group-data-[collapsible=icon]:block"
    />
</template>
