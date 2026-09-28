<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import PublicFooter from '@/components/PublicFooter.vue';
import PublicHeader from '@/components/PublicHeader.vue';
import ReportAbuseButton from '@/components/ReportAbuseButton.vue';

const props = defineProps<{
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
        reviews?: {
            data: Array<{ rating: number; comment: string | null; reviewer: string; published_at: string | null; date_label: string | null }>;
            current_page: number;
            last_page: number;
        };
    };
    timezone: string;
    can_set_up_weekly: boolean;
    can_report_abuse: boolean;
    abuse_report_reasons: Array<{ value: string; label: string }>;
}>();

// A guest and a parent get bookable slots (a guest is sent to sign in and returned to the booking page);
// a tutor or an admin sees the times as plain text.
const page = usePage();
const canBook = computed(() => !page.props.auth?.user || props.can_set_up_weekly);

// The link carries the slot as a UTC instant, seconds precision, percent-encoded (no bare "+" in a query string).
function bookHref(startsAt: string): string {
    const utc = new Date(startsAt).toISOString().replace(/\.\d{3}Z$/, 'Z');

    return `/tutors/${props.tutor.id}/book?starts_at=${encodeURIComponent(utc)}`;
}

// A distinct query-string name (reviews_page) so this paginator never collides with another on the page.
function goToReviewsPage(targetPage: number) {
    router.get(`/tutors/${props.tutor.id}`, { reviews_page: targetPage }, { preserveScroll: true, preserveState: true, only: ['tutor'] });
}
</script>

<template>
    <Head :title="tutor.name" />
    <PublicHeader />

    <main class="mx-auto flex w-full max-w-3xl flex-col gap-6 p-4">
        <header class="grid gap-1">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <h1 class="text-2xl font-semibold">{{ tutor.name }}</h1>
                <ReportAbuseButton v-if="can_report_abuse" :post-url="`/tutors/${tutor.id}/abuse-reports`" :reasons="abuse_report_reasons" />
            </div>
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
                <li v-for="slot in tutor.next_slots" :key="slot.starts_at" class="rounded-md border px-3 py-1">
                    <Link v-if="canBook" :href="bookHref(slot.starts_at)" class="underline-offset-4 hover:underline">{{ slot.label }}</Link>
                    <template v-else>{{ slot.label }}</template>
                </li>
            </ul>
            <p v-else class="text-muted-foreground text-sm">No open slots right now.</p>
        </section>

        <section v-if="can_set_up_weekly" class="grid gap-2">
            <h2 class="font-medium">Weekly lessons</h2>
            <p class="text-muted-foreground text-sm">Once your trial lesson with {{ tutor.name }} is complete you can reserve a standing weekly slot.</p>
            <Link :href="`/weekly-slots/create?tutor=${tutor.id}`" class="w-fit text-sm underline underline-offset-4">Set up a weekly slot</Link>
        </section>

        <section v-if="tutor.reviews !== undefined" class="grid gap-3" data-test="reviews">
            <h2 class="font-medium">Reviews</h2>

            <p v-if="tutor.reviews.data.length === 0" class="text-muted-foreground text-sm">No reviews yet.</p>

            <template v-else>
                <ul class="grid gap-3">
                    <li v-for="(review, index) in tutor.reviews.data" :key="index" class="rounded-xl border p-4 text-sm" data-test="review">
                        <div class="flex items-center justify-between gap-2">
                            <span>★ {{ review.rating }} out of 5</span>
                            <span class="text-muted-foreground text-xs">{{ review.reviewer }} · {{ review.date_label }}</span>
                        </div>
                        <p v-if="review.comment" class="mt-2 whitespace-pre-line">{{ review.comment }}</p>
                    </li>
                </ul>

                <div v-if="tutor.reviews.last_page > 1" class="flex items-center gap-3 text-sm" data-test="reviews-pagination">
                    <button
                        type="button"
                        class="underline-offset-4 disabled:text-muted-foreground disabled:no-underline hover:underline"
                        :disabled="tutor.reviews.current_page <= 1"
                        data-test="reviews-prev"
                        @click="goToReviewsPage(tutor.reviews.current_page - 1)"
                    >
                        Previous
                    </button>
                    <span class="text-muted-foreground text-xs">Page {{ tutor.reviews.current_page }} of {{ tutor.reviews.last_page }}</span>
                    <button
                        type="button"
                        class="underline-offset-4 disabled:text-muted-foreground disabled:no-underline hover:underline"
                        :disabled="tutor.reviews.current_page >= tutor.reviews.last_page"
                        data-test="reviews-next"
                        @click="goToReviewsPage(tutor.reviews.current_page + 1)"
                    >
                        Next
                    </button>
                </div>
            </template>
        </section>
    </main>

    <PublicFooter />
</template>
