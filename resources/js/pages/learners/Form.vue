<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed, watch } from 'vue';
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
        year_group_id: number | null;
        year_group: string | null;
        year_group_is_legacy: boolean;
        curriculum_id: number | null;
        school: string | null;
        notes: string | null;
    } | null;
    curricula: Array<{ id: number; name: string }>;
    yearGroups: Array<{ id: number; curriculum_id: number; label: string }>;
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
    year_group_id: props.learner?.year_group_id ?? '',
    curriculum_id: props.learner?.curriculum_id ?? '',
    school: props.learner?.school ?? '',
    notes: props.learner?.notes ?? '',
});

// Year groups belong to a curriculum: only the chosen curriculum's are offered,
// and a year group left over from another curriculum is cleared.
const groupsForCurriculum = computed(() => props.yearGroups.filter((group) => group.curriculum_id === Number(form.curriculum_id)));

watch(
    () => form.curriculum_id,
    () => {
        if (!groupsForCurriculum.value.some((group) => group.id === Number(form.year_group_id))) {
            form.year_group_id = '';
        }
    },
);

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
            <Label for="year_group_id">Year group</Label>
            <select
                id="year_group_id"
                v-model="form.year_group_id"
                class="border-input rounded-md border p-2 text-sm"
                :required="!isSelf"
                :disabled="form.curriculum_id === ''"
            >
                <option value="" :disabled="!isSelf">{{ form.curriculum_id === '' ? 'Choose a curriculum first' : 'Select' }}</option>
                <option v-for="group in groupsForCurriculum" :key="group.id" :value="group.id">{{ group.label }}</option>
            </select>
            <p v-if="learner?.year_group_is_legacy" class="text-muted-foreground text-xs">
                We could not match “{{ learner.year_group }}” to our list — please choose a year group.
            </p>
            <InputError :message="form.errors.year_group_id" />
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
