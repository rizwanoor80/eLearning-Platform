<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';

// CP7 8d (R137): the report button shared by a tutor profile, a lesson page, and a conversation —
// each passes its own POST url (a literal template string, matching this codebase's convention;
// there is no Ziggy `route()` helper here) and the server-built reason options for that party.
// Authorization is never assumed here: the server re-checks on submit and a non-party gets a 404,
// so this component only ever renders when the page already decided the viewer may see it.
const props = defineProps<{
    postUrl: string;
    reasons: Array<{ value: string; label: string }>;
}>();

const isOpen = ref(false);

const form = useForm({
    reason: '',
    description: '',
});

function submit() {
    form.post(props.postUrl, {
        preserveScroll: true,
        onSuccess: () => {
            isOpen.value = false;
            form.reset();
        },
    });
}
</script>

<template>
    <Button variant="outline" size="sm" data-test="report-abuse-button" @click="isOpen = true">Report a concern</Button>

    <Dialog :open="isOpen" @update:open="isOpen = $event">
        <DialogContent class="sm:max-w-md">
            <DialogHeader>
                <DialogTitle>Report a concern</DialogTitle>
                <DialogDescription>
                    This goes to our safeguarding team, not to the person you're reporting. They are never told who filed it.
                </DialogDescription>
            </DialogHeader>

            <form class="grid gap-4" data-test="report-abuse-form" @submit.prevent="submit">
                <div class="grid gap-2">
                    <Label for="abuse_report_reason">Reason</Label>
                    <select id="abuse_report_reason" v-model="form.reason" required class="border-input rounded-md border p-2 text-sm">
                        <option value="" disabled>Select</option>
                        <option v-for="option in reasons" :key="option.value" :value="option.value">{{ option.label }}</option>
                    </select>
                    <InputError :message="form.errors.reason" />
                </div>

                <div class="grid gap-2">
                    <Label for="abuse_report_description">What happened</Label>
                    <textarea
                        id="abuse_report_description"
                        v-model="form.description"
                        rows="4"
                        maxlength="2000"
                        required
                        class="border-input rounded-md border p-2 text-sm"
                    />
                    <InputError :message="form.errors.description" />
                </div>

                <DialogFooter>
                    <Button type="button" variant="outline" :disabled="form.processing" @click="isOpen = false">Cancel</Button>
                    <Button type="submit" :disabled="form.processing" data-test="report-abuse-submit">
                        <Spinner v-if="form.processing" />
                        Submit report
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
