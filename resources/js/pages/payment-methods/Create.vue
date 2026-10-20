<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import type {
    Stripe,
    StripeElements,
    StripePaymentElement,
} from '@stripe/stripe-js';
import { nextTick, onBeforeUnmount, onMounted, ref } from 'vue';
import InputError from '@/components/InputError.vue';
import TestModeBanner from '@/components/TestModeBanner.vue';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { postJson, stripeFor } from '@/lib/stripe';

const props = defineProps<{
    driver: 'fake' | 'stripe';
    // Present only while Stripe is the active gateway.
    stripe: { publishable_key: string } | null;
    cards: Array<{ value: string; label: string }>;
    current: {
        brand: string;
        last4: string;
        expires: string;
        notice: string | null;
    } | null;
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

// Stripe: the card number is typed into Stripe's own field and goes from the browser to Stripe. This
// server only ever receives the id of the SetupIntent that was confirmed.
const stripeForm = useForm({
    setup_intent_id: '',
    learner: props.learner,
    tutor: props.tutor,
});

const cardHost = ref<HTMLElement | null>(null);
const cardReady = ref(false);
const cardError = ref<string | null>(null);
const saving = ref(false);

let stripe: Stripe | null = null;
let elements: StripeElements | null = null;
let paymentElement: StripePaymentElement | null = null;

onMounted(async () => {
    if (props.driver !== 'stripe' || !props.stripe) {
        return;
    }

    stripe = await stripeFor(props.stripe.publishable_key);

    if (!stripe) {
        cardError.value =
            'The card form could not be loaded. Please try again in a moment.';

        return;
    }

    elements = stripe.elements({
        mode: 'setup',
        currency: 'aed',
        setupFutureUsage: 'off_session',
        allowedPaymentMethodTypes: ['card'],
    });
    paymentElement = elements.create('payment');
    await nextTick();

    if (cardHost.value) {
        paymentElement.mount(cardHost.value);
        cardReady.value = true;
    }
});

onBeforeUnmount(() => paymentElement?.destroy());

async function saveStripeCard() {
    if (!stripe || !elements) {
        return;
    }

    saving.value = true;
    cardError.value = null;
    stripeForm.clearErrors();

    const submitted = await elements.submit();

    if (submitted.error) {
        cardError.value =
            submitted.error.message ?? 'Please check your card details.';
        saving.value = false;

        return;
    }

    const intent = await postJson<{
        setup_intent_id: string;
        client_secret: string;
    }>('/payment-methods/setup-intent', {});

    if (!intent.ok) {
        cardError.value =
            intent.body.message ??
            'We could not start saving your card. Please try again.';
        saving.value = false;

        return;
    }

    const confirmed = await stripe.confirmSetup({
        elements,
        clientSecret: intent.body.client_secret,
        redirect: 'if_required',
        confirmParams: { return_url: window.location.href },
    });

    if (confirmed.error || confirmed.setupIntent?.status !== 'succeeded') {
        cardError.value =
            confirmed.error?.message ??
            'Your card could not be saved. Please try another card.';
        saving.value = false;

        return;
    }

    stripeForm
        .transform((data) => ({
            ...data,
            setup_intent_id: confirmed.setupIntent.id,
        }))
        .post('/payment-methods/stripe', {
            onFinish: () => (saving.value = false),
        });
}
</script>

<template>
    <Head title="Add a card" />

    <form
        v-if="driver === 'stripe'"
        class="flex max-w-xl flex-col gap-6 p-4"
        @submit.prevent="saveStripeCard"
    >
        <h1 class="text-xl font-semibold">Add a card</h1>

        <p v-if="current" class="text-muted-foreground text-sm">
            Saved card: {{ current.brand }} ending {{ current.last4 }}, expires
            {{ current.expires }}. Saving a new card replaces it.
        </p>
        <p
            v-if="current?.notice"
            class="text-sm text-amber-700 dark:text-amber-400"
            role="status"
            data-testid="card-notice"
        >
            {{ current.notice }}
        </p>

        <div class="grid gap-2" data-testid="card-form">
            <Label>Card</Label>
            <div ref="cardHost" />
            <p v-if="!cardReady && !cardError" class="text-muted-foreground text-sm">
                Loading the card form…
            </p>
            <p v-if="cardError" class="text-destructive text-sm" role="alert">
                {{ cardError }}
            </p>
            <InputError :message="stripeForm.errors.setup_intent_id" />
            <p class="text-muted-foreground text-xs">
                Your card details go straight to our payment provider; we keep
                only the card brand, last four digits and expiry.
            </p>
        </div>

        <div class="flex items-center gap-3">
            <Button type="submit" :disabled="!cardReady || saving">
                <Spinner v-if="saving" />
                Save card
            </Button>
            <Button variant="outline" as-child>
                <Link href="/learners">Cancel</Link>
            </Button>
        </div>
    </form>

    <form
        v-else
        class="flex max-w-xl flex-col gap-6 p-4"
        @submit.prevent="form.post('/payment-methods')"
    >
        <h1 class="text-xl font-semibold">Add a card</h1>

        <TestModeBanner>Choose one of the test cards below; nothing is typed and no card details are stored.</TestModeBanner>

        <p v-if="current" class="text-muted-foreground text-sm">
            Saved card: {{ current.brand }} ending {{ current.last4 }}, expires {{ current.expires }}. Choosing a card below replaces it.
        </p>
        <p
            v-if="current?.notice"
            class="text-sm text-amber-700 dark:text-amber-400"
            role="status"
            data-testid="card-notice"
        >
            {{ current.notice }}
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
