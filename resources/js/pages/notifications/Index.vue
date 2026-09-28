<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import { useUnreadCounts } from '@/composables/useUnreadCounts';

defineProps<{
    notifications: Array<{
        id: string;
        message: string;
        url: string | null;
        read: boolean;
        created_at_label: string | null;
    }>;
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Notifications', href: '/notifications' }],
    },
});

// So the header/sidebar bell drops to the new count immediately rather than waiting for the next
// 60s poll — `useUnreadCounts` shares one module-level state, so this `refresh()` updates every
// mounted badge, not just this page.
const { refresh } = useUnreadCounts();

function markRead(id: string) {
    router.post(`/notifications/${id}/read`, {}, { preserveScroll: true, preserveState: true, onSuccess: () => void refresh() });
}

function markAllRead() {
    router.post('/notifications/read-all', {}, { preserveScroll: true, preserveState: true, onSuccess: () => void refresh() });
}
</script>

<template>
    <Head title="Notifications" />

    <div class="flex flex-col gap-6 p-4">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <h1 class="text-xl font-semibold">Notifications</h1>
            <Button
                v-if="notifications.some((n) => !n.read)"
                variant="outline"
                size="sm"
                data-test="mark-all-read"
                @click="markAllRead"
            >
                Mark all read
            </Button>
        </div>

        <p v-if="notifications.length === 0" class="text-muted-foreground text-sm" data-test="notifications-empty">
            Nothing here yet.
        </p>

        <ul class="grid gap-2">
            <li v-for="notification in notifications" :key="notification.id">
                <!-- A row with a target opens it via one POST (mark-read + redirect happen server-side,
                     see NotificationController::open) so a click never races a separate mark-read
                     request for the same row. A row with no target (its data couldn't resolve a link)
                     falls back to the plain mark-read POST. -->
                <Link
                    v-if="notification.url"
                    :href="`/notifications/${notification.id}/open`"
                    method="post"
                    as="button"
                    class="hover:bg-accent flex w-full flex-wrap items-center justify-between gap-3 rounded-xl border p-4 text-start"
                    :class="{ 'bg-accent/40': !notification.read }"
                    data-test="notification-row"
                    @success="void refresh()"
                >
                    <span class="flex-1 text-sm">
                        <span :class="{ 'font-medium': !notification.read }">{{ notification.message }}</span>
                        <span v-if="notification.created_at_label" class="text-muted-foreground block text-xs">{{ notification.created_at_label }}</span>
                    </span>
                    <span v-if="!notification.read" class="bg-primary text-primary-foreground min-w-5 rounded-full px-1.5 text-center text-xs" data-test="unread-dot">•</span>
                </Link>

                <div
                    v-else
                    class="flex flex-wrap items-center justify-between gap-3 rounded-xl border p-4"
                    :class="{ 'bg-accent/40': !notification.read }"
                    data-test="notification-row"
                >
                    <span class="flex-1 text-sm">
                        <span :class="{ 'font-medium': !notification.read }">{{ notification.message }}</span>
                        <span v-if="notification.created_at_label" class="text-muted-foreground block text-xs">{{ notification.created_at_label }}</span>
                    </span>
                    <Button v-if="!notification.read" variant="ghost" size="sm" data-test="mark-read" @click="markRead(notification.id)">
                        Mark read
                    </Button>
                </div>
            </li>
        </ul>
    </div>
</template>
