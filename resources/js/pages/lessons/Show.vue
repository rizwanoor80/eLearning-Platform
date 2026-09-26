<script setup lang="ts">
import { Head, router, usePoll } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';

type LessonView = {
    id: number;
    status: string;
    side: 'tutor' | 'parent';
    subject: string | null;
    duration_minutes: number;
    learner_display_name: string;
    tutor_display_name: string;
    starts_at_label: string;
    timezone: string;
    join_opens_label: string;
    room_ready: boolean;
    window: 'before' | 'open' | 'after';
    embed: boolean;
    can_join: boolean;
    join_problem: string | null;
    can_mark_joined: boolean;
    can_mark_no_show: boolean;
    i_joined: boolean;
    other_joined: boolean;
    other_role: 'student' | 'tutor';
    no_show_outcome: 'pay_tutor' | 'refund_parent';
    terminal: boolean;
};

const props = defineProps<{ lesson: LessonView }>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Lesson', href: '#' }],
    },
});

// Attendance arrives by webhook while the page is open, so the flags are refreshed in place (Reverb is deferred).
// A page opened on a closed lesson never starts polling; one that turns closed while open stops (the watch below).
const { stop } = usePoll(30000, { only: ['lesson'] }, { autoStart: !props.lesson.terminal });

watch(
    () => props.lesson.terminal,
    (terminal) => terminal && stop(),
);

// The join token lives only in this component's memory: it is never a prop, never stored, never in the address bar
// of this page. It goes to the provider's own room URL, which is how the provider's prebuilt room takes it.
const joinUrl = ref<string | null>(null);
const joining = ref(false);
const joinError = ref<string | null>(null);

const statusLabel = computed(() => props.lesson.status.replaceAll('_', ' '));

function xsrfToken(): string {
    const match = document.cookie.split('; ').find((row) => row.startsWith('XSRF-TOKEN='));

    return match ? decodeURIComponent(match.slice('XSRF-TOKEN='.length)) : '';
}

async function join() {
    joining.value = true;
    joinError.value = null;

    try {
        const response = await fetch(`/lessons/${props.lesson.id}/room`, {
            method: 'POST',
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-XSRF-TOKEN': xsrfToken(),
            },
            credentials: 'same-origin',
        });
        const body = (await response.json().catch(() => ({}))) as { url?: string; token?: string; message?: string };

        if (!response.ok || !body.url || !body.token) {
            joinError.value = body.message ?? 'The video room is unavailable right now. Please try again in a moment.';

            return;
        }

        joinUrl.value = `${body.url}${body.url.includes('?') ? '&' : '?'}t=${encodeURIComponent(body.token)}`;
    } catch {
        joinError.value = 'The video room is unavailable right now. Please try again in a moment.';
    } finally {
        joining.value = false;
    }
}

function markJoined() {
    // preserveState: a redirect back to this page must not remount it, or an open room frame and its token are lost.
    router.post(`/lessons/${props.lesson.id}/joined`, {}, { preserveScroll: true, preserveState: true });
}

function markNoShow() {
    const consequence =
        props.lesson.no_show_outcome === 'pay_tutor'
            ? 'The tutor is paid in full for this lesson and the parent is not refunded.'
            : 'You are refunded in full and the no-show is recorded on the tutor’s account.';

    if (!window.confirm(`Mark the ${props.lesson.other_role} as absent? ${consequence} This cannot be undone.`)) {
        return;
    }

    router.post(`/lessons/${props.lesson.id}/no-show`, {}, { preserveScroll: true, preserveState: true });
}

const closedMessages: Record<string, string> = {
    completed: 'This lesson took place. A progress report will follow.',
    completed_reported: 'This lesson took place and its report has been submitted.',
    cancelled_by_parent: 'This lesson was cancelled by the parent.',
    cancelled_by_tutor: 'This lesson was cancelled by the tutor.',
    cancelled_payment_failed: 'This lesson was cancelled because its payment failed.',
    expired: 'This lesson was not paid for in time and has expired.',
    refunded: 'This lesson was refunded.',
    no_show_both: 'Nobody joined this lesson, so it was refunded.',
    provider_failure: 'This lesson was refunded because the video service failed.',
    settled: 'This lesson took place and has been settled.',
    disputed: 'This lesson is under review.',
    no_show_student: 'The student did not join, so the tutor was paid for this lesson.',
    no_show_tutor: 'The tutor did not join, so this lesson was refunded.',
};
</script>

<template>
    <Head :title="`${lesson.subject ?? 'Lesson'} lesson`" />

    <div class="flex flex-col gap-6 p-4">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div class="grid gap-1">
                <h1 class="text-xl font-semibold">
                    {{ lesson.subject ?? 'Lesson' }} with
                    {{ lesson.side === 'parent' ? lesson.tutor_display_name : lesson.learner_display_name }}
                </h1>
                <p class="text-muted-foreground text-sm">
                    {{ lesson.starts_at_label }} ({{ lesson.timezone }}) · {{ lesson.duration_minutes }} min
                    <template v-if="lesson.side === 'parent'"> · for {{ lesson.learner_display_name }}</template>
                </p>
            </div>
            <Badge variant="outline">{{ statusLabel }}</Badge>
        </div>

        <p v-if="lesson.terminal" class="rounded-xl border p-4 text-sm" data-test="closed">
            {{ closedMessages[lesson.status] ?? 'This lesson is closed.' }}
        </p>

        <p v-else-if="lesson.status === 'reserved'" class="rounded-xl border p-4 text-sm" data-test="reserved">
            This weekly lesson is reserved. It is confirmed, and the room made, once the payment goes through.
        </p>

        <p v-else-if="lesson.status === 'pending_payment'" class="rounded-xl border p-4 text-sm" data-test="pending-payment">
            This lesson is waiting for its payment. The room opens once it is confirmed.
        </p>

        <template v-else>
            <p v-if="lesson.window === 'before'" class="rounded-xl border p-4 text-sm" data-test="waiting">
                The room opens at {{ lesson.join_opens_label }}, 10 minutes before the lesson.
            </p>
            <p v-else-if="lesson.window === 'after'" class="rounded-xl border p-4 text-sm" data-test="room-closed">
                The room for this lesson has closed.
            </p>

            <div v-if="lesson.can_join" class="grid gap-3 rounded-xl border p-4" data-test="join">
                <div class="flex flex-wrap items-center gap-3">
                    <Button :disabled="joining" data-test="join-button" @click="join">
                        <Spinner v-if="joining" />
                        {{ lesson.embed ? 'Join the lesson here' : 'Get the lesson link' }}
                    </Button>
                    <a
                        v-if="joinUrl && !lesson.embed"
                        :href="joinUrl"
                        target="_blank"
                        rel="noopener noreferrer"
                        class="text-sm underline underline-offset-4"
                        data-test="join-link"
                    >
                        Open the lesson room in a new tab
                    </a>
                </div>
                <p v-if="joinError" class="text-destructive text-sm" role="alert" data-test="join-error">{{ joinError }}</p>
                <iframe
                    v-if="joinUrl && lesson.embed"
                    :src="joinUrl"
                    allow="camera; microphone; fullscreen; display-capture"
                    class="aspect-video w-full rounded-lg border"
                    title="Lesson room"
                    data-test="room-frame"
                />
            </div>
            <p v-else-if="lesson.join_problem && lesson.window === 'open'" class="text-muted-foreground text-sm" data-test="join-problem">
                {{ lesson.join_problem }}
            </p>

            <div v-if="lesson.can_mark_joined" class="flex flex-wrap items-center gap-3 rounded-xl border p-4" data-test="manual-join">
                <p class="text-sm">This room does not report who has joined, so tell us when you are in.</p>
                <Button variant="outline" data-test="joined-button" @click="markJoined">I've joined</Button>
            </div>

            <div class="text-muted-foreground grid gap-1 text-sm" data-test="attendance">
                <span v-if="lesson.i_joined">You have joined.</span>
                <span v-if="lesson.other_joined">The {{ lesson.other_role }} has joined.</span>
            </div>

            <div v-if="lesson.can_mark_no_show" class="grid gap-2 rounded-xl border p-4" data-test="no-show">
                <p class="text-sm">The {{ lesson.other_role }} has not joined and the waiting time is over.</p>
                <div>
                    <Button variant="outline" data-test="no-show-button" @click="markNoShow">
                        Mark the {{ lesson.other_role }} as absent
                    </Button>
                </div>
            </div>
        </template>
    </div>
</template>
