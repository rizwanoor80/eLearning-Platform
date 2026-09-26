<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import TestModeBanner from '@/components/TestModeBanner.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';

defineProps<{
    learner: { id: number; display_name: string; is_minor: boolean; year_group: string | null; curriculum: string | null };
    slots: Array<{
        id: number;
        status: string;
        paused_reason: string | null;
        tutor: string;
        subject: string | null;
        schedule: string;
        next: string | null;
        price: string;
        ends_on: string | null;
        end_effective_on: string | null;
        tutor_notice: boolean;
        can_resume: boolean;
    }>;
    lessons: Array<{ id: number; starts_at: string; status: string; subject: string | null; tutor: string; cancel_kind: 'skip' | null }>;
    eligible_tutors: Array<{ id: number; name: string }>;
    reports: Array<{
        id: number;
        lesson_id: number;
        date: string;
        subject: string | null;
        tutor: string;
        is_trial: boolean;
        topics_covered: string;
        went_well: string;
        work_on_next: string;
        homework: string;
        engagement: number;
        trial_suitability: string | null;
        trial_recommended_frequency: number | null;
        trial_focus_areas: string | null;
    }>;
    reports_capped: boolean;
    recent_focus: Array<{
        lesson_id: number;
        date: string;
        subject: string | null;
        tutor: string;
        work_on_next: string;
        trial_focus_areas: string | null;
    }>;
    timezone: string;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Learners', href: '/learners' },
            { title: 'Learner', href: '#' },
        ],
    },
});

function skip(id: number) {
    router.post(`/lessons/${id}/cancel`);
}

function resume(id: number) {
    router.post(`/weekly-slots/${id}/resume`);
}
</script>

<template>
    <Head :title="learner.display_name" />

    <div class="flex flex-col gap-6 p-4">
        <div class="grid gap-1">
            <h1 class="text-xl font-semibold">{{ learner.display_name }}</h1>
            <p class="text-muted-foreground text-sm">{{ [learner.curriculum, learner.year_group].filter(Boolean).join(' · ') }}</p>
        </div>

        <section class="grid gap-3">
            <h2 class="font-medium">Weekly slots</h2>
            <TestModeBanner />
            <p class="text-muted-foreground text-xs">
                A standing weekly reservation: each lesson is charged to your saved card shortly before it starts. Times are shown in {{ timezone }}.
            </p>

            <p v-if="slots.length === 0" class="text-muted-foreground text-sm">No weekly slots yet.</p>

            <ul v-else class="divide-y rounded-xl border">
                <li v-for="slot in slots" :key="slot.id" class="flex items-center justify-between gap-4 p-4">
                    <div class="grid gap-1">
                        <span class="font-medium">{{ slot.subject ?? 'Lesson' }} with {{ slot.tutor }}</span>
                        <span class="text-muted-foreground text-sm">{{ slot.schedule }} · {{ slot.price }}</span>
                        <span v-if="slot.next" class="text-muted-foreground text-sm">Next lesson: {{ slot.next }}</span>
                        <span v-if="slot.tutor_notice && slot.end_effective_on" class="text-sm">
                            Your tutor has ended this slot; the last day is {{ slot.end_effective_on }}.
                        </span>
                        <span v-else-if="slot.ends_on && slot.status !== 'ended'" class="text-muted-foreground text-sm">Ends on {{ slot.ends_on }}</span>
                        <span v-if="slot.status === 'paused'" class="text-sm">
                            Paused{{ slot.paused_reason === 'payment_failed' ? ' — the last payment did not go through' : '' }}.
                        </span>
                    </div>
                    <div class="flex items-center gap-2">
                        <Badge variant="outline">{{ slot.status }}</Badge>
                        <template v-if="slot.can_resume">
                            <Button variant="outline" size="sm" as-child>
                                <Link :href="`/payment-methods/create?learner=${learner.id}`">Replace card</Link>
                            </Button>
                            <Button size="sm" @click="resume(slot.id)">Resume</Button>
                        </template>
                        <Button v-if="slot.status !== 'ended'" variant="outline" size="sm" as-child>
                            <Link :href="`/weekly-slots/${slot.id}/end`">End</Link>
                        </Button>
                    </div>
                </li>
            </ul>

            <div v-if="eligible_tutors.length" class="flex flex-wrap gap-2">
                <Button v-for="tutor in eligible_tutors" :key="tutor.id" variant="outline" size="sm" as-child>
                    <Link :href="`/weekly-slots/create?tutor=${tutor.id}&learner=${learner.id}`">Set up a weekly slot with {{ tutor.name }}</Link>
                </Button>
            </div>
            <p v-else class="text-muted-foreground text-sm">
                A weekly slot is available with a tutor once your trial lesson with them is completed.
                <Link href="/tutors" class="underline underline-offset-4">Find a tutor</Link>
            </p>
        </section>

        <section v-if="lessons.length" class="grid gap-3">
            <h2 class="font-medium">Upcoming weekly lessons</h2>
            <ul class="divide-y rounded-xl border">
                <li v-for="lesson in lessons" :key="lesson.id" class="flex items-center justify-between gap-4 p-4">
                    <div class="grid gap-1">
                        <span class="font-medium">{{ lesson.subject ?? 'Lesson' }} with {{ lesson.tutor }}</span>
                        <span class="text-muted-foreground text-sm">{{ lesson.starts_at }}</span>
                    </div>
                    <div class="flex items-center gap-2">
                        <Badge variant="outline">{{ lesson.status }}</Badge>
                        <Button v-if="lesson.cancel_kind" variant="outline" size="sm" @click="skip(lesson.id)">Skip</Button>
                    </div>
                </li>
            </ul>
        </section>

        <section v-if="recent_focus.length" class="grid gap-3" data-test="recent-focus">
            <h2 class="font-medium">Recent focus areas</h2>
            <p class="text-muted-foreground text-xs">What the tutors said to work on next, from the last {{ recent_focus.length }} {{ recent_focus.length === 1 ? 'report' : 'reports' }}.</p>
            <ul class="divide-y rounded-xl border">
                <li v-for="item in recent_focus" :key="item.lesson_id" class="grid gap-1 p-4">
                    <span class="text-muted-foreground text-xs">{{ item.date }} · {{ item.subject ?? 'Lesson' }} with {{ item.tutor }}</span>
                    <span class="text-sm whitespace-pre-line">{{ item.work_on_next }}</span>
                    <span v-if="item.trial_focus_areas" class="text-sm whitespace-pre-line"><strong>First month:</strong> {{ item.trial_focus_areas }}</span>
                </li>
            </ul>
        </section>

        <section v-if="reports.length" class="grid gap-3" data-test="report-timeline">
            <h2 class="font-medium">Progress reports</h2>
            <p v-if="reports_capped" class="text-muted-foreground text-xs">Showing the latest {{ reports.length }} reports.</p>
            <ol class="grid gap-3">
                <li v-for="report in reports" :key="report.id" class="grid gap-2 rounded-xl border p-4">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <span class="font-medium">{{ report.date }} · {{ report.subject ?? 'Lesson' }} with {{ report.tutor }}</span>
                        <div class="flex items-center gap-2">
                            <Badge v-if="report.is_trial" variant="secondary">trial</Badge>
                            <Badge variant="outline">engagement {{ report.engagement }}/5</Badge>
                        </div>
                    </div>
                    <p class="text-sm whitespace-pre-line"><strong>Topics covered:</strong> {{ report.topics_covered }}</p>
                    <p class="text-sm whitespace-pre-line"><strong>What went well:</strong> {{ report.went_well }}</p>
                    <p class="text-sm whitespace-pre-line"><strong>To work on next:</strong> {{ report.work_on_next }}</p>
                    <p class="text-sm whitespace-pre-line"><strong>Homework set:</strong> {{ report.homework }}</p>
                    <template v-if="report.is_trial">
                        <p class="text-sm"><strong>Fit:</strong> {{ report.trial_suitability }}</p>
                        <p class="text-sm">
                            <strong>Recommended:</strong> {{ report.trial_recommended_frequency }}
                            {{ report.trial_recommended_frequency === 1 ? 'lesson' : 'lessons' }} a week
                        </p>
                        <p class="text-sm whitespace-pre-line"><strong>Focus areas for the first month:</strong> {{ report.trial_focus_areas }}</p>
                    </template>
                </li>
            </ol>
        </section>
    </div>
</template>
