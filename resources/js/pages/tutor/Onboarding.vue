<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
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
    level_min_id: number | null;
    level_max_id: number | null;
    level_min_legacy: string | null;
    level_max_legacy: string | null;
    level_tier: string;
}

interface YearGroupProp {
    id: number;
    curriculum_id: number;
    label: string;
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
    status: string;
    reviewNote: string | null;
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
    yearGroups: YearGroupProp[];
    rateBand: { min: number; max: number; conflicting: string[] } | null;
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

// A changes_requested tutor already has every field filled, so the server-derived
// step is 'complete'. This picker lets them open the existing forms to fix what the
// admin flagged; every submit redirects to /tutor/onboarding, which re-derives the
// step and lands back on 'complete' (R31). The derived step itself is never chosen
// from here — `viewing` only decides which already-saved form to show.
type PickableStep = 'personal' | 'permit' | 'bank' | 'subjects' | 'rate' | 'profile' | 'availability';

const pickableSteps: Array<{ key: PickableStep; label: string }> = [
    { key: 'personal', label: 'Contact details' },
    { key: 'permit', label: 'Permit' },
    { key: 'bank', label: 'Bank details' },
    { key: 'subjects', label: 'Subjects' },
    { key: 'rate', label: 'Hourly rate' },
    { key: 'profile', label: 'Bio and headline' },
    { key: 'availability', label: 'Availability' },
];

const viewing = ref<PickableStep | null>(null);
const canPickStep = computed(() => props.status === 'changes_requested' && props.step === 'complete');
const shown = computed(() => (canPickStep.value && viewing.value !== null ? viewing.value : props.step));
const afterSubmit = { onSuccess: () => (viewing.value = null) };

const lockedTitle = computed(() => {
    switch (props.status) {
        case 'approved':
            return "You're approved.";
        case 'rejected':
            return 'Your application was not approved.';
        case 'suspended':
            return 'Your profile is suspended.';
        default:
            return 'Your profile is under review.';
    }
});

const lockedText = computed(() => {
    switch (props.status) {
        case 'approved':
            return 'Your profile is live. Contact support if something needs to change.';
        case 'rejected':
            return 'Contact support if you would like to know more.';
        case 'suspended':
            return 'Contact support to discuss reinstating your profile.';
        default:
            return "We'll email you once an admin has checked your documents.";
    }
});

const personalForm = useForm({
    phone: props.personal.phone ?? '',
    timezone: props.personal.timezone,
});

const submitPersonal = () => {
    personalForm.post('/tutor/onboarding/personal', afterSubmit);
};

const permitForm = useForm({
    permit_number: props.profile.permit_number ?? '',
    permit_expires_at: props.profile.permit_expires_at ?? '',
});

const submitPermit = () => {
    permitForm.post('/tutor/onboarding/permit', afterSubmit);
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
    bankForm.post('/tutor/onboarding/bank', afterSubmit);
};

type SubjectRow = { curriculum_id: number | string; subject_id: number | string; level_min_id: number | string; level_max_id: number | string };

const emptySubjectRow = (): SubjectRow => ({ curriculum_id: '', subject_id: '', level_min_id: '', level_max_id: '' });

const subjectsForm = useForm<{ subjects: SubjectRow[] }>({
    subjects:
        props.tutorSubjects.length > 0
            ? props.tutorSubjects.map((s) => ({
                  curriculum_id: s.curriculum_id,
                  subject_id: s.subject_id,
                  level_min_id: s.level_min_id ?? '',
                  level_max_id: s.level_max_id ?? '',
              }))
            : [emptySubjectRow()],
});

// A row's year groups come from its own curriculum; changing the curriculum
// clears a year group that no longer belongs to it. The tier is worked out by
// the server from the range, so there is nothing to choose here.
const groupsFor = (curriculumId: number | string) => props.yearGroups.filter((group) => group.curriculum_id === Number(curriculumId));

const clearForeignLevels = (row: SubjectRow) => {
    const valid = groupsFor(row.curriculum_id).map((group) => group.id);
    if (!valid.includes(Number(row.level_min_id))) row.level_min_id = '';
    if (!valid.includes(Number(row.level_max_id))) row.level_max_id = '';
};

const legacyText = (rowIndex: number) => {
    const original = props.tutorSubjects[rowIndex];
    return original && (original.level_min_id === null || original.level_max_id === null)
        ? [original.level_min_legacy, original.level_max_legacy].filter(Boolean).join(' – ')
        : null;
};

const addSubjectRow = () => {
    subjectsForm.subjects.push(emptySubjectRow());
};

const removeSubjectRow = (index: number) => {
    subjectsForm.subjects.splice(index, 1);
};

const submitSubjects = () => {
    subjectsForm.post('/tutor/onboarding/subjects', afterSubmit);
};

const rateForm = useForm({
    hourly_rate: props.profile.hourly_rate !== null ? formatFils(props.profile.hourly_rate) : '',
});

const submitRate = () => {
    rateForm.post('/tutor/onboarding/rate', afterSubmit);
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
    profileForm.post('/tutor/onboarding/profile', afterSubmit);
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
    availabilityForm.post('/tutor/onboarding/availability', afterSubmit);
};

const agreementForm = useForm<{ accepted: boolean; version: number | null }>({
    accepted: false,
    version: props.agreement.current_version,
});

// If the admin publishes a new version while the tutor is reading, the server
// refuses the stale acceptance and re-sends the page: pick up the version now
// shown and make them tick the box again.
watch(
    () => props.agreement.current_version,
    (version) => {
        agreementForm.version = version;
        agreementForm.accepted = false;
    },
);

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

        <div v-if="reviewNote" class="max-w-md rounded-md border border-amber-300 bg-amber-50 p-4 text-sm">
            <p class="font-medium">{{ status === 'suspended' ? 'Reason given by an admin:' : 'An admin asked for some changes:' }}</p>
            <p class="text-muted-foreground whitespace-pre-line">{{ reviewNote }}</p>
        </div>

        <div v-if="canPickStep" class="grid max-w-md gap-3">
            <p class="text-muted-foreground text-sm">
                Pick a section to update, then submit for review again. A document that is pending or accepted can only be replaced
                after the admin rejects it and requests changes — you'll then be taken to its upload step automatically.
            </p>
            <div class="flex flex-wrap gap-2">
                <Button
                    v-for="section in pickableSteps"
                    :key="section.key"
                    type="button"
                    size="sm"
                    :variant="viewing === section.key ? 'default' : 'outline'"
                    @click="viewing = section.key"
                >
                    {{ section.label }}
                </Button>
                <Button v-if="viewing !== null" type="button" size="sm" variant="ghost" @click="viewing = null">Back</Button>
            </div>
        </div>

        <form v-if="shown === 'personal'" @submit.prevent="submitPersonal" class="grid max-w-md gap-4">
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

        <form v-else-if="shown === 'permit'" @submit.prevent="submitPermit" class="grid max-w-md gap-4">
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
            v-else-if="shown === 'document' && currentDocumentType"
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

        <form v-else-if="shown === 'bank'" @submit.prevent="submitBank" class="grid max-w-md gap-4">
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

        <form v-else-if="shown === 'subjects'" @submit.prevent="submitSubjects" class="grid max-w-2xl gap-4">
            <p class="text-muted-foreground text-sm">Which curricula, subjects and levels do you teach?</p>

            <div v-for="(row, index) in subjectsForm.subjects" :key="index" class="grid grid-cols-4 items-end gap-2 border-b pb-4">
                <div class="grid gap-2">
                    <Label :for="`curriculum_${index}`">Curriculum</Label>
                    <select :id="`curriculum_${index}`" v-model="row.curriculum_id" class="border-input rounded-md border p-2 text-sm" @change="clearForeignLevels(row)">
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
                    <Label :for="`level_min_${index}`">From year group</Label>
                    <select :id="`level_min_${index}`" v-model="row.level_min_id" class="border-input rounded-md border p-2 text-sm" :disabled="row.curriculum_id === ''">
                        <option value="" disabled>{{ row.curriculum_id === '' ? 'Choose a curriculum first' : 'Select' }}</option>
                        <option v-for="group in groupsFor(row.curriculum_id)" :key="group.id" :value="group.id">{{ group.label }}</option>
                    </select>
                </div>
                <div class="grid gap-2">
                    <Label :for="`level_max_${index}`">To year group</Label>
                    <select :id="`level_max_${index}`" v-model="row.level_max_id" class="border-input rounded-md border p-2 text-sm" :disabled="row.curriculum_id === ''">
                        <option value="" disabled>{{ row.curriculum_id === '' ? 'Choose a curriculum first' : 'Select' }}</option>
                        <option v-for="group in groupsFor(row.curriculum_id)" :key="group.id" :value="group.id">{{ group.label }}</option>
                    </select>
                </div>
                <p v-if="legacyText(index)" class="text-muted-foreground col-span-4 text-xs">
                    We could not match “{{ legacyText(index) }}” to our list of year groups — please choose them.
                </p>
                <Button v-if="subjectsForm.subjects.length > 1" type="button" variant="ghost" class="col-span-4 w-fit" @click="removeSubjectRow(index)">
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

        <form v-else-if="shown === 'rate'" @submit.prevent="submitRate" class="grid max-w-md gap-4">
            <p v-if="rateBand && rateBand.conflicting.length > 0" class="text-destructive text-sm">
                No single rate satisfies every curriculum you teach at this level — {{ rateBand.conflicting.join(' and ') }} have non-overlapping bands.
            </p>
            <p v-else-if="rateBand" class="text-muted-foreground text-sm">
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

        <form v-else-if="shown === 'profile'" @submit.prevent="submitProfile" class="grid max-w-md gap-4">
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

        <form v-else-if="shown === 'availability'" @submit.prevent="submitAvailability" class="grid max-w-2xl gap-4">
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

        <form v-else-if="shown === 'agreement'" @submit.prevent="submitAgreement" class="grid max-w-md gap-4">
            <p class="font-medium">{{ agreement.title }}</p>
            <p class="text-muted-foreground text-sm whitespace-pre-line">{{ agreement.body }}</p>

            <label class="flex items-center gap-2 text-sm">
                <input type="checkbox" v-model="agreementForm.accepted" required />
                I accept the tutor agreement (version {{ agreement.current_version }})
            </label>
            <InputError :message="agreementForm.errors.accepted" />
            <InputError :message="agreementForm.errors.version" />

            <Button type="submit" :disabled="agreementForm.processing" class="w-fit">
                <Spinner v-if="agreementForm.processing" />
                Continue
            </Button>
        </form>

        <div v-else-if="shown === 'complete'" class="grid max-w-md gap-4">
            <p class="font-medium">You're all set.</p>
            <p class="text-muted-foreground text-sm">Submit your profile for review — an admin will check your documents and approve you.</p>

            <Button :disabled="completeForm.processing" class="w-fit" @click="submitComplete">
                <Spinner v-if="completeForm.processing" />
                Submit for review
            </Button>
        </div>

        <div v-else class="max-w-md">
            <p class="font-medium">{{ lockedTitle }}</p>
            <p class="text-muted-foreground text-sm">{{ lockedText }}</p>
        </div>
    </div>
</template>
