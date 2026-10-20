<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import Heading from '@/components/Heading.vue';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { edit as editPayout } from '@/routes/payout';
import { dashboard as tutorDashboard } from '@/routes/tutor';

type Buckets = {
    pending: string;
    on_hold: string;
    available: string;
    processing: string;
    paid_to_date: string;
    total: string;
    pending_available_on: string | null;
};

defineProps<{
    buckets: Buckets | null;
    payout_problem: string | null;
    payouts: Array<{ id: number; paid_at: string; amount: string; reference: string }>;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Dashboard', href: tutorDashboard() },
            { title: 'Earnings', href: '/tutor/earnings' },
        ],
    },
});

const definitions = [
    { key: 'pending', title: 'Pending', text: 'Your share of lessons that are booked, under way or taught, until the payment is released to you, and for a short while after, while the family can still dispute the lesson.' },
    { key: 'on_hold', title: 'On hold', text: 'Your share of a lesson that is in a dispute. It is paid or returned when the dispute is settled.' },
    { key: 'available', title: 'Available', text: 'Released to you, past the time the family can dispute the lesson, and not yet in a payout. It is included in the next payout once it reaches the minimum.' },
    { key: 'processing', title: 'Processing', text: 'In a payout we are preparing or have sent, and not yet confirmed as paid.' },
    { key: 'paid_to_date', title: 'Paid to date', text: 'Paid out to you, with the payment reference.' },
] as const;
</script>

<template>
    <Head title="Earnings" />

    <div class="space-y-8 px-4 py-6">
        <Heading title="Earnings" description="What you have earned, and where each part of it is on its way to you." />

        <p v-if="buckets === null" class="text-muted-foreground text-sm">You have no lessons yet, so there is nothing to show.</p>

        <template v-else>
            <p class="text-sm" data-testid="earnings-total">
                Total earned so far: <span class="font-semibold">{{ buckets.total }}</span>
            </p>

            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                <Card v-for="definition in definitions" :key="definition.key" :data-testid="`bucket-${definition.key}`">
                    <CardHeader>
                        <CardDescription>{{ definition.title }}</CardDescription>
                        <CardTitle class="text-2xl">{{ buckets[definition.key] }}</CardTitle>
                    </CardHeader>
                    <CardContent class="text-muted-foreground space-y-1 text-sm">
                        <p>{{ definition.text }}</p>
                        <p v-if="definition.key === 'pending' && buckets.pending_available_on" data-testid="pending-available-on">
                            Part of this becomes available on {{ buckets.pending_available_on }}.
                        </p>
                    </CardContent>
                </Card>
            </div>
        </template>

        <p v-if="payout_problem" class="text-sm text-amber-700 dark:text-amber-400" role="status" data-testid="payout-problem">
            You cannot be paid yet: {{ payout_problem }}
            <Link :href="editPayout()" class="underline">Set up your payout method</Link>
        </p>

        <section class="space-y-2" data-testid="payout-history">
            <h2 class="text-lg font-semibold">Payout history</h2>
            <p v-if="payouts.length === 0" class="text-muted-foreground text-sm">No payouts yet.</p>
            <ul v-else class="divide-y text-sm">
                <li v-for="payout in payouts" :key="payout.id" class="flex justify-between py-2">
                    <span>{{ payout.paid_at }} · {{ payout.reference }}</span>
                    <span class="font-medium">{{ payout.amount }}</span>
                </li>
            </ul>
        </section>
    </div>
</template>
