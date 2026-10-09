<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import OnboardingChecklist from '@/components/tutor/OnboardingChecklist.vue';
import type { ChecklistGroup } from '@/components/tutor/OnboardingChecklist.vue';
import { Button } from '@/components/ui/button';

const props = defineProps<{
    checklist: ChecklistGroup[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Get started',
                href: '/get-started',
            },
        ],
    },
});

const learnerAdded = props.checklist.find((group) => group.key === 'required')?.complete ?? false;
</script>

<template>
    <Head title="Get started" />

    <div class="flex max-w-xl flex-col gap-6 p-4">
        <div class="grid gap-2">
            <h1 class="text-xl font-semibold">Welcome to trusTutor</h1>
            <p class="text-muted-foreground text-sm">
                Your email is verified. Here is what you need to book your first lesson, and what you can leave for later.
            </p>
        </div>

        <OnboardingChecklist :groups="props.checklist" title="Your checklist" />

        <div class="flex flex-wrap items-center gap-4">
            <Button v-if="!learnerAdded" as-child>
                <Link href="/learners/create">Add a learner</Link>
            </Button>
            <Button v-else as-child>
                <Link href="/tutors">Find a tutor</Link>
            </Button>
            <Link href="/dashboard" class="text-sm underline underline-offset-4">Go to my dashboard</Link>
        </div>
    </div>
</template>
