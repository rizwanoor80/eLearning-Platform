<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';

const props = defineProps<{
    methods: Array<{ code: string; name: string; kind: 'bank' | 'email' }>;
    selected: string | null;
    saved_method: string | null;
    problem: string | null;
    bank: {
        bank_name: string | null;
        bank_account_name: string | null;
        iban_masked: string | null;
        bank_swift: string | null;
    };
    payout_email_masked: string | null;
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Payouts', href: '/settings/payout' }],
    },
});

// Secrets are never sent to the browser: the IBAN and the account email arrive masked, and an input left blank keeps
// the value already stored.
const form = useForm({
    method: props.selected ?? '',
    bank_name: props.bank.bank_name ?? '',
    bank_account_name: props.bank.bank_account_name ?? '',
    bank_iban: '',
    bank_swift: props.bank.bank_swift ?? '',
    payout_email: '',
});

const kind = () => props.methods.find((m) => m.code === form.method)?.kind ?? null;

function save() {
    form.put('/settings/payout', {
        preserveScroll: true,
        onSuccess: () => form.reset('bank_iban', 'payout_email'),
    });
}
</script>

<template>
    <Head title="Payouts" />

    <h1 class="sr-only">Payouts</h1>

    <div class="space-y-6">
        <Heading
            variant="small"
            title="How you get paid"
            description="Choose a payout method and give us the details it needs. Payouts are made by hand from the platform's payout file."
        />

        <p v-if="problem" class="text-sm text-amber-700 dark:text-amber-400" role="status" data-testid="payout-problem">
            You cannot be paid yet: {{ problem }}
        </p>

        <p v-if="methods.length === 0" class="text-muted-foreground text-sm">
            No payout method is available for your country yet. We will email you when one is.
        </p>

        <form v-else class="space-y-6" @submit.prevent="save">
            <fieldset class="grid gap-3">
                <legend class="mb-1 text-sm font-medium">Payout method</legend>
                <div v-for="method in methods" :key="method.code" class="flex items-center gap-2">
                    <input :id="`method-${method.code}`" v-model="form.method" type="radio" name="method" :value="method.code" />
                    <Label :for="`method-${method.code}`">{{ method.name }}</Label>
                </div>
                <InputError :message="form.errors.method" />
            </fieldset>

            <div v-if="kind() === 'bank'" class="grid gap-4" data-testid="bank-fields">
                <div class="grid gap-2">
                    <Label for="bank_name">Bank name</Label>
                    <Input id="bank_name" v-model="form.bank_name" type="text" />
                    <InputError :message="form.errors.bank_name" />
                </div>
                <div class="grid gap-2">
                    <Label for="bank_account_name">Account holder name</Label>
                    <Input id="bank_account_name" v-model="form.bank_account_name" type="text" />
                    <InputError :message="form.errors.bank_account_name" />
                </div>
                <div class="grid gap-2">
                    <Label for="bank_iban">IBAN {{ bank.iban_masked ? `(currently ${bank.iban_masked}; leave blank to keep it)` : '' }}</Label>
                    <Input id="bank_iban" v-model="form.bank_iban" type="text" autocomplete="off" />
                    <InputError :message="form.errors.bank_iban" />
                </div>
                <div class="grid gap-2">
                    <Label for="bank_swift">SWIFT / BIC (optional)</Label>
                    <Input id="bank_swift" v-model="form.bank_swift" type="text" />
                    <InputError :message="form.errors.bank_swift" />
                </div>
            </div>

            <div v-else-if="kind() === 'email'" class="grid gap-2" data-testid="email-fields">
                <Label for="payout_email">Account email {{ payout_email_masked ? `(currently ${payout_email_masked}; leave blank to keep it)` : '' }}</Label>
                <Input id="payout_email" v-model="form.payout_email" type="email" autocomplete="off" />
                <InputError :message="form.errors.payout_email" />
            </div>

            <Button type="submit" :disabled="form.processing || !form.method">
                <Spinner v-if="form.processing" />
                Save payout method
            </Button>
        </form>
    </div>
</template>
