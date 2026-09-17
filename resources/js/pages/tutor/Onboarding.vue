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

interface CurriculumProp {
    id: number;
    code: string;
    name: string;
}

interface SubjectProp {
    id: number;
    name: string;
}

interface TutorSubjectProp {
    id: number;
    curriculum_id: number;
    subject_id: number;
    level_min: string;
    level_max: string;
    level_tier: string;
}

interface AvailabilityRuleProp {
    id: number;
    weekday: number;
    start_time: string;
    end_time: string;
}

interface AvailabilityExceptionProp {
    id: number;
    date: string;
    start_time: string;
    end_time: string;
    type: string;
}

const props = defineProps<{
    step:
        | 'personal'
        | 'permit'
        | 'document'
        | 'bank'
        | 'subjects'
        | 'rate'
        | 'profile'
        | 'availability'
        | 'agreement'
        | 'complete'
        | 'submitted';
    currentDocumentType: DocumentTypeProp | null;
    personal: { phone: string | null; timezone: string };
    profile: {
        permit_number: string | null;
        permit_expires_at: string | null;
        bank_name: string | null;
        bank_account_name: string | null;
        bank_iban_masked: string | null;
        bank_swift: string | null;
        headline: string | null;
        bio: string | null;
        intro_video_url: string | null;
        hourly_rate: number | null;
    };
    documentTypes: DocumentTypeProp[];
    documents: DocumentProp[];
    curricula: CurriculumProp[];
    subjects: SubjectProp[];
    tutorSubjects: TutorSubjectProp[];
    rateBand: { min: number; max: number } | null;
    trialDiscountPct: number;
    availabilityRules: AvailabilityRuleProp[];
    availabilityExceptions: AvailabilityExceptionProp[];
    agreement: {
        current_version: number | null;
        title: string | null;
        body: string | null;
        accepted_at: string | null;
        accepted_version: number | null;
    };
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Onboarding', href: '/tutor/onboarding' }],
    },
});

const formatFils = (fils: number) => (fils / 100).toFixed(2);

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

const bankForm = useForm({
    bank_name: props.profile.bank_name ?? '',
    bank_account_name: props.profile.bank_account_name ?? '',
    bank_iban: '',
    bank_swift: props.profile.bank_swift ?? '',
});

const submitBank = () => {
    bankForm.post('/tutor/onboarding/bank');
};

const subjectsForm = useForm<{
    subjects: Array<{ curriculum_id: number | string; subject_id: number | string; level_min: string; level_max: string; level_tier: string }>;
}>({
    subjects:
        props.tutorSubjects.length > 0
            ? props.tutorSubjects.map((s) => ({
                  curriculum_id: s.curriculum_id,
                  subject_id: s.subject_id,
                  level_min: s.level_min,
                  level_max: s.level_max,
                  level_tier: s.level_tier,
              }))
            : [{ curriculum_id: '', subject_id: '', level_min: '', level_max: '', level_tier: '' }],
});

const addSubjectRow = () => {
    subjectsForm.subjects.push({ curriculum_id: '', subject_id: '', level_min: '', level_max: '', level_tier: '' });
};

const removeSubjectRow = (index: number) => {
    subjectsForm.subjects.splice(index, 1);
};

const submitSubjects = () => {
    subjectsForm.post('/tutor/onboarding/subjects');
};

const rateForm = useForm({
    hourly_rate: props.profile.hourly_rate !== null ? formatFils(props.profile.hourly_rate) : '',
});

const submitRate = () => {
    rateForm.post('/tutor/onboarding/rate');
};

// Display-only preview mirroring Money::percentage's half-up integer-fils
// rounding (invariant #3 — no float arithmetic on money). The authoritative
// trial price is computed server-side at booking time (CP3).
const trialPriceFils = () => {
    const match = /^(\d+)(?:\.(\d{1,2}))?$/.exec(rateForm.hourly_rate);
    if (!match) {
        return null;
    }

    const fils = Number(match[1]) * 100 + Number((match[2] ?? '').padEnd(2, '0'));
    const pct = 100 - props.trialDiscountPct;

    return Math.floor((fils * pct + 50) / 100);
};

const profileForm = useForm({
    headline: props.profile.headline ?? '',
    bio: props.profile.bio ?? '',
    intro_video_url: props.profile.intro_video_url ?? '',
});

const submitProfile = () => {
    profileForm.post('/tutor/onboarding/profile');
};

const availabilityForm = useForm<{
    rules: Array<{ weekday: number | string; start_time: string; end_time: string }>;
    exceptions: Array<{ date: string; start_time: string; end_time: string; type: string }>;
}>({
    rules:
        props.availabilityRules.length > 0
            ? props.availabilityRules.map((r) => ({ weekday: r.weekday, start_time: r.start_time, end_time: r.end_time }))
            : [{ weekday: '', start_time: '', end_time: '' }],
    exceptions: props.availabilityExceptions.map((e) => ({ date: e.date, start_time: e.start_time, end_time: e.end_time, type: e.type })),
});

const addAvailabilityRow = () => {
    availabilityForm.rules.push({ weekday: '', start_time: '', end_time: '' });
};

const removeAvailabilityRow = (index: number) => {
    availabilityForm.rules.splice(index, 1);
};

const submitAvailability = () => {
    availabilityForm.post('/tutor/onboarding/availability');
};

const agreementForm = useForm<{ accepted: boolean }>({
    accepted: false,
});

const submitAgreement = () => {
    agreementForm.post('/tutor/onboarding/agreement');
};

const completeForm = useForm({});

const submitComplete = () => {
    completeForm.post('/tutor/onboarding/complete');
};
</script>

<template>
    <Head title="Tutor onboarding" />

    <div class="flex h-full flex-1 flex-col gap-6 rounded-xl p-4">
        <div class="flex items-center gap-2">
            <h1 class="text-xl font-semibold">Onboarding</h1>
            <Badge v-if="step === 'document'" variant="secondary">
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

        <form v-else-if="step === 'bank'" @submit.prevent="submitBank" class="grid max-w-md gap-4">
            <p class="text-muted-foreground text-sm">Payout details — your IBAN is encrypted and only ever shown to you masked.</p>

            <div class="grid gap-2">
                <Label for="bank_name">Bank name</Label>
                <Input id="bank_name" v-model="bankForm.bank_name" type="text" required autofocus />
                <InputError :message="bankForm.errors.bank_name" />
            </div>

            <div class="grid gap-2">
                <Label for="bank_account_name">Account holder name</Label>
                <Input id="bank_account_name" v-model="bankForm.bank_account_name" type="text" required />
                <InputError :message="bankForm.errors.bank_account_name" />
            </div>

            <div class="grid gap-2">
                <Label for="bank_iban">IBAN {{ profile.bank_iban_masked ? `(currently ${profile.bank_iban_masked})` : '' }}</Label>
                <Input id="bank_iban" v-model="bankForm.bank_iban" type="text" required />
                <InputError :message="bankForm.errors.bank_iban" />
            </div>

            <div class="grid gap-2">
                <Label for="bank_swift">SWIFT/BIC (optional)</Label>
                <Input id="bank_swift" v-model="bankForm.bank_swift" type="text" />
                <InputError :message="bankForm.errors.bank_swift" />
            </div>

            <Button type="submit" :disabled="bankForm.processing" class="w-fit">
                <Spinner v-if="bankForm.processing" />
                Continue
            </Button>
        </form>

        <form v-else-if="step === 'subjects'" @submit.prevent="submitSubjects" class="grid max-w-2xl gap-4">
            <p class="text-muted-foreground text-sm">Which curricula, subjects and levels do you teach?</p>

            <div v-for="(row, index) in subjectsForm.subjects" :key="index" class="grid grid-cols-5 items-end gap-2 border-b pb-4">
                <div class="grid gap-2">
                    <Label :for="`curriculum_${index}`">Curriculum</Label>
                    <select :id="`curriculum_${index}`" v-model="row.curriculum_id" class="border-input rounded-md border p-2 text-sm">
                        <option value="" disabled>Select</option>
                        <option v-for="curriculum in curricula" :key="curriculum.id" :value="curriculum.id">{{ curriculum.name }}</option>
                    </select>
                </div>
                <div class="grid gap-2">
                    <Label :for="`subject_${index}`">Subject</Label>
                    <select :id="`subject_${index}`" v-model="row.subject_id" class="border-input rounded-md border p-2 text-sm">
                        <option value="" disabled>Select</option>
                        <option v-for="subject in subjects" :key="subject.id" :value="subject.id">{{ subject.name }}</option>
                    </select>
                </div>
                <div class="grid gap-2">
                    <Label :for="`level_min_${index}`">From level</Label>
                    <Input :id="`level_min_${index}`" v-model="row.level_min" type="text" placeholder="Year 7" />
                </div>
                <div class="grid gap-2">
                    <Label :for="`level_max_${index}`">To level</Label>
                    <Input :id="`level_max_${index}`" v-model="row.level_max" type="text" placeholder="Year 9" />
                </div>
                <div class="grid gap-2">
                    <Label :for="`level_tier_${index}`">Tier</Label>
                    <select :id="`level_tier_${index}`" v-model="row.level_tier" class="border-input rounded-md border p-2 text-sm">
                        <option value="" disabled>Select</option>
                        <option value="lower_secondary">Lower secondary</option>
                        <option value="exam_1">Exam years 1</option>
                        <option value="exam_2">Exam years 2</option>
                    </select>
                </div>
                <Button v-if="subjectsForm.subjects.length > 1" type="button" variant="ghost" class="col-span-5 w-fit" @click="removeSubjectRow(index)">
                    Remove
                </Button>
            </div>

            <InputError :message="subjectsForm.errors.subjects" />

            <Button type="button" variant="outline" class="w-fit" @click="addSubjectRow">Add another subject</Button>

            <Button type="submit" :disabled="subjectsForm.processing" class="w-fit">
                <Spinner v-if="subjectsForm.processing" />
                Continue
            </Button>
        </form>

        <form v-else-if="step === 'rate'" @submit.prevent="submitRate" class="grid max-w-md gap-4">
            <p v-if="rateBand" class="text-muted-foreground text-sm">
                Your rate for the highest level you teach must be between {{ formatFils(rateBand.min) }} and {{ formatFils(rateBand.max) }} AED/hour.
            </p>

            <div class="grid gap-2">
                <Label for="hourly_rate">Hourly rate (AED)</Label>
                <Input id="hourly_rate" v-model="rateForm.hourly_rate" type="text" inputmode="decimal" required autofocus />
                <InputError :message="rateForm.errors.hourly_rate" />
            </div>

            <p v-if="trialPriceFils() !== null" class="text-muted-foreground text-sm">Trial lesson price: {{ formatFils(trialPriceFils()!) }} AED</p>

            <Button type="submit" :disabled="rateForm.processing" class="w-fit">
                <Spinner v-if="rateForm.processing" />
                Continue
            </Button>
        </form>

        <form v-else-if="step === 'profile'" @submit.prevent="submitProfile" class="grid max-w-md gap-4">
            <div class="grid gap-2">
                <Label for="headline">Headline</Label>
                <Input id="headline" v-model="profileForm.headline" type="text" required autofocus />
                <InputError :message="profileForm.errors.headline" />
            </div>

            <div class="grid gap-2">
                <Label for="bio">Bio</Label>
                <textarea id="bio" v-model="profileForm.bio" required class="border-input rounded-md border p-2 text-sm" rows="5"></textarea>
                <InputError :message="profileForm.errors.bio" />
            </div>

            <div class="grid gap-2">
                <Label for="intro_video_url">Intro video URL (optional)</Label>
                <Input id="intro_video_url" v-model="profileForm.intro_video_url" type="url" />
                <InputError :message="profileForm.errors.intro_video_url" />
            </div>

            <Button type="submit" :disabled="profileForm.processing" class="w-fit">
                <Spinner v-if="profileForm.processing" />
                Continue
            </Button>
        </form>

        <form v-else-if="step === 'availability'" @submit.prevent="submitAvailability" class="grid max-w-2xl gap-4">
            <p class="text-muted-foreground text-sm">Your weekly availability, in your own timezone.</p>

            <div v-for="(rule, index) in availabilityForm.rules" :key="index" class="grid grid-cols-4 items-end gap-2 border-b pb-4">
                <div class="grid gap-2">
                    <Label :for="`weekday_${index}`">Weekday</Label>
                    <select :id="`weekday_${index}`" v-model="rule.weekday" class="border-input rounded-md border p-2 text-sm">
                        <option value="" disabled>Select</option>
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
                    <Label :for="`start_${index}`">Start</Label>
                    <Input :id="`start_${index}`" v-model="rule.start_time" type="time" />
                </div>
                <div class="grid gap-2">
                    <Label :for="`end_${index}`">End</Label>
                    <Input :id="`end_${index}`" v-model="rule.end_time" type="time" />
                </div>
                <Button v-if="availabilityForm.rules.length > 1" type="button" variant="ghost" @click="removeAvailabilityRow(index)">Remove</Button>
            </div>

            <InputError :message="availabilityForm.errors.rules" />

            <Button type="button" variant="outline" class="w-fit" @click="addAvailabilityRow">Add another slot</Button>

            <Button type="submit" :disabled="availabilityForm.processing" class="w-fit">
                <Spinner v-if="availabilityForm.processing" />
                Continue
            </Button>
        </form>

        <form v-else-if="step === 'agreement'" @submit.prevent="submitAgreement" class="grid max-w-md gap-4">
            <p class="font-medium">{{ agreement.title }}</p>
            <p class="text-muted-foreground text-sm whitespace-pre-line">{{ agreement.body }}</p>

            <label class="flex items-center gap-2 text-sm">
                <input type="checkbox" v-model="agreementForm.accepted" required />
                I accept the tutor agreement (version {{ agreement.current_version }})
            </label>
            <InputError :message="agreementForm.errors.accepted" />

            <Button type="submit" :disabled="agreementForm.processing" class="w-fit">
                <Spinner v-if="agreementForm.processing" />
                Continue
            </Button>
        </form>

        <div v-else-if="step === 'complete'" class="grid max-w-md gap-4">
            <p class="font-medium">You're all set.</p>
            <p class="text-muted-foreground text-sm">Submit your profile for review — an admin will check your documents and approve you.</p>

            <Button :disabled="completeForm.processing" class="w-fit" @click="submitComplete">
                <Spinner v-if="completeForm.processing" />
                Submit for review
            </Button>
        </div>

        <div v-else class="max-w-md">
            <p class="font-medium">Your profile is under review.</p>
            <p class="text-muted-foreground text-sm">We'll email you once an admin has checked your documents.</p>
        </div>
    </div>
</template>
