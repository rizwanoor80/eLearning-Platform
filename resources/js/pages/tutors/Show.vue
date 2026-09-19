<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import PublicHeader from '@/components/PublicHeader.vue';

defineProps<{
    tutor: {
        id: number;
        name: string;
        headline: string | null;
        bio: string | null;
        intro_video_url: string | null;
        rate: string | null;
        trial_price: string | null;
        rating_avg: string | null;
        rating_count: number;
        subjects: Array<{ curriculum: string | null; subject: string | null; level_min: string; level_max: string }>;
        next_slots: Array<{ starts_at: string; label: string }>;
        reviews?: Array<{ id: number }>;
    };
    timezone: string;
}>();
</script>

<template>
    <Head :title="tutor.name" />
    <PublicHeader />

    <main class="mx-auto flex w-full max-w-3xl flex-col gap-6 p-4">
        <header class="grid gap-1">
            <h1 class="text-2xl font-semibold">{{ tutor.name }}</h1>
            <p v-if="tutor.headline" class="text-lg">{{ tutor.headline }}</p>
            <p class="text-sm">
                {{ tutor.rate }} / hour
                <span v-if="tutor.trial_price" class="text-muted-foreground">· trial lesson {{ tutor.trial_price }}</span>
            </p>
            <p class="text-muted-foreground text-sm">
                <template v-if="tutor.rating_avg">★ {{ tutor.rating_avg }} ({{ tutor.rating_count }})</template>
                <template v-else>No ratings yet</template>
            </p>
        </header>

        <section v-if="tutor.bio" class="grid gap-2">
            <h2 class="font-medium">About</h2>
            <p class="text-sm whitespace-pre-line">{{ tutor.bio }}</p>
            <a
                v-if="tutor.intro_video_url"
                :href="tutor.intro_video_url"
                target="_blank"
                rel="noopener noreferrer"
                class="text-sm underline underline-offset-4"
            >
                Watch the intro video
            </a>
        </section>

        <section class="grid gap-2">
            <h2 class="font-medium">Subjects</h2>
            <ul class="grid gap-1 text-sm">
                <li v-for="(row, index) in tutor.subjects" :key="index">
                    {{ row.subject }} · {{ row.curriculum }} — {{ row.level_min }} to {{ row.level_max }}
                </li>
            </ul>
        </section>

        <section class="grid gap-2">
            <h2 class="font-medium">Next available slots</h2>
            <p class="text-muted-foreground text-xs">Times shown in {{ timezone }}.</p>
            <ul v-if="tutor.next_slots.length" class="flex flex-wrap gap-2 text-sm">
                <li v-for="slot in tutor.next_slots" :key="slot.starts_at" class="rounded-md border px-3 py-1">{{ slot.label }}</li>
            </ul>
            <p v-else class="text-muted-foreground text-sm">No open slots right now.</p>
        </section>

        <section v-if="tutor.reviews !== undefined" class="grid gap-2">
            <h2 class="font-medium">Reviews</h2>
            <p class="text-muted-foreground text-sm">No reviews yet.</p>
        </section>
    </main>
</template>
