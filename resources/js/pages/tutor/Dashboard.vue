<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { Badge } from '@/components/ui/badge';
import { dashboard as tutorDashboard } from '@/routes/tutor';

type ScheduledLesson = {
    id: number;
    starts_at: string;
    duration_minutes: number;
    status: string;
    learner_display_name: string;
    weekly: boolean;
};

type WeeklySlot = {
    id: number;
    learner_display_name: string;
    subject: string | null;
    schedule: string;
    status: string;
    ending_on: string | null;
};

const props = defineProps<{
    today: ScheduledLesson[];
    upcoming: ScheduledLesson[];
    slots: WeeklySlot[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Dashboard',
                href: tutorDashboard(),
            },
        ],
    },
});
</script>

<template>
    <Head title="Dashboard" />

    <div class="flex flex-col gap-6 p-4">
        <div>
            <h1 class="mb-3 text-xl font-semibold">Weekly slots</h1>
            <p v-if="props.slots.length === 0" class="text-muted-foreground text-sm">
                No weekly slots yet.
            </p>
            <ul v-else class="divide-y rounded-xl border">
                <li v-for="slot in props.slots" :key="slot.id" class="flex items-center justify-between gap-4 p-4">
                    <div class="grid gap-1">
                        <span class="font-medium">{{ slot.learner_display_name }}</span>
                        <span class="text-muted-foreground text-sm">
                            {{ slot.subject ?? 'Lesson' }} · {{ slot.schedule }}
                        </span>
                        <span v-if="slot.ending_on" class="text-sm">Ending — last day {{ slot.ending_on }}</span>
                    </div>
                    <div class="flex items-center gap-2">
                        <Badge variant="outline">{{ slot.status }}</Badge>
                        <Link
                            v-if="!slot.ending_on"
                            :href="`/tutor/weekly-slots/${slot.id}/end`"
                            class="text-sm underline underline-offset-4"
                        >
                            End
                        </Link>
                    </div>
                </li>
            </ul>
        </div>

        <div>
            <h1 class="mb-3 text-xl font-semibold">Today</h1>
            <p v-if="props.today.length === 0" class="text-muted-foreground text-sm">
                No lessons today.
            </p>
            <ul v-else class="divide-y rounded-xl border">
                <li v-for="lesson in props.today" :key="lesson.id" class="flex items-center justify-between gap-4 p-4">
                    <div class="grid gap-1">
                        <span class="font-medium">{{ lesson.learner_display_name }}</span>
                        <span class="text-muted-foreground text-sm">
                            {{ lesson.starts_at }} · {{ lesson.duration_minutes }} min
                        </span>
                    </div>
                    <div class="flex items-center gap-2">
                        <Badge v-if="lesson.weekly" variant="secondary">weekly</Badge>
                        <Badge variant="outline">{{ lesson.status }}</Badge>
                        <Link :href="`/lessons/${lesson.id}`" class="text-sm underline underline-offset-4">Open</Link>
                    </div>
                </li>
            </ul>
        </div>

        <div>
            <h1 class="mb-3 text-xl font-semibold">Upcoming</h1>
            <p v-if="props.upcoming.length === 0" class="text-muted-foreground text-sm">
                No upcoming lessons yet.
            </p>
            <ul v-else class="divide-y rounded-xl border">
                <li v-for="lesson in props.upcoming" :key="lesson.id" class="flex items-center justify-between gap-4 p-4">
                    <div class="grid gap-1">
                        <span class="font-medium">{{ lesson.learner_display_name }}</span>
                        <span class="text-muted-foreground text-sm">
                            {{ lesson.starts_at }} · {{ lesson.duration_minutes }} min
                        </span>
                    </div>
                    <div class="flex items-center gap-2">
                        <Badge v-if="lesson.weekly" variant="secondary">weekly</Badge>
                        <Badge variant="outline">{{ lesson.status }}</Badge>
                    </div>
                </li>
            </ul>
        </div>
    </div>
</template>
