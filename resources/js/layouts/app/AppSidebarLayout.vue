<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import AppContent from '@/components/AppContent.vue';
import AppShell from '@/components/AppShell.vue';
import AppSidebar from '@/components/AppSidebar.vue';
import AppSidebarHeader from '@/components/AppSidebarHeader.vue';
import { Toaster } from '@/components/ui/sonner';
import type { BreadcrumbItem } from '@/types';

type Props = {
    breadcrumbs?: BreadcrumbItem[];
};

withDefaults(defineProps<Props>(), {
    breadcrumbs: () => [],
});

const page = usePage<{ site?: { footer_text: string | null }; footerPages?: Array<{ title: string; href: string }> }>();
</script>

<template>
    <AppShell variant="sidebar">
        <AppSidebar />
        <AppContent variant="sidebar" class="min-w-0 overflow-x-clip">
            <AppSidebarHeader :breadcrumbs="breadcrumbs" />
            <slot />
            <footer v-if="page.props.site?.footer_text || page.props.footerPages?.length" class="text-muted-foreground flex flex-col gap-2 border-t px-4 py-3 text-xs">
                <nav v-if="page.props.footerPages?.length" class="flex flex-wrap gap-x-4 gap-y-1">
                    <Link v-for="link in page.props.footerPages" :key="link.href" :href="link.href" class="underline-offset-4 hover:underline">{{ link.title }}</Link>
                </nav>
                <p v-if="page.props.site?.footer_text">{{ page.props.site.footer_text }}</p>
            </footer>
        </AppContent>
        <Toaster />
    </AppShell>
</template>
