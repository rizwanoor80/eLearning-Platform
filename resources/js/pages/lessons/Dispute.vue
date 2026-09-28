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
    reasons: { value: string; label: string }[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Dispute this lesson', href: '#' }],
    },
});

const form = useForm({
    reason: '',
    description: '',
});

function submit() {
    form.post(`/lessons/${props.lesson.id}/dispute`);
}
</script>

<template>
    <Head title="Dispute this lesson" />

    <form class="flex max-w-xl flex-col gap-6 p-4" data-test="dispute-form" @submit.prevent="submit">
        <div class="grid gap-1">
            <h1 class="text-xl font-semibold">Dispute this lesson</h1>
            <p class="text-muted-foreground text-sm">{{ lesson.subject ?? 'Lesson' }} with {{ lesson.tutor_display_name }} · {{ lesson.starts_at_label }}</p>
            <p class="text-muted-foreground text-sm">This pauses any pending payout to the tutor while an admin reviews it. You can dispute a lesson up to 48 hours after it ends.</p>
        </div>

        <div class="grid gap-2">
            <Label for="reason">Reason</Label>
            <select id="reason" v-model="form.reason" required class="border-input rounded-md border p-2 text-sm">
                <option value="" disabled>Select</option>
                <option v-for="reason in reasons" :key="reason.value" :value="reason.value">{{ reason.label }}</option>
            </select>
            <InputError :message="form.errors.reason" />
        </div>

        <div class="grid gap-2">
            <Label for="description">What happened</Label>
            <textarea id="description" v-model="form.description" rows="5" maxlength="2000" required class="border-input rounded-md border p-2 text-sm" />
            <InputError :message="form.errors.description" />
        </div>

        <div class="flex items-center gap-3">
            <Button type="submit" :disabled="form.processing" data-test="dispute-submit">
                <Spinner v-if="form.processing" />
                Open dispute
            </Button>
            <Button variant="outline" as-child>
                <Link :href="`/lessons/${lesson.id}`">Cancel</Link>
            </Button>
        </div>
    </form>
</template>
