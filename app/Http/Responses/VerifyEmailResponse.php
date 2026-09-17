<?php

namespace App\Http\Responses;

use App\Support\RoleRedirect;
use Illuminate\Http\Request;
use Laravel\Fortify\Contracts\VerifyEmailResponse as VerifyEmailResponseContract;
use Symfony\Component\HttpFoundation\Response;

class VerifyEmailResponse implements VerifyEmailResponseContract
{
    public function toResponse($request): Response
    {
        /** @var Request $request */
        return redirect()->intended(
            route(RoleRedirect::routeName($request->user())).'?verified=1'
        );
    }
}
