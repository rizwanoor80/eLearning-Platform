<?php

namespace App\Http\Responses;

use App\Support\RoleRedirect;
use Illuminate\Http\Request;
use Laravel\Fortify\Contracts\LoginResponse as LoginResponseContract;
use Symfony\Component\HttpFoundation\Response;

class LoginResponse implements LoginResponseContract
{
    public function toResponse($request): Response
    {
        /** @var Request $request */
        return redirect()->intended(
            route(RoleRedirect::routeName($request->user()))
        );
    }
}
