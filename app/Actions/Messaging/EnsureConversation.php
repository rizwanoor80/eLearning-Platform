<?php

namespace App\Actions\Messaging;

use App\Models\Conversation;
use App\Models\Lesson;

/**
 * R133: the conversation between a lesson's account holder and its tutor profile. Idempotent and safe
 * to run twice at once: the insert ignores the unique pair, the read that follows always finds the row.
 * The account holder is the learner's `account_user_id` — a minor has no login (invariant #7).
 */
class EnsureConversation
{
    public function __invoke(Lesson $lesson): Conversation
    {
        $accountId = $lesson->learner->account_user_id;

        Conversation::query()->insertOrIgnore([
            'account_user_id' => $accountId,
            'tutor_profile_id' => $lesson->tutor_profile_id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return Conversation::query()
            ->where('account_user_id', $accountId)
            ->where('tutor_profile_id', $lesson->tutor_profile_id)
            ->firstOrFail();
    }
}
