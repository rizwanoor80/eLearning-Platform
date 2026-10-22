<script setup lang="ts">
import { Form, Head, usePage } from '@inertiajs/vue3';
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import AccountDeletionController from '@/actions/App/Http/Controllers/Settings/AccountDeletionController';
import DisplayNameController from '@/actions/App/Http/Controllers/Settings/DisplayNameController';
import ProfileController from '@/actions/App/Http/Controllers/Settings/ProfileController';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { edit } from '@/routes/profile';
import { send } from '@/routes/verification';

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Profile settings',
                href: edit(),
            },
        ],
    },
});

defineProps<{
    tutorDisplayName: {
        shown: string;
        approved: string | null;
        requested: string | null;
    } | null;
    accountDeletion: {
        status: 'open' | 'erased' | 'declined' | null;
        requested_at: string | null;
        handled_at: string | null;
        note: string | null;
    } | null;
}>();

const page = usePage();
const user = computed(() => page.props.auth.user);
</script>

<template>
    <Head title="Profile settings" />

    <h1 class="sr-only">Profile settings</h1>

    <div class="flex flex-col space-y-6">
        <Heading
            variant="small"
            title="Profile"
            description="Update your name and email address"
        />

        <Form
            v-bind="ProfileController.update.form()"
            class="space-y-6"
            v-slot="{ errors, processing }"
        >
            <div class="grid gap-2">
                <Label for="name">Name</Label>
                <Input
                    id="name"
                    class="mt-1 block w-full"
                    name="name"
                    :default-value="user.name"
                    required
                    autocomplete="name"
                    placeholder="Full name"
                />
                <InputError class="mt-2" :message="errors.name" />
            </div>

            <div class="grid gap-2">
                <Label for="email">Email address</Label>
                <Input
                    id="email"
                    type="email"
                    class="mt-1 block w-full"
                    name="email"
                    :default-value="user.email"
                    required
                    autocomplete="username"
                    placeholder="Email address"
                />
                <InputError class="mt-2" :message="errors.email" />
            </div>

            <div v-if="page.props.mustVerifyEmail && !user.email_verified_at">
                <p class="text-muted-foreground -mt-4 text-sm">
                    Your email address is unverified.
                    <Link
                        :href="send()"
                        as="button"
                        class="text-foreground underline decoration-neutral-300 underline-offset-4 transition-colors duration-300 ease-out hover:decoration-current! dark:decoration-neutral-500"
                    >
                        Click here to re-send the verification email.
                    </Link>
                </p>

                <div
                    v-if="page.props.status === 'verification-link-sent'"
                    class="mt-2 text-sm font-medium text-green-600"
                >
                    A new verification link has been sent to your email address.
                </div>
            </div>

            <div class="flex items-center gap-4">
                <Button :disabled="processing" data-test="update-profile-button"
                    >Save</Button
                >
            </div>
        </Form>

        <div
            v-if="tutorDisplayName"
            class="flex flex-col space-y-6"
            data-test="display-name-section"
        >
            <Heading
                variant="small"
                title="Display name"
                description="Parents see your first name unless an admin approves a display name you ask for"
            />

            <p class="text-sm" data-test="display-name-shown">
                Parents currently see: <strong>{{ tutorDisplayName.shown }}</strong>
            </p>

            <p
                v-if="tutorDisplayName.requested"
                class="text-muted-foreground text-sm"
                data-test="display-name-pending"
            >
                Waiting for approval:
                <strong>{{ tutorDisplayName.requested }}</strong>. Parents do
                not see it until an admin approves it.
            </p>

            <Form
                v-bind="DisplayNameController.store.form()"
                class="space-y-6"
                v-slot="{ errors, processing }"
            >
                <div class="grid gap-2">
                    <Label for="display_name">Ask for a display name</Label>
                    <Input
                        id="display_name"
                        class="mt-1 block w-full"
                        name="display_name"
                        :default-value="tutorDisplayName.requested ?? ''"
                        required
                        autocomplete="off"
                        placeholder="2 to 30 characters"
                    />
                    <InputError
                        class="mt-2"
                        :message="errors.display_name"
                    />
                </div>

                <Button
                    :disabled="processing"
                    data-test="request-display-name-button"
                    >Send for approval</Button
                >
            </Form>
        </div>

        <div
            v-if="accountDeletion"
            class="flex flex-col space-y-6"
            data-test="account-deletion-section"
        >
            <Heading
                variant="small"
                title="Delete your account"
                description="Ask us to erase your account. Nothing is deleted until our team has reviewed your request"
            />

            <p
                v-if="accountDeletion.status === 'open'"
                class="text-sm"
                data-test="account-deletion-open"
            >
                You asked for your account to be deleted on
                {{ accountDeletion.requested_at }}. Our team is reviewing it.
                If we cannot go ahead we will email you why; otherwise your
                account is simply erased. You do not need to ask again.
            </p>

            <template v-else>
                <div
                    v-if="
                        accountDeletion.status === 'declined' &&
                        accountDeletion.note
                    "
                    class="text-sm"
                    data-test="account-deletion-declined"
                >
                    <p>
                        Your request on {{ accountDeletion.requested_at }}
                        was not carried out yet. Our team wrote:
                    </p>
                    <blockquote
                        class="border-s-2 ps-4 py-1 mt-2 whitespace-pre-line"
                    >
                        {{ accountDeletion.note }}
                    </blockquote>
                </div>

                <p class="text-muted-foreground text-sm">
                    We cannot erase an account while a lesson is still open, and we
                    may ask you to wait if money is still owed on it. Past lessons and payments are
                    kept for our records, without your name.
                </p>

                <Form
                    v-bind="AccountDeletionController.store.form()"
                    class="space-y-6"
                    v-slot="{ errors, processing }"
                >
                    <InputError :message="errors.account" />
                    <Button
                        variant="destructive"
                        :disabled="processing"
                        data-test="request-account-deletion-button"
                        >Request account deletion</Button
                    >
                </Form>
            </template>
        </div>
    </div>
</template>
