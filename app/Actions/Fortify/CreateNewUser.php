<?php

namespace App\Actions\Fortify;

use App\Actions\Learner\CreateSelfLearner;
use App\Concerns\PasswordValidationRules;
use App\Concerns\ProfileValidationRules;
use App\Enums\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Laravel\Fortify\Contracts\CreatesNewUsers;

class CreateNewUser implements CreatesNewUsers
{
    use PasswordValidationRules, ProfileValidationRules;

    /**
     * Validate and create a newly registered user.
     *
     * The role is always account_owner here: this action backs Fortify's
     * stock /register route only. Any 'role' in $input is ignored, never
     * read — the tutor entry point uses its own controller and action.
     *
     * @param  array<string, mixed>  $input
     */
    public function create(array $input): User
    {
        Validator::make($input, [
            ...$this->profileRules(),
            'password' => $this->passwordRules(),
            'is_adult_student' => ['sometimes', 'boolean'],
        ])->validate();

        return DB::transaction(function () use ($input): User {
            $user = User::create([
                'name' => $input['name'],
                'email' => $input['email'],
                'password' => $input['password'],
            ]);

            $user->forceFill(['role' => Role::AccountOwner])->save();

            // The only client-chosen part: whether the account owner is their own
            // (adult) student. It creates a learner row and grants nothing.
            if (! empty($input['is_adult_student'])) {
                (new CreateSelfLearner)($user);
            }

            return $user;
        });
    }
}
