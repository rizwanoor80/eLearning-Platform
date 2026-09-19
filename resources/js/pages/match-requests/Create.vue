<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';

const props = defineProps<{
    learners: Array<{ id: number; display_name: string; curriculum_id: number | null; year_group: string | null }>;
    selectedLearner: number | null;
    curricula: Array<{ id: number; name: string }>;
    subjects: Array<{ id: number; name: string }>;
    budgetTiers: Array<{ value: string; label: string }>;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Match requests', href: '/match-requests' },
            { title: 'New request', href: '#' },
        ],
    },
});

const first = props.learners.find((l) => l.id === props.selectedLearner) ?? props.learners[0];

const form = useForm({
    learner_id: first?.id ?? '',
    curriculum_id: first?.curriculum_id ?? '',
    subject_id: '',
    year_group: first?.year_group ?? '',
    goals: '',
    preferred_times: '',
    budget_tier: 'mid',
});

// Choosing another learner refills the curriculum and year group from them;
// the parent can still change both before sending.
function pickLearner() {
    const learner = props.learners.find((l) => l.id === Number(form.learner_id));
    form.curriculum_id = learner?.curriculum_id ?? '';
    form.year_group = learner?.year_group ?? '';
}

function submit() {
    form.post('/match-requests');
}
</script>

<template>
    <Head title="Request a match" />

    <div class="flex max-w-xl flex-col gap-6 p-4">
        <h1 class="text-xl font-semibold">Request a tutor match</h1>

        <p v-if="learners.length === 0" class="text-sm">
            Add a learner first so we know who the lessons are for.
            <Link href="/learners/create" class="underline underline-offset-4">Add a learner</Link>
        </p>

        <form v-else class="flex flex-col gap-6" @submit.prevent="submit">
            <div class="grid gap-2">
                <Label for="learner_id">Learner</Label>
                <select id="learner_id" v-model="form.learner_id" class="border-input rounded-md border p-2 text-sm" @change="pickLearner">
                    <option v-for="learner in learners" :key="learner.id" :value="learner.id">{{ learner.display_name }}</option>
                </select>
                <InputError :message="form.errors.learner_id" />
            </div>
            <div class="grid gap-2">
                <Label for="curriculum_id">Curriculum</Label>
                <select id="curriculum_id" v-model="form.curriculum_id" class="border-input rounded-md border p-2 text-sm" required>
                    <option value="" disabled>Select</option>
                    <option v-for="curriculum in curricula" :key="curriculum.id" :value="curriculum.id">{{ curriculum.name }}</option>
                </select>
                <InputError :message="form.errors.curriculum_id" />
            </div>
            <div class="grid gap-2">
                <Label for="subject_id">Subject</Label>
                <select id="subject_id" v-model="form.subject_id" class="border-input rounded-md border p-2 text-sm" required>
                    <option value="" disabled>Select</option>
                    <option v-for="subject in subjects" :key="subject.id" :value="subject.id">{{ subject.name }}</option>
                </select>
                <InputError :message="form.errors.subject_id" />
            </div>
            <div class="grid gap-2">
                <Label for="year_group">Year group</Label>
                <Input id="year_group" v-model="form.year_group" required placeholder="e.g. Year 8" />
                <InputError :message="form.errors.year_group" />
            </div>
            <div class="grid gap-2">
                <Label for="goals">What would you like help with?</Label>
                <textarea id="goals" v-model="form.goals" rows="4" required class="border-input rounded-md border p-2 text-sm" />
                <InputError :message="form.errors.goals" />
            </div>
            <div class="grid gap-2">
                <Label for="preferred_times">Preferred days and times (optional)</Label>
                <Input id="preferred_times" v-model="form.preferred_times" placeholder="e.g. weekday evenings" />
                <InputError :message="form.errors.preferred_times" />
            </div>
            <div class="grid gap-2">
                <Label for="budget_tier">Budget</Label>
                <select id="budget_tier" v-model="form.budget_tier" class="border-input rounded-md border p-2 text-sm">
                    <option v-for="tier in budgetTiers" :key="tier.value" :value="tier.value">{{ tier.label }}</option>
                </select>
                <InputError :message="form.errors.budget_tier" />
            </div>
            <div class="flex items-center gap-3">
                <Button type="submit" :disabled="form.processing">
                    <Spinner v-if="form.processing" />
                    Send request
                </Button>
                <Button variant="outline" as-child>
                    <Link href="/match-requests">Cancel</Link>
                </Button>
            </div>
        </form>
    </div>
</template>
