<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { Badge } from '@/components/ui/badge';
import { dashboard as tutorDashboard } from '@/routes/tutor';

type ScheduledLesson = {
    id: number;
    starts_at: string;
    duration_minutes: number;
    status: string;
    learner_display_name: string;
};

const props = defineProps<{
    today: ScheduledLesson[];
    upcoming: ScheduledLesson[];
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
                    <Badge variant="outline">{{ lesson.status }}</Badge>
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
                    <Badge variant="outline">{{ lesson.status }}</Badge>
                </li>
            </ul>
        </div>
    </div>
</template>
