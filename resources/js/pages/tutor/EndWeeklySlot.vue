<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import { Button } from '@/components/ui/button';

const props = defineProps<{
    weekly_slot: { id: number; ended: boolean; notice_given: boolean; learner: string; subject: string | null; schedule: string };
    notice_days: number;
    last_day: string;
    released_count: number;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Dashboard', href: '/tutor/dashboard' },
            { title: 'End weekly slot', href: '#' },
        ],
    },
});

const processing = ref(false);

function end() {
    processing.value = true;
    router.post(`/tutor/weekly-slots/${props.weekly_slot.id}/end`, {}, { onFinish: () => (processing.value = false) });
}
</script>

<template>
    <Head title="End weekly slot" />

    <div class="flex max-w-xl flex-col gap-6 p-4">
        <h1 class="text-xl font-semibold">End this weekly slot?</h1>

        <p class="text-sm">{{ weekly_slot.subject ?? 'Lesson' }} with {{ weekly_slot.learner }} · {{ weekly_slot.schedule }}</p>

        <p v-if="weekly_slot.ended" class="text-muted-foreground text-sm">This weekly slot has already ended.</p>
        <p v-else-if="weekly_slot.notice_given" class="text-muted-foreground text-sm">
            Notice has already been given. The slot runs through {{ last_day }}.
        </p>

        <template v-else>
            <p class="text-sm">
                You give {{ notice_days }} days' notice. The slot keeps running through {{ last_day }}, and the parent is told by email.
                <template v-if="released_count > 0">
                    {{ released_count }} later {{ released_count === 1 ? 'lesson is' : 'lessons are' }} released without charge; no strike is
                    recorded.
                </template>
            </p>

            <div class="flex items-center gap-3">
                <Button :disabled="processing" @click="end">Give notice and end</Button>
                <Button variant="outline" as-child>
                    <Link href="/tutor/dashboard">Keep it</Link>
                </Button>
            </div>
        </template>
    </div>
</template>
