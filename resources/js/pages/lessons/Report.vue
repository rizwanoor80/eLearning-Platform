<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';

const props = defineProps<{
    lesson: {
        id: number;
        subject: string | null;
        learner_display_name: string;
        starts_at_label: string;
        timezone: string;
        is_trial: boolean;
        released_already: boolean;
    };
    suitabilityOptions: Array<{ value: string; label: string }>;
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Lesson report', href: '#' }],
    },
});

// The trial fields are sent only for a trial lesson: the server reads the lesson's own type and refuses them on a regular one.
const form = useForm({
    topics_covered: '',
    went_well: '',
    work_on_next: '',
    homework: '',
    engagement: '',
    trial_suitability: '',
    trial_recommended_frequency: '',
    trial_focus_areas: '',
});

function submit() {
    form
        .transform((data) =>
            props.lesson.is_trial
                ? data
                : {
                      topics_covered: data.topics_covered,
                      went_well: data.went_well,
                      work_on_next: data.work_on_next,
                      homework: data.homework,
                      engagement: data.engagement,
                  },
        )
        .post(`/lessons/${props.lesson.id}/report`);
}
</script>

<template>
    <Head :title="lesson.is_trial ? 'Trial lesson report' : 'Lesson report'" />

    <form class="flex max-w-xl flex-col gap-6 p-4" data-test="report-form" @submit.prevent="submit">
        <div class="grid gap-1">
            <h1 class="text-xl font-semibold">{{ lesson.is_trial ? 'Trial lesson report' : 'Lesson report' }}</h1>
            <p class="text-muted-foreground text-sm">
                {{ lesson.subject ?? 'Lesson' }} with {{ lesson.learner_display_name }} · {{ lesson.starts_at_label }} ({{ lesson.timezone }})
            </p>
            <p class="text-muted-foreground text-sm" data-test="report-note">
                {{
                    lesson.released_already
                        ? 'Your payment for this lesson has already been released. The report goes to the parent.'
                        : 'The report goes to the parent, and your payment for the lesson is released when you submit it.'
                }}
            </p>
        </div>

        <div class="grid gap-2">
            <Label for="topics_covered">Topics covered</Label>
            <textarea id="topics_covered" v-model="form.topics_covered" rows="3" maxlength="3000" required class="border-input rounded-md border p-2 text-sm" />
            <InputError :message="form.errors.topics_covered" />
        </div>

        <div class="grid gap-2">
            <Label for="went_well">What went well</Label>
            <textarea id="went_well" v-model="form.went_well" rows="3" maxlength="3000" required class="border-input rounded-md border p-2 text-sm" />
            <InputError :message="form.errors.went_well" />
        </div>

        <div class="grid gap-2">
            <Label for="work_on_next">What to work on next</Label>
            <textarea id="work_on_next" v-model="form.work_on_next" rows="3" maxlength="3000" required class="border-input rounded-md border p-2 text-sm" />
            <InputError :message="form.errors.work_on_next" />
        </div>

        <div class="grid gap-2">
            <Label for="homework">Homework set</Label>
            <textarea id="homework" v-model="form.homework" rows="2" maxlength="3000" required class="border-input rounded-md border p-2 text-sm" />
            <p class="text-muted-foreground text-xs">Write “None” if you set none.</p>
            <InputError :message="form.errors.homework" />
        </div>

        <div class="grid gap-2">
            <Label for="engagement">Engagement</Label>
            <select id="engagement" v-model="form.engagement" required class="border-input rounded-md border p-2 text-sm">
                <option value="" disabled>Select</option>
                <option v-for="n in 5" :key="n" :value="n">{{ n }} out of 5</option>
            </select>
            <InputError :message="form.errors.engagement" />
        </div>

        <template v-if="lesson.is_trial">
            <div class="grid gap-2">
                <Label for="trial_suitability">Is {{ lesson.learner_display_name }} a fit for your teaching?</Label>
                <select id="trial_suitability" v-model="form.trial_suitability" required class="border-input rounded-md border p-2 text-sm">
                    <option value="" disabled>Select</option>
                    <option v-for="option in suitabilityOptions" :key="option.value" :value="option.value">{{ option.label }}</option>
                </select>
                <InputError :message="form.errors.trial_suitability" />
            </div>

            <div class="grid gap-2">
                <Label for="trial_recommended_frequency">Recommended lessons a week</Label>
                <select
                    id="trial_recommended_frequency"
                    v-model="form.trial_recommended_frequency"
                    required
                    class="border-input rounded-md border p-2 text-sm"
                >
                    <option value="" disabled>Select</option>
                    <option v-for="n in 3" :key="n" :value="n">{{ n }}</option>
                </select>
                <InputError :message="form.errors.trial_recommended_frequency" />
            </div>

            <div class="grid gap-2">
                <Label for="trial_focus_areas">Focus areas for the first month</Label>
                <textarea
                    id="trial_focus_areas"
                    v-model="form.trial_focus_areas"
                    rows="3"
                    maxlength="3000"
                    required
                    class="border-input rounded-md border p-2 text-sm"
                />
                <InputError :message="form.errors.trial_focus_areas" />
            </div>
        </template>

        <div class="flex items-center gap-3">
            <Button type="submit" :disabled="form.processing" data-test="report-submit">
                <Spinner v-if="form.processing" />
                Submit the report
            </Button>
            <Button variant="outline" as-child>
                <Link :href="`/lessons/${lesson.id}`">Cancel</Link>
            </Button>
        </div>
    </form>
</template>
