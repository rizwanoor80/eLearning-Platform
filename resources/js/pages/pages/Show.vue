<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import PublicFooter from '@/components/PublicFooter.vue';
import PublicHeader from '@/components/PublicHeader.vue';

defineProps<{
    title: string;
    html: string;
    version: number;
    publishedAt: string | null;
}>();
</script>

<template>
    <Head :title="title" />
    <PublicHeader />

    <main class="mx-auto flex w-full max-w-3xl flex-col gap-4 p-4">
        <h1 class="text-2xl font-semibold">{{ title }}</h1>
        <!-- Server-rendered markdown with raw HTML stripped and unsafe links dropped (App\Support\Markdown). -->
        <div class="prose dark:prose-invert max-w-none text-sm" v-html="html" />
    </main>

    <PublicFooter />
</template>
