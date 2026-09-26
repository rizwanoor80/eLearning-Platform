<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { BookOpen, FolderGit2, LayoutGrid, MessageSquare } from '@lucide/vue';
import { computed } from 'vue';
import AppLogo from '@/components/AppLogo.vue';
import NavFooter from '@/components/NavFooter.vue';
import NavMain from '@/components/NavMain.vue';
import NavUser from '@/components/NavUser.vue';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { useUnreadCounts } from '@/composables/useUnreadCounts';
import type { NavItem } from '@/types';

const page = usePage();
const home = computed(() => page.props.auth.home);

// R133/R135: Messages shows for the two portals when the feature is on; the badge is the polled unread count.
const messagingOn = computed(() => page.props.features.messaging && ['account_owner', 'tutor'].includes(String(page.props.auth.user?.role ?? '')));
const { messages: unreadMessages } = useUnreadCounts(() => messagingOn.value);

const mainNavItems = computed<NavItem[]>(() => [
    {
        title: 'Dashboard',
        href: home.value,
        icon: LayoutGrid,
    },
    ...(messagingOn.value ? [{ title: 'Messages', href: '/messages', icon: MessageSquare, badge: unreadMessages.value }] : []),
]);

const footerNavItems: NavItem[] = [
    {
        title: 'Repository',
        href: 'https://github.com/laravel/vue-starter-kit',
        icon: FolderGit2,
    },
    {
        title: 'Documentation',
        href: 'https://laravel.com/docs/starter-kits#vue',
        icon: BookOpen,
    },
];
</script>

<template>
    <Sidebar collapsible="icon" variant="inset">
        <SidebarHeader>
            <SidebarMenu>
                <SidebarMenuItem>
                    <SidebarMenuButton size="lg" as-child>
                        <Link :href="home">
                            <AppLogo />
                        </Link>
                    </SidebarMenuButton>
                </SidebarMenuItem>
            </SidebarMenu>
        </SidebarHeader>

        <SidebarContent>
            <NavMain :items="mainNavItems" />
        </SidebarContent>

        <SidebarFooter>
            <NavFooter :items="footerNavItems" />
            <NavUser />
        </SidebarFooter>
    </Sidebar>
    <slot />
</template>
