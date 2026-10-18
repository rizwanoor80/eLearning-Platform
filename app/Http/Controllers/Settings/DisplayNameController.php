<?php

namespace App\Http\Controllers\Settings;

use App\Actions\Tutor\RequestDisplayName;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\RequestDisplayNameRequest;
use App\Models\TutorProfile;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

/**
 * R195: a tutor asks for a display name from Settings. The request waits for an admin
 * (`ApproveDisplayName` / `RejectDisplayName`); until then parents keep seeing the name they see now (the
 * approved display name, else the first name).
 */
class DisplayNameController extends Controller
{
    public function store(RequestDisplayNameRequest $request, RequestDisplayName $requestDisplayName): RedirectResponse
    {
        $user = $request->user();

        // 18d: a local variable, not `$user->tutorProfile`, so the profile is never loaded onto the request user.
        $profile = TutorProfile::query()->where('user_id', $user->id)->firstOrFail();

        $requestDisplayName($user, $profile, $request->validated('display_name'));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Display name sent for approval. Parents keep seeing the name they see now until it is approved.')]);

        return to_route('profile.edit');
    }
}
