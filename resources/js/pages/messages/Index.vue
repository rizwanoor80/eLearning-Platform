<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';

defineProps<{
    conversations: Array<{
        id: number;
        counterpart: string;
        unread_count: number;
        last_message_at: string | null;
        closed: boolean;
    }>;
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Messages', href: '/messages' }],
    },
});
</script>

<template>
    <Head title="Messages" />

    <div class="flex flex-col gap-6 p-4">
        <h1 class="text-xl font-semibold">Messages</h1>

        <p v-if="conversations.length === 0" class="text-muted-foreground text-sm" data-test="messages-empty">
            No conversations yet. A conversation opens once a lesson is booked.
        </p>

        <ul class="grid gap-2">
            <li v-for="conversation in conversations" :key="conversation.id">
                <Link
                    :href="`/messages/${conversation.id}`"
                    class="hover:bg-accent flex flex-wrap items-baseline justify-between gap-2 rounded-xl border p-4"
                    data-test="conversation-row"
                >
                    <span class="font-medium">{{ conversation.counterpart }}</span>
                    <span class="flex items-center gap-3 text-sm">
                        <span v-if="conversation.closed" class="text-muted-foreground">Closed</span>
                        <span v-if="conversation.last_message_at" class="text-muted-foreground">{{ conversation.last_message_at }}</span>
                        <span
                            v-if="conversation.unread_count > 0"
                            class="bg-primary text-primary-foreground min-w-5 rounded-full px-1.5 text-center text-xs font-medium"
                            data-test="unread-count"
                        >
                            {{ conversation.unread_count }}
                        </span>
                    </span>
                </Link>
            </li>
        </ul>
    </div>
</template>
