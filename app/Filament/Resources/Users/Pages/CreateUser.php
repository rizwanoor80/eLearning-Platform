<?php

namespace App\Filament\Resources\Users\Pages;

use App\Actions\RecordAuditLog;
use App\Enums\Role;
use App\Filament\Resources\Users\UserResource;
use App\Models\User;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateUser extends CreateRecord
{
    protected static string $resource = UserResource::class;

    /**
     * The role is forced to admin here, server-side — never read from form
     * input (`role` is not mass-assignable). The new admin's email is marked
     * verified: an existing admin vouches for them, and there is no
     * self-registration route to verify through.
     *
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        $user = new User;
        $user->fill($data);
        $user->forceFill([
            'role' => Role::Admin,
            'email_verified_at' => now(),
        ])->save();

        app(RecordAuditLog::class)(auth()->user(), 'admin_user.created', $user, null, [
            'email' => $user->email,
        ]);

        return $user;
    }
}
