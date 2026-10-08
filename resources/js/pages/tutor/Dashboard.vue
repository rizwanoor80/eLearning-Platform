<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ClipboardCheck } from '@lucide/vue';
import InputError from '@/components/InputError.vue';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { dashboard as tutorDashboard } from '@/routes/tutor';

type OnboardingBanner = {
    visible: boolean;
    missing: string[];
};

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

type ReportDue = {
    id: number;
    starts_at: string;
    learner_display_name: string;
    is_trial: boolean;
    released: boolean;
    due_by: string | null;
    auto_release_by: string | null;
};

const props = defineProps<{
    reportsDue: ReportDue[];
    today: ScheduledLesson[];
    upcoming: ScheduledLesson[];
    slots: WeeklySlot[];
    onboarding: OnboardingBanner;
    needsAvailability: boolean;
    leadTime: { current: number; options: Array<{ value: number; label: string }> } | null;
}>();

const leadTimeForm = useForm({ min_lead_hours: props.leadTime?.current ?? 12 });

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
        <Alert v-if="props.onboarding.visible" data-test="onboarding-banner">
            <ClipboardCheck class="size-4" />
            <AlertTitle>Finish setting up your tutor profile</AlertTitle>
            <AlertDescription>
                <p v-if="props.onboarding.missing.length > 0" class="text-sm">
                    Still needed: {{ props.onboarding.missing.join(', ') }}.
                </p>
                <Link href="/tutor/onboarding" class="text-sm underline underline-offset-4">Continue onboarding</Link>
            </AlertDescription>
        </Alert>

        <Alert v-if="props.needsAvailability" data-test="availability-banner">
            <ClipboardCheck class="size-4" />
            <AlertTitle>Add your availability to appear in search</AlertTitle>
            <AlertDescription>
                <p class="text-sm">You are approved, but parents cannot find or book you until you add at least one weekly window.</p>
                <Link href="/tutor/onboarding" class="text-sm underline underline-offset-4">Add availability</Link>
            </AlertDescription>
        </Alert>

        <div v-if="props.leadTime" class="grid gap-2 rounded-xl border p-4" data-test="lead-time">
            <h1 class="text-xl font-semibold">Booking notice</h1>
            <form class="grid gap-2" @submit.prevent="leadTimeForm.post('/tutor/booking-lead-time', { preserveScroll: true })">
                <Label for="min_lead_hours">How soon can a parent book you?</Label>
                <select id="min_lead_hours" v-model="leadTimeForm.min_lead_hours" class="border-input rounded-md border p-2 text-sm">
                    <option v-for="option in props.leadTime.options" :key="option.value" :value="option.value">{{ option.label }}</option>
                </select>
                <p class="text-muted-foreground text-xs">
                    A lesson that starts within 24 hours cannot be cancelled by the parent for a refund. Lessons already booked do not change.
                </p>
                <InputError :message="leadTimeForm.errors.min_lead_hours" />
                <div>
                    <Button type="submit" :disabled="leadTimeForm.processing">Save</Button>
                </div>
            </form>
        </div>
        <div v-if="props.reportsDue.length > 0" data-test="reports-due">
            <h1 class="mb-3 text-xl font-semibold">Reports due</h1>
            <ul class="divide-y rounded-xl border">
                <li v-for="lesson in props.reportsDue" :key="lesson.id" class="flex items-center justify-between gap-4 p-4">
                    <div class="grid gap-1">
                        <span class="font-medium">{{ lesson.learner_display_name }}</span>
                        <span class="text-muted-foreground text-sm">
                            {{ lesson.starts_at }}<template v-if="lesson.due_by"> · due {{ lesson.due_by }}</template>
                        </span>
                        <span v-if="lesson.released" class="text-muted-foreground text-sm">
                            Released without a report and flagged as late. You can still file it.
                        </span>
                        <span v-else-if="lesson.auto_release_by" class="text-muted-foreground text-sm">
                            Without a report by {{ lesson.auto_release_by }}, the payment is released and the lesson is flagged as late.
                        </span>
                    </div>
                    <div class="flex items-center gap-2">
                        <Badge v-if="lesson.is_trial" variant="secondary">trial</Badge>
                        <Link :href="`/lessons/${lesson.id}/report`" class="text-sm underline underline-offset-4">Write the report</Link>
                    </div>
                </li>
            </ul>
        </div>

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
