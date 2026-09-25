<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import InputError from '@/components/InputError.vue';
import TestModeBanner from '@/components/TestModeBanner.vue';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';

const props = defineProps<{
    cards: Array<{ value: string; label: string }>;
    current: { brand: string; last4: string; expires: string } | null;
    learner: number | null;
    tutor: number | null;
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Add a card', href: '#' }],
    },
});

const form = useForm({
    card: props.cards[0]?.value ?? '',
    learner: props.learner,
    tutor: props.tutor,
});
</script>

<template>
    <Head title="Add a card" />

    <form class="flex max-w-xl flex-col gap-6 p-4" @submit.prevent="form.post('/payment-methods')">
        <h1 class="text-xl font-semibold">Add a card</h1>

        <TestModeBanner>Choose one of the test cards below; nothing is typed and no card details are stored.</TestModeBanner>

        <p v-if="current" class="text-muted-foreground text-sm">
            Saved card: {{ current.brand }} ending {{ current.last4 }}, expires {{ current.expires }}. Choosing a card below replaces it.
        </p>

        <fieldset class="grid gap-3">
            <legend class="mb-1 text-sm font-medium">Test card</legend>
            <div v-for="card in cards" :key="card.value" class="flex items-center gap-2">
                <input :id="`card-${card.value}`" v-model="form.card" type="radio" name="card" :value="card.value" />
                <Label :for="`card-${card.value}`">{{ card.label }}</Label>
            </div>
            <InputError :message="form.errors.card" />
        </fieldset>

        <div class="flex items-center gap-3">
            <Button type="submit" :disabled="form.processing">
                <Spinner v-if="form.processing" />
                Save card
            </Button>
            <Button variant="outline" as-child>
                <Link href="/learners">Cancel</Link>
            </Button>
        </div>
    </form>
</template>
