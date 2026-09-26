<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import BrandLockup from '@/components/BrandLockup.vue';

const page = usePage();
const user = computed(() => page.props.auth.user);
const home = computed(() => page.props.auth.home);
</script>

<template>
    <header class="mx-auto flex w-full max-w-5xl items-center justify-between gap-4 p-4">
        <Link href="/" class="focus-visible:ring-ring/50 rounded-md outline-none focus-visible:ring-[3px]"><BrandLockup /></Link>
        <nav class="flex items-center gap-4 text-sm">
            <Link href="/tutors" class="underline-offset-4 hover:underline">Find a tutor</Link>
            <Link v-if="user" :href="home" class="underline-offset-4 hover:underline">Dashboard</Link>
            <template v-else>
                <Link href="/login" class="underline-offset-4 hover:underline">Log in</Link>
                <Link href="/register" class="underline-offset-4 hover:underline">Register</Link>
            </template>
        </nav>
    </header>
</template>
