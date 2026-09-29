<?php

namespace App\Listeners\Lessons;

use App\Enums\LessonStatus;
use App\Enums\Role;
use App\Enums\UserStatus;
use App\Events\Lessons\LessonStatusChanged;
use App\Models\User;
use App\Notifications\Admin\DisputeOpenedAdminNotification;
use App\Notifications\Lessons\DisputeOpenedNotification;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * CP8 (R150/R139): "both get a database notification" — the tutor's bell and every active admin's
 * bell, one listener standing in for both mail listeners' condition (`SendDisputeOpenedMail`,
 * `SendAdminDisputeOpenedMail`), matching `RecordLessonCancelledNotification`'s precedent shape.
 *
 * Admins are notified by querying active `User` rows directly, not through
 * `AdminRecipients::resolve()`: that helper returns email strings for the mail side and, once
 * `support_address` is set, no `User` at all — there would be nothing to call `->notify()` on.
 */
class RecordDisputeOpenedNotification implements ShouldQueue
{
    public function handle(LessonStatusChanged $event): void
    {
        if (! in_array($event->from, [LessonStatus::Completed, LessonStatus::CompletedReported], true)
            || $event->to !== LessonStatus::Disputed) {
            return;
        }

        $dispute = $event->lesson->dispute;

        if ($dispute === null) {
            return;
        }

        $event->lesson->tutorProfile->user->notify(new DisputeOpenedNotification($dispute));

        $admins = User::query()
            ->where('role', Role::Admin)
            ->where('status', UserStatus::Active)
            ->get();

        foreach ($admins as $admin) {
            $admin->notify(new DisputeOpenedAdminNotification($dispute));
        }
    }
}
