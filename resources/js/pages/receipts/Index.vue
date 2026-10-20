<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';

defineProps<{
    receipts: Array<{
        id: number;
        paid_on: string;
        lesson_on: string;
        tutor_display_name: string;
        learner_display_name: string;
        amount: string;
        refunded: string | null;
        reference: string;
    }>;
    previous_page_url: string | null;
    next_page_url: string | null;
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Receipts', href: '/receipts' }],
    },
});
</script>

<template>
    <Head title="Receipts" />

    <div class="flex max-w-3xl flex-col gap-6 p-4">
        <h1 class="text-xl font-semibold">Receipts</h1>

        <p v-if="receipts.length === 0" class="text-muted-foreground text-sm" data-testid="no-receipts">
            You have not paid for a lesson yet. A receipt appears here once you have.
        </p>

        <ul v-else class="divide-y rounded-xl border" data-testid="receipt-list">
            <li v-for="receipt in receipts" :key="receipt.id" class="flex items-center justify-between gap-4 p-4">
                <div class="grid gap-1">
                    <span class="font-medium">
                        Lesson with {{ receipt.tutor_display_name }} on {{ receipt.lesson_on }}
                    </span>
                    <span class="text-muted-foreground text-sm">
                        {{ receipt.learner_display_name }} · paid {{ receipt.paid_on }} · {{ receipt.amount }}
                        <template v-if="receipt.refunded"> · refunded {{ receipt.refunded }}</template>
                    </span>
                    <span class="text-muted-foreground text-xs">Reference {{ receipt.reference }}</span>
                </div>
                <Link :href="`/receipts/${receipt.id}`" class="text-sm underline underline-offset-4">View receipt</Link>
            </li>
        </ul>

        <div v-if="previous_page_url || next_page_url" class="flex gap-6 text-sm">
            <Link v-if="previous_page_url" :href="previous_page_url" class="underline underline-offset-4">Newer</Link>
            <Link v-if="next_page_url" :href="next_page_url" class="underline underline-offset-4">Older</Link>
        </div>
    </div>
</template>
