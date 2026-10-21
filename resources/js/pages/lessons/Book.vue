<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import type {
    Stripe,
    StripeElements,
    StripePaymentElement,
} from '@stripe/stripe-js';
import { computed, nextTick, onBeforeUnmount, ref, watch } from 'vue';
import InputError from '@/components/InputError.vue';
import TestModeBanner from '@/components/TestModeBanner.vue';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { postJson, stripeFor } from '@/lib/stripe';

type SubjectRow = { curriculum_id: number; subject_id: number; label: string };

const props = defineProps<{
    tutor: { id: number; name: string };
    // Type and price are computed on the server per learner (BookLesson's own rule); the browser only shows them.
    learners: Array<{
        id: number;
        display_name: string;
        type: 'trial' | 'regular';
        type_label: string;
        price: string | null;
        amount_fils: number | null;
        quote_token: string;
        preselect: SubjectRow | null;
    }>;
    selected_learner: number | null;
    subjects: SubjectRow[];
    starts_at: string;
    slot_label: string;
    slot_available: boolean;
    cancel_notice: string | null;
    alternatives: Array<{ starts_at: string; label: string }>;
    timezone: string;
    // Present only while Stripe is the active gateway; the fake driver's booking has no card step.
    stripe: { publishable_key: string } | null;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Tutors', href: '/tutors' },
            { title: 'Book a lesson', href: '#' },
        ],
    },
});

const key = (row: SubjectRow) => `${row.curriculum_id}|${row.subject_id}`;

const learnerId = ref<number | ''>(props.selected_learner ?? '');
const learner = computed(
    () =>
        props.learners.find((row) => row.id === Number(learnerId.value)) ??
        null,
);
const initial =
    props.learners.find((row) => row.id === props.selected_learner)
        ?.preselect ?? null;
const subject = ref(initial ? key(initial) : '');

// Changing the learner re-derives the subject preselect from that learner's server-side curriculum match.
watch(learnerId, () => {
    subject.value = learner.value?.preselect
        ? key(learner.value.preselect)
        : '';
});

const form = useForm({
    learner_id: '' as number | '',
    tutor_id: props.tutor.id,
    curriculum_id: '' as number | '',
    subject_id: '' as number | '',
    starts_at: props.starts_at,
    quote_token: '',
    payment_intent_id: '',
});

// `slot` is a server-side form error key that is not one of the form's fields.
// `retry` is the server saying the payment could not be confirmed but the card hold is still good.
const slotError = computed(() => {
    const errors = form.errors as Record<string, string | undefined>;

    return errors.slot ?? errors.retry;
});

const cardError = ref<string | null>(null);
// One id per payment attempt: a double click or a retry asks Stripe for the same hold, never a second one. A new
// attempt starts after any failure that is not "try again on the same hold".
const attemptId = ref(crypto.randomUUID());
const heldIntentId = ref<string | null>(null);
const paying = ref(false);
const cardHost = ref<HTMLElement | null>(null);
const cardReady = ref(false);

let stripe: Stripe | null = null;
let elements: StripeElements | null = null;
let paymentElement: StripePaymentElement | null = null;

// The card form needs an amount, so it appears once a learner with a price is chosen and follows the learner.
const cardAmount = computed(() =>
    props.stripe && props.slot_available
        ? (learner.value?.amount_fils ?? null)
        : null,
);

watch(
    cardAmount,
    async (amount) => {
        if (!props.stripe || amount === null) {
            return;
        }

        if (elements) {
            elements.update({ amount });

            return;
        }

        stripe = await stripeFor(props.stripe.publishable_key);

        if (!stripe) {
            cardError.value =
                'The card form could not be loaded. Please try again in a moment.';

            return;
        }

        elements = stripe.elements({
            mode: 'payment',
            amount,
            currency: 'aed',
            captureMethod: 'manual',
            allowedPaymentMethodTypes: ['card'],
        });
        paymentElement = elements.create('payment');
        await nextTick();

        if (cardHost.value) {
            paymentElement.mount(cardHost.value);
            cardReady.value = true;
        }
    },
    { immediate: true },
);

onBeforeUnmount(() => paymentElement?.destroy());

const canSubmit = computed(
    () =>
        props.slot_available &&
        learner.value !== null &&
        subject.value !== '' &&
        learner.value.price !== null &&
        (!props.stripe || cardReady.value),
);

function post(paymentIntentId: string) {
    const [curriculum, subj] = subject.value.split('|');

    form.transform((data) => ({
        ...data,
        learner_id: learnerId.value,
        curriculum_id: Number(curriculum),
        subject_id: Number(subj),
        quote_token: learner.value?.quote_token ?? '',
        payment_intent_id: paymentIntentId,
    })).post('/lessons', {
        onError: (errors) => {
            if (!('retry' in errors)) {
                heldIntentId.value = null;
                attemptId.value = crypto.randomUUID();
            }
        },
        onFinish: () => (paying.value = false),
    });
}

// Stripe: the browser only places a hold on the card (3-D Secure if the bank asks); the server takes the money
// inside the booking, so a refused slot or a changed price never charges anyone.
async function pay() {
    if (!stripe || !elements || !learner.value || paying.value) {
        return;
    }

    paying.value = true;
    cardError.value = null;
    form.clearErrors();

    // The card is already held and only the server's answer was missing: submit the same hold again.
    if (heldIntentId.value !== null) {
        post(heldIntentId.value);

        return;
    }

    const submitted = await elements.submit();

    if (submitted.error) {
        cardError.value =
            submitted.error.message ?? 'Please check your card details.';
        paying.value = false;

        return;
    }

    const intent = await postJson<{
        payment_intent_id: string;
        client_secret: string;
    }>('/payments/checkout-intent', {
        learner_id: learnerId.value,
        tutor_id: props.tutor.id,
        starts_at: props.starts_at,
        quote_token: learner.value.quote_token,
        attempt_id: attemptId.value,
    });

    if (!intent.ok) {
        cardError.value =
            intent.body.message ??
            'We could not start the payment. Please try again.';
        paying.value = false;

        return;
    }

    const confirmed = await stripe.confirmPayment({
        elements,
        clientSecret: intent.body.client_secret,
        redirect: 'if_required',
        confirmParams: { return_url: window.location.href },
    });

    if (
        confirmed.error ||
        confirmed.paymentIntent?.status !== 'requires_capture'
    ) {
        cardError.value =
            confirmed.error?.message ??
            'Your card could not be authorised. Please try another card.';
        paying.value = false;

        return;
    }

    heldIntentId.value = confirmed.paymentIntent.id;
    post(heldIntentId.value);
}

function submit() {
    if (props.stripe) {
        void pay();

        return;
    }

    post('');
}
</script>

<template>
    <Head title="Book a lesson" />

    <div class="flex max-w-xl flex-col gap-6 p-4">
        <TestModeBanner />

        <div class="grid gap-1">
            <h1 class="text-xl font-semibold">
                Book a lesson with {{ tutor.name }}
            </h1>
            <p class="text-sm">
                {{ slot_label }}
                <span class="text-muted-foreground"
                    >({{ timezone }}) · 60 minutes</span
                >
            </p>
        </div>

        <div v-if="!slot_available" class="grid gap-3 rounded-xl border p-4">
            <p class="text-sm">
                That time is no longer available. Choose another:
            </p>
            <ul v-if="alternatives.length" class="flex flex-wrap gap-2 text-sm">
                <li v-for="slot in alternatives" :key="slot.starts_at">
                    <Link
                        :href="`/tutors/${tutor.id}/book?starts_at=${encodeURIComponent(slot.starts_at)}`"
                        class="rounded-md border px-3 py-1 underline-offset-4 hover:underline"
                    >
                        {{ slot.label }}
                    </Link>
                </li>
            </ul>
            <p v-else class="text-muted-foreground text-sm">
                No open slots right now.
            </p>
        </div>

        <p v-if="learners.length === 0" class="rounded-md border p-3 text-sm">
            Add a learner before booking a lesson.
            <Link href="/learners/create" class="underline underline-offset-4"
                >Add a learner</Link
            >
        </p>

        <form v-else class="flex flex-col gap-6" @submit.prevent="submit">
            <div class="grid gap-2">
                <Label for="learner_id">Learner</Label>
                <select
                    id="learner_id"
                    v-model="learnerId"
                    class="border-input rounded-md border p-2 text-sm"
                    required
                >
                    <option value="" disabled>Select</option>
                    <option
                        v-for="row in learners"
                        :key="row.id"
                        :value="row.id"
                    >
                        {{ row.display_name }}
                    </option>
                </select>
            </div>

            <div class="grid gap-2">
                <Label for="subject">Subject</Label>
                <select
                    id="subject"
                    v-model="subject"
                    class="border-input rounded-md border p-2 text-sm"
                    required
                >
                    <option value="" disabled>Select</option>
                    <option
                        v-for="row in subjects"
                        :key="key(row)"
                        :value="key(row)"
                    >
                        {{ row.label }}
                    </option>
                </select>
            </div>

            <div v-if="learner" class="grid gap-1 rounded-xl border p-4">
                <p class="font-medium">{{ learner.type_label }}</p>
                <p class="text-sm">
                    {{ learner.price ?? 'No price set for this tutor' }}
                </p>
                <p class="text-muted-foreground text-xs">
                    <template v-if="learner.type === 'trial'"
                        >The first lesson with a tutor is a discounted
                        trial.</template
                    >
                    <template v-else
                        >Charged at the tutor's hourly rate.</template
                    >
                </p>
            </div>

            <div
                v-if="stripe && slot_available"
                class="grid gap-2"
                data-testid="card-form"
            >
                <Label>Card</Label>
                <div v-show="cardAmount !== null" ref="cardHost" />
                <p
                    v-if="cardAmount === null"
                    class="text-muted-foreground text-sm"
                >
                    Choose a learner to enter your card.
                </p>
                <p class="text-muted-foreground text-xs">
                    Your card is held now and charged when the lesson is
                    confirmed.
                </p>
                <InputError :message="cardError ?? undefined" />
            </div>

            <p
                v-if="slot_available && cancel_notice"
                class="rounded-md border p-3 text-sm"
                data-testid="cancel-notice"
            >
                {{ cancel_notice }}
            </p>

            <InputError :message="slotError" />
            <InputError :message="form.errors.starts_at" />
            <InputError :message="form.errors.quote_token" />
            <InputError :message="form.errors.learner_id" />
            <InputError :message="form.errors.curriculum_id" />
            <InputError :message="form.errors.subject_id" />

            <div class="flex items-center gap-3">
                <Button
                    type="submit"
                    :disabled="form.processing || paying || !canSubmit"
                >
                    <Spinner v-if="form.processing || paying" />
                    Confirm and pay
                </Button>
                <Button variant="outline" as-child>
                    <Link :href="`/tutors/${tutor.id}`">Cancel</Link>
                </Button>
            </div>
        </form>
    </div>
</template>
