<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\Conversation;
use App\Models\User;

/**
 * Only the two parties read or post in a conversation. Everyone else — another family, another tutor,
 * an admin on the portal routes — is answered with a 404 by the caller, so a conversation's existence is
 * not disclosed. The admin's read-only view is a Filament resource that does not go through this
 * policy, and is audited (R140).
 */
class ConversationPolicy
{
    public function viewAny(User $user): bool
    {
        return in_array($user->role, [Role::AccountOwner, Role::Tutor], true);
    }

    public function view(User $user, Conversation $conversation): bool
    {
        return $this->viewAny($user) && $conversation->hasParty($user);
    }

    public function send(User $user, Conversation $conversation): bool
    {
        return $this->view($user, $conversation);
    }
}
