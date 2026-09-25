<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import { Button } from '@/components/ui/button';

const props = defineProps<{
    weekly_slot: { id: number; ended: boolean; learner: string; tutor: string; subject: string | null; schedule: string };
    reserved_count: number;
    confirmed: Array<{ id: number; starts_at: string; cancel_window_hours: number }>;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Learners', href: '/learners' },
            { title: 'End weekly slot', href: '#' },
        ],
    },
});

const processing = ref(false);

function end() {
    processing.value = true;
    router.post(`/weekly-slots/${props.weekly_slot.id}/end`, {}, { onFinish: () => (processing.value = false) });
}
</script>

<template>
    <Head title="End weekly slot" />

    <div class="flex max-w-xl flex-col gap-6 p-4">
        <h1 class="text-xl font-semibold">End this weekly slot?</h1>

        <p class="text-sm">
            {{ weekly_slot.subject ?? 'Lesson' }} with {{ weekly_slot.tutor }} for {{ weekly_slot.learner }} · {{ weekly_slot.schedule }}
        </p>

        <p v-if="weekly_slot.ended" class="text-muted-foreground text-sm">This weekly slot has already ended.</p>

        <template v-else>
            <p class="text-sm">
                Ending the slot takes effect straight away.
                <template v-if="reserved_count > 0">
                    {{ reserved_count }} upcoming {{ reserved_count === 1 ? 'lesson that has' : 'lessons that have' }} not been charged yet
                    will be released, with no charge.
                </template>
                <template v-else>There are no upcoming lessons that are still to be charged.</template>
            </p>

            <div v-if="confirmed.length" class="grid gap-2">
                <p class="text-sm font-medium">Already charged — these lessons stay booked</p>
                <ul class="divide-y rounded-xl border text-sm">
                    <li v-for="lesson in confirmed" :key="lesson.id" class="p-3">
                        {{ lesson.starts_at }}
                        <span class="text-muted-foreground block text-xs">
                            Cancel it from your dashboard: {{ lesson.cancel_window_hours }} hours or more ahead is refunded in full; closer than that is not refunded and the tutor is paid.
                        </span>
                    </li>
                </ul>
                <Link href="/dashboard" class="text-sm underline underline-offset-4">Go to your dashboard</Link>
            </div>

            <div class="flex items-center gap-3">
                <Button :disabled="processing" @click="end">End weekly slot</Button>
                <Button variant="outline" as-child>
                    <Link href="/learners">Keep it</Link>
                </Button>
            </div>
        </template>
    </div>
</template>
