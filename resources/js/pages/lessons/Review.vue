<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';

const props = defineProps<{
    lesson: {
        id: number;
        subject: string | null;
        tutor_display_name: string;
        starts_at_label: string;
    };
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Review this lesson', href: '#' }],
    },
});

const form = useForm({
    rating: '',
    comment: '',
});

function submit() {
    form.post(`/lessons/${props.lesson.id}/review`);
}
</script>

<template>
    <Head title="Review this lesson" />

    <form class="flex max-w-xl flex-col gap-6 p-4" data-test="review-form" @submit.prevent="submit">
        <div class="grid gap-1">
            <h1 class="text-xl font-semibold">Review this lesson</h1>
            <p class="text-muted-foreground text-sm">{{ lesson.subject ?? 'Lesson' }} with {{ lesson.tutor_display_name }} · {{ lesson.starts_at_label }}</p>
            <p class="text-muted-foreground text-sm">Your review is published right away and shown on the tutor's public profile with your first name and last initial only.</p>
        </div>

        <div class="grid gap-2">
            <Label for="rating">Rating</Label>
            <select id="rating" v-model="form.rating" required class="border-input rounded-md border p-2 text-sm">
                <option value="" disabled>Select</option>
                <option v-for="n in 5" :key="n" :value="n">{{ n }} out of 5</option>
            </select>
            <InputError :message="form.errors.rating" />
        </div>

        <div class="grid gap-2">
            <Label for="comment">Comment (optional)</Label>
            <textarea id="comment" v-model="form.comment" rows="4" maxlength="1000" class="border-input rounded-md border p-2 text-sm" />
            <p class="text-muted-foreground text-xs">Contact details are always hidden from a published review.</p>
            <InputError :message="form.errors.comment" />
        </div>

        <div class="flex items-center gap-3">
            <Button type="submit" :disabled="form.processing" data-test="review-submit">
                <Spinner v-if="form.processing" />
                Submit review
            </Button>
            <Button variant="outline" as-child>
                <Link :href="`/lessons/${lesson.id}`">Cancel</Link>
            </Button>
        </div>
    </form>
</template>
