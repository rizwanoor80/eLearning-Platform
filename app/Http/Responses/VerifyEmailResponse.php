<?php

namespace App\Http\Responses;

use App\Enums\Role;
use App\Models\Learner;
use App\Models\User;
use App\Support\RoleRedirect;
use Illuminate\Http\Request;
use Laravel\Fortify\Contracts\VerifyEmailResponse as VerifyEmailResponseContract;
use Symfony\Component\HttpFoundation\Response;

class VerifyEmailResponse implements VerifyEmailResponseContract
{
    public function toResponse($request): Response
    {
        /** @var Request $request */
        /** @var User $user */
        $user = $request->user();

        return redirect()->intended(route($this->landingRoute($user)).'?verified=1');
    }

    /**
     * R186: a parent who has not added a learner yet lands on "Get started" (what to do first);
     * everyone else on their usual landing page. An intended URL (e.g. a booking they started) still wins.
     */
    private function landingRoute(User $user): string
    {
        if ($user->role === Role::AccountOwner && ! Learner::query()->where('account_user_id', $user->id)->exists()) {
            return 'get-started';
        }

        return RoleRedirect::routeName($user);
    }
}
