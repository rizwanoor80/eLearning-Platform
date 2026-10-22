<?php

namespace App\Http\Controllers\Settings;

use App\Actions\Account\RequestAccountDeletion;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * R273: a parent or tutor asks, from Settings, for an admin to erase their account. Nothing is erased here
 * (`EraseAccount` is admin-only); the request waits in the admin queue and the requester is told by email.
 */
class AccountDeletionController extends Controller
{
    public function store(Request $request, RequestAccountDeletion $requestAccountDeletion): RedirectResponse
    {
        $requestAccountDeletion($request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Request received. Nothing has been deleted yet: our team reviews every request, and we have emailed you a confirmation.')]);

        return to_route('profile.edit');
    }
}
