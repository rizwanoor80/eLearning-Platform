<?php

namespace App\Http\Controllers\Settings;

use App\Enums\AccountDeletionRequestStatus;
use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\ProfileUpdateRequest;
use App\Models\AccountDeletionRequest;
use App\Models\TutorProfile;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * No self-service `destroy()`: R54 makes account deletion an admin-only,
 * audited action (`App\Actions\Account\EraseAccount`, which runs
 * `App\Actions\Admin\AnonymizeUser`) for both parents and tutors, never
 * something a signed-in user does to their own row. R273: a parent or tutor can
 * only ask for it (`AccountDeletionController`); this page shows the state of
 * that request.
 */
class ProfileController extends Controller
{
    /**
     * Show the user's profile settings page.
     */
    public function edit(Request $request): Response
    {
        return Inertia::render('settings/Profile', [
            'mustVerifyEmail' => $request->user() instanceof MustVerifyEmail,
            'status' => $request->session()->get('status'),
            'tutorDisplayName' => $this->tutorDisplayName($request),
            'accountDeletion' => $this->accountDeletion($request),
        ]);
    }

    /**
     * R273: the state of this account's latest deletion request, for its owner only. Null for an admin (admins
     * disable, not delete) so the section does not render. A declined request shows the admin's note until a new
     * request is filed; a declined one does not stop the next.
     *
     * @return array{status: string|null, requested_at: string|null, handled_at: string|null, note: string|null}|null
     */
    private function accountDeletion(Request $request): ?array
    {
        $user = $request->user();

        if ($user->role === Role::Admin) {
            return null;
        }

        $latest = AccountDeletionRequest::query()->where('user_id', $user->id)->latest('id')->first();

        return [
            'status' => $latest?->status->value,
            'requested_at' => $latest?->requested_at->setTimezone($user->timezone)->format('D, j M Y, g:i A'),
            'handled_at' => $latest?->handled_at?->setTimezone($user->timezone)->format('D, j M Y, g:i A'),
            'note' => $latest?->status === AccountDeletionRequestStatus::Declined ? $latest->admin_note : null,
        ];
    }

    /**
     * R195: what a tutor sees about their own display name — the name parents see now, the approved name
     * (null while parents see the first name) and the request waiting for an admin. Null for anyone without a
     * tutor profile, so the section does not render. The pending request is shown to its owner only.
     *
     * @return array{shown: string, approved: string|null, requested: string|null}|null
     */
    private function tutorDisplayName(Request $request): ?array
    {
        // A query, not `$user->tutorProfile`: that would load the relation onto the user object, which
        // `HandleInertiaRequests` shares with every page as `auth.user`, bank fields included.
        $profile = TutorProfile::query()->where('user_id', $request->user()->id)->first();

        if ($profile === null) {
            return null;
        }

        return [
            'shown' => $profile->displayName(),
            'approved' => $profile->display_name,
            'requested' => $profile->display_name_requested,
        ];
    }

    /**
     * Update the user's profile information.
     */
    public function update(ProfileUpdateRequest $request): RedirectResponse
    {
        $request->user()->fill($request->validated());

        if ($request->user()->isDirty('email')) {
            $request->user()->email_verified_at = null;
        }

        // Saving fires UserObserver, which keeps an adult student's learner name in step.
        $request->user()->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Profile updated.')]);

        return to_route('profile.edit');
    }
}
