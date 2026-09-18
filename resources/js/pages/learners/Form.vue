<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';

const props = defineProps<{
    learner: {
        id: number;
        display_name: string;
        is_minor: boolean;
        year_group: string | null;
        curriculum_id: number | null;
        school: string | null;
        notes: string | null;
    } | null;
    curricula: Array<{ id: number; name: string }>;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Learners', href: '/learners' },
            { title: 'Learner', href: '#' },
        ],
    },
});

const isSelf = props.learner !== null && !props.learner.is_minor;

const form = useForm({
    display_name: props.learner?.display_name ?? '',
    year_group: props.learner?.year_group ?? '',
    curriculum_id: props.learner?.curriculum_id ?? '',
    school: props.learner?.school ?? '',
    notes: props.learner?.notes ?? '',
});

function submit() {
    if (props.learner) {
        form.put(`/learners/${props.learner.id}`);
    } else {
        form.post('/learners');
    }
}
</script>

<template>
    <Head :title="learner ? 'Edit learner' : 'Add a learner'" />

    <form class="flex max-w-xl flex-col gap-6 p-4" @submit.prevent="submit">
        <h1 class="text-xl font-semibold">{{ learner ? 'Edit learner' : 'Add a learner' }}</h1>

        <div class="grid gap-2">
            <Label for="display_name">Name</Label>
            <Input id="display_name" v-model="form.display_name" :disabled="isSelf" required />
            <p v-if="isSelf" class="text-muted-foreground text-xs">Your name follows your account name.</p>
            <InputError :message="form.errors.display_name" />
        </div>

        <div class="grid gap-2">
            <Label for="curriculum_id">Curriculum</Label>
            <select id="curriculum_id" v-model="form.curriculum_id" class="border-input rounded-md border p-2 text-sm" :required="!isSelf">
                <option value="" :disabled="!isSelf">Select</option>
                <option v-for="curriculum in curricula" :key="curriculum.id" :value="curriculum.id">{{ curriculum.name }}</option>
            </select>
            <InputError :message="form.errors.curriculum_id" />
        </div>

        <div class="grid gap-2">
            <Label for="year_group">Year group</Label>
            <Input id="year_group" v-model="form.year_group" placeholder="e.g. Year 8" :required="!isSelf" />
            <InputError :message="form.errors.year_group" />
        </div>

        <div class="grid gap-2">
            <Label for="school">School (optional)</Label>
            <Input id="school" v-model="form.school" />
            <InputError :message="form.errors.school" />
        </div>

        <div class="grid gap-2">
            <Label for="notes">Notes for tutors (optional)</Label>
            <textarea id="notes" v-model="form.notes" rows="4" class="border-input rounded-md border p-2 text-sm" />
            <InputError :message="form.errors.notes" />
        </div>

        <div class="flex items-center gap-3">
            <Button type="submit" :disabled="form.processing">
                <Spinner v-if="form.processing" />
                Save
            </Button>
            <Button variant="outline" as-child>
                <Link href="/learners">Cancel</Link>
            </Button>
        </div>
    </form>
</template>
