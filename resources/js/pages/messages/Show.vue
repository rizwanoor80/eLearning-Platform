<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';

const props = defineProps<{
    conversation: {
        id: number;
        counterpart: string;
        closed: boolean;
        closed_notice: string;
        contact_hidden: boolean;
        placeholder: string;
    };
    messages: Array<{
        id: number;
        mine: boolean;
        body: string;
        body_masked: boolean;
        sent_at: string | null;
    }>;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Messages', href: '/messages' },
            { title: 'Conversation', href: '#' },
        ],
    },
});

const form = useForm({ body: '' });

function submit() {
    form.post(`/messages/${props.conversation.id}`, {
        preserveScroll: true,
        onSuccess: () => form.reset('body'),
    });
}
</script>

<template>
    <Head :title="`Messages · ${conversation.counterpart}`" />

    <div class="flex max-w-2xl flex-col gap-4 p-4">
        <div class="flex items-baseline justify-between gap-2">
            <h1 class="text-xl font-semibold" data-test="conversation-title">{{ conversation.counterpart }}</h1>
            <Link href="/messages" class="text-muted-foreground text-sm underline underline-offset-4">All messages</Link>
        </div>

        <p v-if="conversation.contact_hidden" class="text-muted-foreground text-sm" data-test="masking-notice">
            Emails, phone numbers and web addresses are hidden until after your first lesson together.
        </p>

        <p v-if="messages.length === 0" class="text-muted-foreground text-sm">No messages yet.</p>

        <ul class="flex flex-col gap-2" data-test="thread">
            <!-- Plain text only: {{ }} escapes, and whitespace-pre-wrap keeps the sender's line breaks. -->
            <li
                v-for="message in messages"
                :key="message.id"
                class="max-w-[85%] rounded-xl border p-3"
                :class="message.mine ? 'bg-accent self-end' : 'self-start'"
                data-test="message"
            >
                <p class="text-sm break-words whitespace-pre-wrap">{{ message.body }}</p>
                <p class="text-muted-foreground mt-1 text-xs">{{ message.sent_at }}</p>
            </li>
        </ul>

        <p v-if="conversation.closed" class="text-muted-foreground rounded-xl border p-3 text-sm" data-test="closed-notice">
            {{ conversation.closed_notice }}
        </p>

        <form v-else class="grid gap-2" data-test="message-form" @submit.prevent="submit">
            <label for="body" class="sr-only">Message</label>
            <textarea id="body" v-model="form.body" rows="3" maxlength="2000" required class="border-input rounded-md border p-2 text-sm" />
            <InputError :message="form.errors.body" />
            <div class="flex items-center justify-between gap-2">
                <span class="text-muted-foreground text-xs">{{ form.body.length }} / 2000</span>
                <Button type="submit" :disabled="form.processing">
                    <Spinner v-if="form.processing" />
                    Send
                </Button>
            </div>
        </form>
    </div>
</template>
