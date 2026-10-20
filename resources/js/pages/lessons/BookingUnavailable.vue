<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';

defineProps<{
    tutor: { id: number; name: string };
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Tutors', href: '/tutors' },
            { title: 'Book a lesson', href: '#' },
        ],
    },
});
</script>

<template>
    <Head title="Booking is not available" />

    <div class="flex max-w-xl flex-col gap-6 p-4">
        <div class="grid gap-2">
            <h1 class="text-xl font-semibold">Booking is not available yet</h1>
            <p class="text-sm" data-test="booking-unavailable">
                We cannot take payments right now, so lessons cannot be booked. Nothing has been charged. Please try again later.
            </p>
        </div>

        <Link :href="`/tutors/${tutor.id}`" class="text-sm underline underline-offset-4">Back to {{ tutor.name }}</Link>
    </div>
</template>
