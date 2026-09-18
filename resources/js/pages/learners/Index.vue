<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';

defineProps<{
    learners: Array<{
        id: number;
        display_name: string;
        is_minor: boolean;
        year_group: string | null;
        curriculum: string | null;
        school: string | null;
    }>;
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Learners', href: '/learners' }],
    },
});

function remove(id: number, name: string) {
    if (window.confirm(`Remove ${name}?`)) {
        router.delete(`/learners/${id}`);
    }
}
</script>

<template>
    <Head title="Learners" />

    <div class="flex flex-col gap-6 p-4">
        <div class="flex items-center justify-between">
            <h1 class="text-xl font-semibold">Your learners</h1>
            <Button as-child>
                <Link href="/learners/create">Add a learner</Link>
            </Button>
        </div>

        <p v-if="learners.length === 0" class="text-muted-foreground text-sm">
            No learners yet. Add a learner to start looking for a tutor.
        </p>

        <ul v-else class="divide-y rounded-xl border">
            <li v-for="learner in learners" :key="learner.id" class="flex items-center justify-between gap-4 p-4">
                <div class="grid gap-1">
                    <span class="font-medium">
                        {{ learner.display_name }}
                        <span v-if="!learner.is_minor" class="text-muted-foreground text-xs">(you)</span>
                    </span>
                    <span class="text-muted-foreground text-sm">
                        <template v-if="learner.curriculum || learner.year_group">
                            {{ [learner.curriculum, learner.year_group].filter(Boolean).join(' · ') }}
                        </template>
                        <template v-else>Add a curriculum and year group to search for tutors.</template>
                        <template v-if="learner.school"> · {{ learner.school }}</template>
                    </span>
                </div>
                <div class="flex items-center gap-2">
                    <Button variant="outline" size="sm" as-child>
                        <Link :href="`/learners/${learner.id}/edit`">Edit</Link>
                    </Button>
                    <Button v-if="learner.is_minor" variant="outline" size="sm" @click="remove(learner.id, learner.display_name)">
                        Remove
                    </Button>
                </div>
            </li>
        </ul>
    </div>
</template>
