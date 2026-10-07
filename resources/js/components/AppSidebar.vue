<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { Bell, BookOpen, ClipboardCheck, FolderGit2, LayoutGrid, MessageSquare } from '@lucide/vue';
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

// R133/R135: Messages shows when the server says the caller may message (feature on, parent or tutor); the badge is the polled unread count.
// CP7 8e: notifications poll unconditionally (not gated on `can_message`) — the server zeroes
// `messages` itself when messaging is off, so polling always is simplest and costs nothing extra.
const messagingOn = computed(() => page.props.auth.can_message);
const { messages: unreadMessages, notifications: unreadNotifications } = useUnreadCounts();

// R173(a): tutors with an incomplete profile (no profile row yet, draft, or changes_requested —
// see HandleInertiaRequests.php) get a standing nav entry back to the wizard; it disappears once
// the profile has moved past those statuses.
const needsOnboarding = computed(() => page.props.auth.needs_onboarding);

const mainNavItems = computed<NavItem[]>(() => [
    {
        title: 'Dashboard',
        href: home.value,
        icon: LayoutGrid,
    },
    ...(needsOnboarding.value ? [{ title: 'Complete your profile', href: '/tutor/onboarding', icon: ClipboardCheck }] : []),
    ...(messagingOn.value ? [{ title: 'Messages', href: '/messages', icon: MessageSquare, badge: unreadMessages.value }] : []),
    // CP7 8e (R139): the notification centre bell, always shown (not gated on `can_message`).
    { title: 'Notifications', href: '/notifications', icon: Bell, badge: unreadNotifications.value },
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
