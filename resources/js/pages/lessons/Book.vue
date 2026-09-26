<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import InputError from '@/components/InputError.vue';
import TestModeBanner from '@/components/TestModeBanner.vue';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';

type SubjectRow = { curriculum_id: number; subject_id: number; label: string };

const props = defineProps<{
    tutor: { id: number; name: string };
    // Type and price are computed on the server per learner (BookLesson's own rule); the browser only shows them.
    learners: Array<{ id: number; display_name: string; type: 'trial' | 'regular'; type_label: string; price: string | null; preselect: SubjectRow | null }>;
    selected_learner: number | null;
    subjects: SubjectRow[];
    starts_at: string;
    slot_label: string;
    slot_available: boolean;
    alternatives: Array<{ starts_at: string; label: string }>;
    timezone: string;
    can_pay: boolean;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Tutors', href: '/tutors' },
            { title: 'Book a lesson', href: '#' },
        ],
    },
});

const key = (row: SubjectRow) => `${row.curriculum_id}|${row.subject_id}`;

const learnerId = ref<number | ''>(props.selected_learner ?? '');
const learner = computed(() => props.learners.find((row) => row.id === Number(learnerId.value)) ?? null);
const initial = props.learners.find((row) => row.id === props.selected_learner)?.preselect ?? null;
const subject = ref(initial ? key(initial) : '');

// Changing the learner re-derives the subject preselect from that learner's server-side curriculum match.
watch(learnerId, () => {
    subject.value = learner.value?.preselect ? key(learner.value.preselect) : '';
});

const form = useForm({
    learner_id: '' as number | '',
    tutor_id: props.tutor.id,
    curriculum_id: '' as number | '',
    subject_id: '' as number | '',
    starts_at: props.starts_at,
});

// `slot` is a server-side form error key that is not one of the form's fields.
const slotError = computed(() => (form.errors as Record<string, string | undefined>).slot);

const canSubmit = computed(() => props.can_pay && props.slot_available && learner.value !== null && subject.value !== '' && learner.value.price !== null);

function submit() {
    const [curriculum, subj] = subject.value.split('|');
    form.transform((data) => ({
        ...data,
        learner_id: learnerId.value,
        curriculum_id: Number(curriculum),
        subject_id: Number(subj),
    })).post('/lessons');
}
</script>

<template>
    <Head title="Book a lesson" />

    <div class="flex max-w-xl flex-col gap-6 p-4">
        <TestModeBanner />

        <div class="grid gap-1">
            <h1 class="text-xl font-semibold">Book a lesson with {{ tutor.name }}</h1>
            <p class="text-sm">
                {{ slot_label }} <span class="text-muted-foreground">({{ timezone }}) · 60 minutes</span>
            </p>
        </div>

        <div v-if="!slot_available" class="grid gap-3 rounded-xl border p-4">
            <p class="text-sm">That time is no longer available. Choose another:</p>
            <ul v-if="alternatives.length" class="flex flex-wrap gap-2 text-sm">
                <li v-for="slot in alternatives" :key="slot.starts_at">
                    <Link :href="`/tutors/${tutor.id}/book?starts_at=${encodeURIComponent(slot.starts_at)}`" class="rounded-md border px-3 py-1 underline-offset-4 hover:underline">
                        {{ slot.label }}
                    </Link>
                </li>
            </ul>
            <p v-else class="text-muted-foreground text-sm">No open slots right now.</p>
        </div>

        <p v-if="!can_pay" class="rounded-md border p-3 text-sm">Booking is not available yet.</p>

        <p v-if="learners.length === 0" class="rounded-md border p-3 text-sm">
            Add a learner before booking a lesson.
            <Link href="/learners/create" class="underline underline-offset-4">Add a learner</Link>
        </p>

        <form v-else class="flex flex-col gap-6" @submit.prevent="submit">
            <div class="grid gap-2">
                <Label for="learner_id">Learner</Label>
                <select id="learner_id" v-model="learnerId" class="border-input rounded-md border p-2 text-sm" required>
                    <option value="" disabled>Select</option>
                    <option v-for="row in learners" :key="row.id" :value="row.id">{{ row.display_name }}</option>
                </select>
            </div>

            <div class="grid gap-2">
                <Label for="subject">Subject</Label>
                <select id="subject" v-model="subject" class="border-input rounded-md border p-2 text-sm" required>
                    <option value="" disabled>Select</option>
                    <option v-for="row in subjects" :key="key(row)" :value="key(row)">{{ row.label }}</option>
                </select>
            </div>

            <div v-if="learner" class="grid gap-1 rounded-xl border p-4">
                <p class="font-medium">{{ learner.type_label }}</p>
                <p class="text-sm">{{ learner.price ?? 'No price set for this tutor' }}</p>
                <p class="text-muted-foreground text-xs">
                    <template v-if="learner.type === 'trial'">The first lesson with a tutor is a discounted trial.</template>
                    <template v-else>Charged at the tutor's hourly rate.</template>
                </p>
            </div>

            <InputError :message="slotError" />
            <InputError :message="form.errors.starts_at" />

            <div class="flex items-center gap-3">
                <Button type="submit" :disabled="form.processing || !canSubmit">
                    <Spinner v-if="form.processing" />
                    Confirm and pay
                </Button>
                <Button variant="outline" as-child>
                    <Link :href="`/tutors/${tutor.id}`">Cancel</Link>
                </Button>
            </div>
        </form>
    </div>
</template>
