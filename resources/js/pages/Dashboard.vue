<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import PlaceholderPattern from '@/components/PlaceholderPattern.vue';
import { dashboard } from '@/routes';

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Dashboard',
                href: dashboard(),
            },
        ],
    },
});
</script>

<template>
    <Head title="Dashboard" />

    <div
        class="flex h-full flex-1 flex-col gap-4 overflow-x-auto rounded-xl p-4"
    >
        <div class="flex gap-6">
            <Link href="/learners" class="text-sm underline underline-offset-4">
                Manage your learners
            </Link>
            <Link href="/tutors" class="text-sm underline underline-offset-4">
                Find a tutor
            </Link>
            <Link
                v-if="$page.props.features.match_requests"
                href="/match-requests"
                class="text-sm underline underline-offset-4"
            >
                Request a match
            </Link>
        </div>
        <div class="grid auto-rows-min gap-4 md:grid-cols-3">
            <div
                class="border-sidebar-border/70 dark:border-sidebar-border relative aspect-video overflow-hidden rounded-xl border"
            >
                <PlaceholderPattern />
            </div>
            <div
                class="border-sidebar-border/70 dark:border-sidebar-border relative aspect-video overflow-hidden rounded-xl border"
            >
                <PlaceholderPattern />
            </div>
            <div
                class="border-sidebar-border/70 dark:border-sidebar-border relative aspect-video overflow-hidden rounded-xl border"
            >
                <PlaceholderPattern />
            </div>
        </div>
        <div
            class="border-sidebar-border/70 dark:border-sidebar-border relative min-h-[100vh] flex-1 rounded-xl border md:min-h-min"
        >
            <PlaceholderPattern />
        </div>
    </div>
</template>
