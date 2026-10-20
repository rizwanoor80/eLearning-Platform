<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';

type RefundState = 'returned' | 'pending' | 'refused';

defineProps<{
    receipt: {
        id: number;
        number: string;
        paid_at: string;
        lesson_at: string;
        duration_minutes: number;
        subject: string | null;
        timezone: string;
        currency: string;
        payer_name: string;
        tutor_display_name: string;
        learner_display_name: string;
        amount: string;
        net: string;
        reference: string;
        refunds: Array<{ date: string; amount: string; state: RefundState }>;
    };
}>();

const refundNote: Record<RefundState, string> = {
    returned: '(returned to your card)',
    pending: '(being returned to your card)',
    refused: '(could not be returned to your card; please contact support)',
};

function print() {
    window.print();
}

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Receipts', href: '/receipts' },
            { title: 'Receipt', href: '#' },
        ],
    },
});
</script>

<template>
    <Head title="Receipt" />

    <div class="flex max-w-xl flex-col gap-6 p-4">
        <div class="flex items-center gap-3 print:hidden">
            <Button type="button" data-testid="print-receipt" @click="print">Print receipt</Button>
            <Button variant="outline" as-child>
                <Link href="/receipts">All receipts</Link>
            </Button>
        </div>

        <article class="grid gap-4 rounded-xl border p-6 print:border-0 print:p-0" data-testid="receipt">
            <header class="grid gap-1">
                <h1 class="text-xl font-semibold">Receipt {{ receipt.number }}</h1>
                <p class="text-muted-foreground text-sm">Paid {{ receipt.paid_at }} ({{ receipt.timezone }})</p>
            </header>

            <dl class="grid grid-cols-[auto_1fr] gap-x-6 gap-y-2 text-sm">
                <dt class="text-muted-foreground">Paid by</dt>
                <dd>{{ receipt.payer_name }}</dd>
                <dt class="text-muted-foreground">Tutor</dt>
                <dd>{{ receipt.tutor_display_name }}</dd>
                <dt class="text-muted-foreground">Learner</dt>
                <dd>{{ receipt.learner_display_name }}</dd>
                <dt class="text-muted-foreground">Lesson</dt>
                <dd>
                    {{ receipt.subject ?? 'Lesson' }}, {{ receipt.lesson_at }} · {{ receipt.duration_minutes }} min
                </dd>
                <dt class="text-muted-foreground">Payment reference</dt>
                <dd class="break-all">{{ receipt.reference }}</dd>
            </dl>

            <table class="w-full text-sm">
                <tbody>
                    <tr>
                        <td class="py-1">Lesson fee</td>
                        <td class="py-1 text-end">{{ receipt.amount }}</td>
                    </tr>
                    <tr v-for="(refund, index) in receipt.refunds" :key="index" data-testid="refund-line">
                        <td class="py-1">
                            Refund, {{ refund.date }}
                            <span class="text-muted-foreground text-xs">
                                {{ refundNote[refund.state] }}
                            </span>
                        </td>
                        <td class="py-1 text-end">-{{ refund.amount }}</td>
                    </tr>
                    <tr class="border-t font-semibold">
                        <td class="py-1">Total paid (refunds returned to your card deducted)</td>
                        <td class="py-1 text-end">{{ receipt.net }}</td>
                    </tr>
                </tbody>
            </table>
        </article>
    </div>
</template>

<style>
/* Print only the receipt: hide the app sidebar, header and footer around it. */
@media print {
    body:has([data-testid='receipt']) [data-slot='sidebar'],
    body:has([data-testid='receipt']) main > header,
    body:has([data-testid='receipt']) main > footer {
        display: none !important;
    }
}
</style>
