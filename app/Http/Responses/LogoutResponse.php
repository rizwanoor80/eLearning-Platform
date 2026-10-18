<?php

namespace App\Http\Responses;

use Inertia\Inertia;
use Laravel\Fortify\Contracts\LogoutResponse as LogoutResponseContract;
use Laravel\Fortify\Http\Responses\LogoutResponse as FortifyLogoutResponse;

/**
 * R227: Fortify's sign-out, plus `Inertia::clearHistory()`. The flag is kept in the session, and Fortify has
 * already invalidated the old one by the time this runs, so it lands in the new guest session and rides on the
 * first page after the redirect, where the browser discards the key its history was sealed with.
 */
class LogoutResponse implements LogoutResponseContract
{
    public function toResponse($request)
    {
        Inertia::clearHistory();

        return (new FortifyLogoutResponse)->toResponse($request);
    }
}
