<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';

defineProps<{
    requests: Array<{
        id: number;
        learner: string;
        curriculum: string;
        subject: string;
        year_group: string;
        goals: string;
        status: string;
        created_at: string | null;
        suggestions: Array<{ id: number; name: string; headline: string | null; rate: string | null; trial_price: string | null }>;
    }>;
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Match requests', href: '/match-requests' }],
    },
});
</script>

<template>
    <Head title="Match requests" />

    <div class="flex flex-col gap-6 p-4">
        <div class="flex items-center justify-between">
            <h1 class="text-xl font-semibold">Your match requests</h1>
            <Button as-child>
                <Link href="/match-requests/create">New request</Link>
            </Button>
        </div>

        <p v-if="requests.length === 0" class="text-muted-foreground text-sm">No requests yet.</p>

        <ul class="grid gap-4">
            <li v-for="request in requests" :key="request.id" class="grid gap-2 rounded-xl border p-4">
                <div class="flex flex-wrap items-baseline justify-between gap-2">
                    <span class="font-medium">{{ request.learner }} · {{ request.subject }} · {{ request.curriculum }} · {{ request.year_group }}</span>
                    <span class="text-muted-foreground text-sm capitalize">{{ request.status }}</span>
                </div>
                <p class="text-sm">{{ request.goals }}</p>
                <div v-if="request.suggestions.length" class="grid gap-2">
                    <h2 class="text-sm font-medium">Suggested tutors</h2>
                    <ul class="grid gap-1 text-sm">
                        <li v-for="tutor in request.suggestions" :key="tutor.id">
                            <Link :href="`/tutors/${tutor.id}`" class="underline underline-offset-4">{{ tutor.name }}</Link>
                            <span v-if="tutor.headline"> — {{ tutor.headline }}</span>
                            <span class="text-muted-foreground"> · {{ tutor.rate }} / hour, trial {{ tutor.trial_price }}</span>
                        </li>
                    </ul>
                </div>
            </li>
        </ul>
    </div>
</template>
