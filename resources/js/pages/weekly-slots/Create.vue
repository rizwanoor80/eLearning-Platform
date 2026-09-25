<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import InputError from '@/components/InputError.vue';
import TestModeBanner from '@/components/TestModeBanner.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';

const props = defineProps<{
    tutor: { id: number; name: string; rate: string | null };
    learners: Array<{ id: number; display_name: string; trial_completed: boolean }>;
    selected_learner: number | null;
    subjects: Array<{ curriculum_id: number; subject_id: number; label: string }>;
    options: Array<{ weekday: number; start_time: string; value: string; label: string; next: string | null; first_on: string | null }>;
    timezone: string;
    card: { brand: string; last4: string } | null;
    can_add_card: boolean;
    min_start: string;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Learners', href: '/learners' },
            { title: 'Weekly slot', href: '#' },
        ],
    },
});

const learnerId = ref<number | ''>(props.selected_learner ?? '');
const slot = ref('');
const subject = ref('');

const form = useForm({
    learner_id: props.selected_learner ?? '',
    tutor_id: props.tutor.id,
    curriculum_id: '' as number | '',
    subject_id: '' as number | '',
    weekday: '' as number | '',
    start_time: '',
    starts_on: props.min_start,
    ends_on: '',
});

const learner = computed(() => props.learners.find((row) => row.id === Number(learnerId.value)) ?? null);
const trialMissing = computed(() => learner.value !== null && !learner.value.trial_completed);
const chosen = computed(() => props.options.find((option) => option.value === slot.value) ?? null);
// The offered times already respect the booking lead time; start on the option's own first date so the default is never refused.
watch(chosen, (option) => {
    if (option?.first_on) {
        form.starts_on = option.first_on;
    }
});
const startMin = computed(() => chosen.value?.first_on ?? props.min_start);
const addCardHref = computed(() => `/payment-methods/create?tutor=${props.tutor.id}` + (learnerId.value ? `&learner=${learnerId.value}` : ''));

function submit() {
    const [curriculum, subj] = subject.value.split('|');
    form.transform((data) => ({
        ...data,
        learner_id: learnerId.value,
        curriculum_id: Number(curriculum),
        subject_id: Number(subj),
        weekday: chosen.value?.weekday ?? '',
        start_time: chosen.value?.start_time ?? '',
        ends_on: data.ends_on === '' ? null : data.ends_on,
    })).post('/weekly-slots');
}
</script>

<template>
    <Head title="Set up a weekly slot" />

    <div class="flex max-w-xl flex-col gap-6 p-4">
        <TestModeBanner />

        <div class="grid gap-1">
            <h1 class="text-xl font-semibold">Set up a weekly slot with {{ tutor.name }}</h1>
            <p class="text-muted-foreground text-sm">
                A standing weekly reservation: each lesson is charged to your saved card {{ tutor.rate ? `at ${tutor.rate} per hour` : '' }}
                shortly before it starts. You can skip a lesson or end the slot at any time.
            </p>
        </div>

        <div v-if="!card" class="grid gap-3 rounded-xl border p-4">
            <p class="text-sm">A saved card is needed before a weekly slot can be set up.</p>
            <Button v-if="can_add_card" as-child class="w-fit">
                <Link :href="addCardHref">Add a card</Link>
            </Button>
            <p v-else class="text-muted-foreground text-sm">Saving a card is not available yet.</p>
        </div>

        <form v-else class="flex flex-col gap-6" @submit.prevent="submit">
            <p class="text-muted-foreground text-sm">Card on file: {{ card.brand }} ending {{ card.last4 }}.</p>

            <div class="grid gap-2">
                <Label for="learner_id">Learner</Label>
                <select id="learner_id" v-model="learnerId" class="border-input rounded-md border p-2 text-sm" required>
                    <option value="" disabled>Select</option>
                    <option v-for="row in learners" :key="row.id" :value="row.id">{{ row.display_name }}</option>
                </select>
                <InputError :message="form.errors.learner_id" />
            </div>

            <p v-if="trialMissing" class="rounded-md border p-3 text-sm">
                A weekly slot is available after the trial lesson with {{ tutor.name }} is completed.
                <Link :href="`/tutors/${tutor.id}`" class="underline underline-offset-4">Book a trial lesson</Link>
            </p>

            <template v-else>
                <div class="grid gap-2">
                    <Label for="subject">Subject</Label>
                    <select id="subject" v-model="subject" class="border-input rounded-md border p-2 text-sm" required>
                        <option value="" disabled>Select</option>
                        <option v-for="row in subjects" :key="`${row.curriculum_id}|${row.subject_id}`" :value="`${row.curriculum_id}|${row.subject_id}`">
                            {{ row.label }}
                        </option>
                    </select>
                </div>

                <div class="grid gap-2">
                    <Label for="slot">Day and time</Label>
                    <select id="slot" v-model="slot" class="border-input rounded-md border p-2 text-sm" required>
                        <option value="" disabled>{{ options.length ? 'Select' : 'No weekly times are open' }}</option>
                        <option v-for="option in options" :key="option.value" :value="option.value">{{ option.label }}</option>
                    </select>
                    <p v-if="chosen?.next" class="text-muted-foreground text-xs">
                        The earliest first lesson at this time is {{ chosen.next }} ({{ timezone }}).
                    </p>
                </div>

                <div class="grid gap-2">
                    <Label for="starts_on">Start date</Label>
                    <Input id="starts_on" v-model="form.starts_on" type="date" :min="startMin" required />
                    <InputError :message="form.errors.starts_on" />
                </div>

                <div class="grid gap-2">
                    <Label for="ends_on">End date (optional)</Label>
                    <Input id="ends_on" v-model="form.ends_on" type="date" :min="form.starts_on" />
                    <InputError :message="form.errors.ends_on" />
                </div>

                <InputError :message="form.errors.slot" />

                <div class="flex items-center gap-3">
                    <Button type="submit" :disabled="form.processing || !slot || !subject">
                        <Spinner v-if="form.processing" />
                        Set up weekly slot
                    </Button>
                    <Button variant="outline" as-child>
                        <Link :href="`/tutors/${tutor.id}`">Cancel</Link>
                    </Button>
                </div>
            </template>
        </form>
    </div>
</template>
