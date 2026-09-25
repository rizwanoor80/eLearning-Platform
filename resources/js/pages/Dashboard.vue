<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { dashboard } from '@/routes';

type UpcomingLesson = {
    id: number;
    starts_at: string;
    duration_minutes: number;
    status: string;
    subject: string | null;
    learner_display_name: string;
    tutor_display_name: string;
    price: string;
    weekly: boolean;
    cancel_window_hours: number;
    cancel_kind: 'skip' | 'cancel' | null;
};

const props = defineProps<{
    upcoming: UpcomingLesson[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Dashboard',
                href: dashboard(),
            },
        ],
    },
});

function cancel(lesson: UpcomingLesson) {
    if (lesson.cancel_kind === 'cancel') {
        const confirmed = window.confirm(
            `Cancelling this lesson ${lesson.cancel_window_hours} hours or more before it starts gets a full refund. ` +
                `Cancelling inside that window pays the tutor in full instead. Cancel this lesson?`,
        );

        if (!confirmed) {
            return;
        }
    }

    router.post(`/lessons/${lesson.id}/cancel`);
}
</script>

<template>
    <Head title="Dashboard" />

    <div class="flex flex-col gap-6 p-4">
        <div class="flex gap-6">
            <Link href="/learners" class="text-sm underline underline-offset-4">
                Manage your learners
            </Link>
            <Link href="/tutors" class="text-sm underline underline-offset-4">
                Find a tutor
            </Link>
            <Link
                v-if="$page.props.features.match_requests"
                href="/match-requests"
                class="text-sm underline underline-offset-4"
            >
                Request a match
            </Link>
        </div>

        <h1 class="text-xl font-semibold">Upcoming lessons</h1>

        <p v-if="props.upcoming.length === 0" class="text-muted-foreground text-sm">
            No upcoming lessons yet.
        </p>

        <ul v-else class="divide-y rounded-xl border">
            <li v-for="lesson in props.upcoming" :key="lesson.id" class="flex items-center justify-between gap-4 p-4">
                <div class="grid gap-1">
                    <span class="font-medium">
                        {{ lesson.subject ?? 'Lesson' }} with {{ lesson.tutor_display_name }}
                    </span>
                    <span class="text-muted-foreground text-sm">
                        {{ lesson.starts_at }} · {{ lesson.duration_minutes }} min · {{ lesson.learner_display_name }} · {{ lesson.price }}
                    </span>
                </div>
                <div class="flex items-center gap-2">
                    <Badge v-if="lesson.weekly" variant="secondary">weekly</Badge>
                    <Badge variant="outline">{{ lesson.status }}</Badge>
                    <Button v-if="lesson.cancel_kind" variant="outline" size="sm" @click="cancel(lesson)">
                        {{ lesson.cancel_kind === 'skip' ? 'Skip' : 'Cancel' }}
                    </Button>
                </div>
            </li>
        </ul>
    </div>
</template>
