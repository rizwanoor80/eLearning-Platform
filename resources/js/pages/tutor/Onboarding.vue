<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import InputError from '@/components/InputError.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';

interface DocumentTypeProp {
    id: number;
    code: string;
    name: string;
    description: string;
}

interface DocumentProp {
    id: number;
    document_type_id: number;
    original_name: string;
    status: string;
}

const props = defineProps<{
    step: 'personal' | 'permit' | 'document' | 'complete';
    currentDocumentType: DocumentTypeProp | null;
    personal: { phone: string | null; timezone: string };
    profile: { permit_number: string | null; permit_expires_at: string | null };
    documentTypes: DocumentTypeProp[];
    documents: DocumentProp[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Onboarding', href: '/tutor/onboarding' }],
    },
});

const personalForm = useForm({
    phone: props.personal.phone ?? '',
    timezone: props.personal.timezone,
});

const submitPersonal = () => {
    personalForm.post('/tutor/onboarding/personal');
};

const permitForm = useForm({
    permit_number: props.profile.permit_number ?? '',
    permit_expires_at: props.profile.permit_expires_at ?? '',
});

const submitPermit = () => {
    permitForm.post('/tutor/onboarding/permit');
};

const documentForm = useForm<{ file: File | null }>({
    file: null,
});

const onFileChange = (event: Event) => {
    const target = event.target as HTMLInputElement;
    documentForm.file = target.files?.[0] ?? null;
};

const submitDocument = () => {
    documentForm.post('/tutor/onboarding/documents', {
        forceFormData: true,
        onSuccess: () => documentForm.reset(),
    });
};

const documentsUploadedCount = () => props.documents.length;
</script>

<template>
    <Head title="Tutor onboarding" />

    <div class="flex h-full flex-1 flex-col gap-6 rounded-xl p-4">
        <div class="flex items-center gap-2">
            <h1 class="text-xl font-semibold">Onboarding</h1>
            <Badge v-if="step !== 'complete'" variant="secondary">
                {{ documentsUploadedCount() }} of {{ documentTypes.length }} documents uploaded
            </Badge>
        </div>

        <form v-if="step === 'personal'" @submit.prevent="submitPersonal" class="grid max-w-md gap-4">
            <p class="text-muted-foreground text-sm">First, a couple of contact details.</p>

            <div class="grid gap-2">
                <Label for="phone">Phone number</Label>
                <Input id="phone" v-model="personalForm.phone" type="tel" required autofocus autocomplete="tel" />
                <InputError :message="personalForm.errors.phone" />
            </div>

            <div class="grid gap-2">
                <Label for="timezone">Timezone</Label>
                <Input id="timezone" v-model="personalForm.timezone" type="text" required />
                <InputError :message="personalForm.errors.timezone" />
            </div>

            <Button type="submit" :disabled="personalForm.processing" class="w-fit">
                <Spinner v-if="personalForm.processing" />
                Continue
            </Button>
        </form>

        <form v-else-if="step === 'permit'" @submit.prevent="submitPermit" class="grid max-w-md gap-4">
            <p class="text-muted-foreground text-sm">Your tutoring permit keeps you bookable — we'll remind you before it expires.</p>

            <div class="grid gap-2">
                <Label for="permit_number">Permit number</Label>
                <Input id="permit_number" v-model="permitForm.permit_number" type="text" required autofocus />
                <InputError :message="permitForm.errors.permit_number" />
            </div>

            <div class="grid gap-2">
                <Label for="permit_expires_at">Permit expiry date</Label>
                <Input id="permit_expires_at" v-model="permitForm.permit_expires_at" type="date" required />
                <InputError :message="permitForm.errors.permit_expires_at" />
            </div>

            <Button type="submit" :disabled="permitForm.processing" class="w-fit">
                <Spinner v-if="permitForm.processing" />
                Continue
            </Button>
        </form>

        <form
            v-else-if="step === 'document' && currentDocumentType"
            @submit.prevent="submitDocument"
            class="grid max-w-md gap-4"
        >
            <div>
                <p class="font-medium">{{ currentDocumentType.name }}</p>
                <p class="text-muted-foreground text-sm">{{ currentDocumentType.description }}</p>
            </div>

            <div class="grid gap-2">
                <Label for="file">File (PDF, JPG or PNG, up to 10MB)</Label>
                <input
                    id="file"
                    type="file"
                    accept=".pdf,.jpg,.jpeg,.png"
                    required
                    class="border-input file:bg-secondary rounded-md border p-2 text-sm"
                    @change="onFileChange"
                />
                <InputError :message="documentForm.errors.file" />
            </div>

            <Button type="submit" :disabled="documentForm.processing || !documentForm.file" class="w-fit">
                <Spinner v-if="documentForm.processing" />
                Upload
            </Button>
        </form>

        <div v-else class="max-w-md">
            <p class="font-medium">All documents received.</p>
            <p class="text-muted-foreground text-sm">
                The rest of onboarding (bank details, subjects, rate, bio, availability and the tutor
                agreement) is coming soon.
            </p>
        </div>
    </div>
</template>
