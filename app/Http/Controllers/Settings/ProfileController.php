<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\ProfileUpdateRequest;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * No self-service `destroy()`: R54 makes account deletion an admin-only,
 * audited action (`App\Actions\Admin\AnonymizeUser`) for both parents and
 * tutors, never something a signed-in user does to their own row.
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
        ]);
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
        $profile = $request->user()->tutorProfile;

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
