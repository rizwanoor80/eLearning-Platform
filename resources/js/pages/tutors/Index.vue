<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, reactive, watch } from 'vue';
import PublicFooter from '@/components/PublicFooter.vue';
import PublicHeader from '@/components/PublicHeader.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type Card = {
    id: number;
    name: string;
    headline: string | null;
    rate: string | null;
    trial_price: string | null;
    rating_avg: string | null;
    rating_count: number;
    subjects: Array<{ curriculum: string | null; subject: string | null; level_min: string; level_max: string }>;
    next_slot: { starts_at: string; label: string } | null;
};

const props = defineProps<{
    tutors: Card[];
    total: number;
    page: number;
    lastPage: number;
    timezone: string;
    filters: {
        learner: number | null;
        curriculum_id: number | null;
        subject_id: number | null;
        year_group_id: number | null;
        min_price: string | null;
        max_price: string | null;
        day: number | null;
        time_of_day: string | null;
        min_rating: string | null;
        sort: string;
    };
    curricula: Array<{ id: number; name: string }>;
    subjects: Array<{ id: number; name: string }>;
    learners: Array<{ id: number; display_name: string }>;
    yearGroups: Array<{ id: number; curriculum_id: number; label: string }>;
}>();

const form = reactive({
    learner: props.filters.learner ?? '',
    curriculum_id: props.filters.curriculum_id ?? '',
    subject_id: props.filters.subject_id ?? '',
    year_group_id: props.filters.year_group_id ?? '',
    min_price: props.filters.min_price ?? '',
    max_price: props.filters.max_price ?? '',
    day: props.filters.day ?? '',
    time_of_day: props.filters.time_of_day ?? '',
    min_rating: props.filters.min_rating ?? '',
    sort: props.filters.sort,
});

// A year group only applies inside its own curriculum: with "Any" curriculum the
// select is disabled, and one left over from another curriculum is cleared.
const groupsForCurriculum = computed(() => props.yearGroups.filter((group) => group.curriculum_id === Number(form.curriculum_id)));

watch(
    () => form.curriculum_id,
    () => {
        if (!groupsForCurriculum.value.some((group) => group.id === Number(form.year_group_id))) {
            form.year_group_id = '';
        }
    },
);

function query(page = 1) {
    const params: Record<string, string | number> = {};
    for (const [key, value] of Object.entries(form)) {
        if (value !== '' && value !== null) {
            params[key] = value as string | number;
        }
    }
    if (page > 1) {
        params.page = page;
    }
    return params;
}

function search(page = 1) {
    router.get('/tutors', query(page), { preserveScroll: true });
}
</script>

<template>
    <Head title="Find a tutor" />
    <PublicHeader />

    <main class="mx-auto flex w-full max-w-5xl flex-col gap-6 p-4">
        <h1 class="text-xl font-semibold">Find a tutor</h1>
        <ul v-if="Object.keys($page.props.errors ?? {}).length" class="text-destructive grid gap-1 text-sm" role="alert">
            <li v-for="(message, field) in $page.props.errors" :key="field">{{ message }}</li>
        </ul>
        <p v-if="$page.props.features.match_requests" class="text-sm">
            Not sure who to pick?
            <Link href="/match-requests/create" class="underline underline-offset-4">Ask us to suggest tutors</Link>
        </p>

        <form class="grid gap-4 rounded-xl border p-4 sm:grid-cols-2 lg:grid-cols-4" @submit.prevent="search()">
            <div v-if="learners.length" class="grid gap-2">
                <Label for="learner">For</Label>
                <select id="learner" v-model="form.learner" class="border-input rounded-md border p-2 text-sm">
                    <option value="">Anyone</option>
                    <option v-for="learner in learners" :key="learner.id" :value="learner.id">{{ learner.display_name }}</option>
                </select>
            </div>
            <div class="grid gap-2">
                <Label for="curriculum_id">Curriculum</Label>
                <select id="curriculum_id" v-model="form.curriculum_id" class="border-input rounded-md border p-2 text-sm">
                    <option value="">Any</option>
                    <option v-for="curriculum in curricula" :key="curriculum.id" :value="curriculum.id">{{ curriculum.name }}</option>
                </select>
            </div>
            <div class="grid gap-2">
                <Label for="subject_id">Subject</Label>
                <select id="subject_id" v-model="form.subject_id" class="border-input rounded-md border p-2 text-sm">
                    <option value="">Any</option>
                    <option v-for="subject in subjects" :key="subject.id" :value="subject.id">{{ subject.name }}</option>
                </select>
            </div>
            <div class="grid gap-2">
                <Label for="year_group_id">Year group</Label>
                <select id="year_group_id" v-model="form.year_group_id" class="border-input rounded-md border p-2 text-sm" :disabled="form.curriculum_id === ''">
                    <option value="">{{ form.curriculum_id === '' ? 'Choose a curriculum first' : 'Any' }}</option>
                    <option v-for="group in groupsForCurriculum" :key="group.id" :value="group.id">{{ group.label }}</option>
                </select>
            </div>
            <div class="grid gap-2">
                <Label for="min_price">Min price / hour</Label>
                <Input id="min_price" v-model="form.min_price" inputmode="decimal" placeholder="80" />
            </div>
            <div class="grid gap-2">
                <Label for="max_price">Max price / hour</Label>
                <Input id="max_price" v-model="form.max_price" inputmode="decimal" placeholder="200" />
            </div>
            <div class="grid gap-2">
                <Label for="day">Day</Label>
                <select id="day" v-model="form.day" class="border-input rounded-md border p-2 text-sm">
                    <option value="">Any</option>
                    <option :value="0">Sunday</option>
                    <option :value="1">Monday</option>
                    <option :value="2">Tuesday</option>
                    <option :value="3">Wednesday</option>
                    <option :value="4">Thursday</option>
                    <option :value="5">Friday</option>
                    <option :value="6">Saturday</option>
                </select>
            </div>
            <div class="grid gap-2">
                <Label for="time_of_day">Time</Label>
                <select id="time_of_day" v-model="form.time_of_day" class="border-input rounded-md border p-2 text-sm">
                    <option value="">Any</option>
                    <option value="morning">Morning</option>
                    <option value="afternoon">Afternoon</option>
                    <option value="evening">Evening</option>
                </select>
            </div>
            <div class="grid gap-2">
                <Label for="min_rating">Minimum rating</Label>
                <select id="min_rating" v-model="form.min_rating" class="border-input rounded-md border p-2 text-sm">
                    <option value="">Any</option>
                    <option value="3">3+</option>
                    <option value="4">4+</option>
                    <option value="4.5">4.5+</option>
                </select>
            </div>
            <div class="grid gap-2">
                <Label for="sort">Sort by</Label>
                <select id="sort" v-model="form.sort" class="border-input rounded-md border p-2 text-sm">
                    <option value="rating">Rating</option>
                    <option value="price">Price</option>
                </select>
            </div>
            <div class="flex items-end">
                <Button type="submit">Search</Button>
            </div>
        </form>

        <p class="text-muted-foreground text-sm">
            {{ total }} tutor{{ total === 1 ? '' : 's' }} with open slots in the next two weeks. Times shown in {{ timezone }}.
        </p>

        <ul class="grid gap-4">
            <li v-for="tutor in tutors" :key="tutor.id" class="grid gap-2 rounded-xl border p-4">
                <div class="flex flex-wrap items-baseline justify-between gap-2">
                    <Link :href="`/tutors/${tutor.id}`" class="text-lg font-medium underline-offset-4 hover:underline">{{ tutor.name }}</Link>
                    <span class="text-sm">
                        {{ tutor.rate }} / hour
                        <span v-if="tutor.trial_price" class="text-muted-foreground">· trial {{ tutor.trial_price }}</span>
                    </span>
                </div>
                <p v-if="tutor.headline" class="text-sm">{{ tutor.headline }}</p>
                <p class="text-muted-foreground text-sm">
                    <template v-if="tutor.rating_avg">★ {{ tutor.rating_avg }} ({{ tutor.rating_count }})</template>
                    <template v-else>No ratings yet</template>
                    <template v-if="tutor.next_slot"> · Next: {{ tutor.next_slot.label }}</template>
                </p>
                <p class="text-muted-foreground text-xs">
                    {{ tutor.subjects.map((s) => [s.subject, s.curriculum].filter(Boolean).join(' · ')).join(', ') }}
                </p>
            </li>
        </ul>

        <p v-if="tutors.length === 0" class="text-muted-foreground text-sm">No tutors match. Try widening the filters.</p>

        <div v-if="lastPage > 1" class="flex items-center gap-3">
            <Button variant="outline" size="sm" :disabled="page <= 1" @click="search(page - 1)">Previous</Button>
            <span class="text-sm">Page {{ page }} of {{ lastPage }}</span>
            <Button variant="outline" size="sm" :disabled="page >= lastPage" @click="search(page + 1)">Next</Button>
        </div>
    </main>

    <PublicFooter />
</template>
