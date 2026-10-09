<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\Parents\ParentGetStarted;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * R186: the parent's "Get started" page — where email verification lands a parent who has no
 * learner yet, and what the dashboard banner links to. Read-only; each item links to the screen
 * that does the work.
 */
class GetStartedController extends Controller
{
    public function __invoke(Request $request, ParentGetStarted $getStarted): Response
    {
        /** @var User $user */
        $user = $request->user();

        return Inertia::render('GetStarted', [
            'checklist' => $getStarted->groups($user),
        ]);
    }
}
