<?php

namespace App\Support\Mail;

use App\Enums\Role;
use App\Enums\UserStatus;
use App\Models\User;
use App\Support\Facades\Settings;

class AdminRecipients
{
    /**
     * Where admin-side notices go (PRD §8 "Tutor + admin"): the
     * `support_address` setting when set, otherwise every active admin.
     *
     * @return array<int, string>
     */
    public static function resolve(): array
    {
        $support = Settings::get('support_address');

        if (is_string($support) && $support !== '') {
            return [$support];
        }

        return User::query()
            ->where('role', Role::Admin)
            ->where('status', UserStatus::Active)
            ->pluck('email')
            ->all();
    }
}
