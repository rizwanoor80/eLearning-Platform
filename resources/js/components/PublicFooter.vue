<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

const page = usePage();
const links = computed(() => page.props.footerPages);
const text = computed(() => (page.props.site as { footer_text?: string | null } | undefined)?.footer_text ?? null);
</script>

<template>
    <footer class="text-muted-foreground mx-auto mt-8 flex w-full max-w-5xl flex-col gap-2 border-t p-4 text-xs">
        <nav v-if="links.length" class="flex flex-wrap gap-x-4 gap-y-1">
            <Link v-for="link in links" :key="link.href" :href="link.href" class="underline-offset-4 hover:underline">{{ link.title }}</Link>
        </nav>
        <p v-if="text">{{ text }}</p>
    </footer>
</template>
