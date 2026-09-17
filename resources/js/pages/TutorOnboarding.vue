<script setup lang="ts">
import { Head } from '@inertiajs/vue3';

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Onboarding',
                href: '/tutor/onboarding',
            },
        ],
    },
});
</script>

<template>
    <Head title="Tutor onboarding" />

    <div class="flex h-full flex-1 flex-col gap-4 rounded-xl p-4">
        <h1 class="text-xl font-semibold">Onboarding</h1>
        <p class="text-muted-foreground">
            The tutor onboarding wizard is not built yet — this is a
            placeholder landing page.
        </p>
    </div>
</template>
