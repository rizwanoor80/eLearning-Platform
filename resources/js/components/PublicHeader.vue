<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

const page = usePage();
const siteName = computed(() => (page.props.name as string | undefined) ?? '');
const user = computed(() => page.props.auth.user);
</script>

<template>
    <header class="mx-auto flex w-full max-w-5xl items-center justify-between gap-4 p-4">
        <Link href="/" class="font-semibold">{{ siteName }}</Link>
        <nav class="flex items-center gap-4 text-sm">
            <Link href="/tutors" class="underline-offset-4 hover:underline">Find a tutor</Link>
            <Link v-if="user" href="/dashboard" class="underline-offset-4 hover:underline">Dashboard</Link>
            <template v-else>
                <Link href="/login" class="underline-offset-4 hover:underline">Log in</Link>
                <Link href="/register" class="underline-offset-4 hover:underline">Register</Link>
            </template>
        </nav>
    </header>
</template>
