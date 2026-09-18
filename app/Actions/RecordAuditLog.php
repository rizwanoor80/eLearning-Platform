<?php

namespace App\Actions;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class RecordAuditLog
{
    /**
     * Writes one append-only audit row. Callers pass only the fields that
     * actually changed in `before`/`after` — never a full model dump — so a
     * reviewer can see what happened at a glance.
     *
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     */
    public function __invoke(User $actor, string $action, Model $subject, ?array $before = null, ?array $after = null): AuditLog
    {
        return AuditLog::query()->create([
            'actor_user_id' => $actor->id,
            'action' => $action,
            'subject_type' => $subject::class,
            'subject_id' => $subject->getKey(),
            'before' => $before,
            'after' => $after,
        ]);
    }
}
