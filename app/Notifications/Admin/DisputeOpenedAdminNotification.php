<?php

namespace App\Notifications\Admin;

use App\Filament\Resources\Disputes\DisputeResource;
use App\Models\Dispute;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * CP8 (R150/PRD §8, R139): the admin bell for "a dispute was opened" — sent to every active admin
 * `User` row directly (not via `AdminRecipients::resolve()`, which returns email strings and, once
 * `support_address` is set, no `User` at all — see the mail-side listener for that path). Carries
 * no part of the account holder's free-text `description`, same as the tutor's bell.
 *
 * The link target is the Filament Disputes list, not `lessons.show`: an admin is never a party to
 * the lesson (`LessonPolicy::attend` — tutor or account holder only), so that route would 403 them.
 * `DisputeResource` has no `view` page (open -> resolved happens as a table action), so `index` is
 * the only page there is.
 */
class DisputeOpenedAdminNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(private readonly Dispute $dispute) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        $lesson = $this->dispute->lesson;

        return [
            'dispute_id' => $this->dispute->id,
            'tutor_display_name' => $lesson->tutorProfile->displayName(),
            'learner_display_name' => $lesson->learner->display_name,
            'reason' => $this->dispute->reason->value,
            'url' => DisputeResource::getUrl('index'),
        ];
    }
}
