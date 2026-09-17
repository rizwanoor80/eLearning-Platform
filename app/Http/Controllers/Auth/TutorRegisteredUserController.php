<?php

namespace App\Http\Controllers\Auth;

use App\Concerns\PasswordValidationRules;
use App\Concerns\ProfileValidationRules;
use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Contracts\Auth\StatefulGuard;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;

class TutorRegisteredUserController extends Controller
{
    use PasswordValidationRules, ProfileValidationRules;

    /**
     * Show the tutor registration page.
     */
    public function create(): Response
    {
        return Inertia::render('auth/TutorRegister', [
            'passwordRules' => Password::defaults()->toPasswordRulesString(),
        ]);
    }

    /**
     * Handle a tutor registration request.
     *
     * The role is always tutor here, forced server-side. Any 'role' key
     * present in the request is never read.
     */
    public function store(Request $request, StatefulGuard $guard): RedirectResponse
    {
        Validator::make($request->all(), [
            ...$this->profileRules(),
            'password' => $this->passwordRules(),
        ])->validate();

        $user = User::create([
            'name' => $request->string('name')->value(),
            'email' => $request->string('email')->value(),
            'password' => $request->string('password')->value(),
        ]);

        $user->forceFill(['role' => Role::Tutor])->save();

        event(new Registered($user));

        $guard->login($user);

        return redirect()->route('tutor.onboarding');
    }
}
