<?php

namespace App\Support\Notifications;

use App\Models\User;
use App\Notifications\Admin\DisputeOpenedAdminNotification;
use App\Notifications\Lessons\DisputeOpenedNotification;
use App\Notifications\Lessons\LessonCancelledNotification;
use App\Notifications\Lessons\LessonConfirmedNotification;
use App\Notifications\Lessons\ProgressReportAvailableNotification;
use App\Notifications\Lessons\WeeklyChargeFailedNotification;
use App\Notifications\Messaging\NewMessageNotification;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Carbon;

/**
 * CP7 8e (R139): turns one stored `notifications` row into the plain `{message, url}` the list page and
 * the bell need — the one place this switch lives, so the Vue side never has to know a notification
 * `type` string. Timestamps in `data` are stored as raw UTC ISO strings (invariant 4: "convert at the
 * edge"); this class converts to the viewer's timezone only when presenting, never at write time.
 */
class NotificationPresenter
{
    /**
     * @return array<string, mixed>
     */
    public static function present(DatabaseNotification $notification, User $viewer): array
    {
        $data = $notification->data;

        [$message, $url] = match ($notification->type) {
            LessonConfirmedNotification::class => [
                'Lesson with '.($data['learner_display_name'] ?? 'a learner').
                    (($data['subject'] ?? null) ? ' on '.$data['subject'] : '').
                    ' is confirmed for '.self::label($data['starts_at'] ?? null, $viewer),
                self::lessonUrl($data),
            ],
            LessonCancelledNotification::class => [
                'Lesson with '.($data['learner_display_name'] ?? 'a learner').
                    (($data['subject'] ?? null) ? ' on '.$data['subject'] : '').
                    ' ('.self::label($data['starts_at'] ?? null, $viewer).') was cancelled',
                self::lessonUrl($data),
            ],
            ProgressReportAvailableNotification::class => [
                (($data['is_trial'] ?? false) ? 'Trial report' : 'Progress report').
                    ' available for '.($data['learner_display_name'] ?? 'a learner'),
                self::lessonUrl($data),
            ],
            WeeklyChargeFailedNotification::class => [
                'Card charge failed for '.($data['learner_display_name'] ?? 'a learner').
                    "'s lesson — please update your card. Next retry: ".self::label($data['retry_at'] ?? null, $viewer),
                self::lessonUrl($data),
            ],
            NewMessageNotification::class => [
                'New message from '.($data['sender_name'] ?? 'someone'),
                isset($data['conversation_id']) ? route('messages.show', $data['conversation_id']) : null,
            ],
            DisputeOpenedNotification::class => [
                'A dispute was opened on your lesson with '.($data['learner_display_name'] ?? 'a learner').
                    (($data['subject'] ?? null) ? ' on '.$data['subject'] : '').
                    ' ('.self::label($data['starts_at'] ?? null, $viewer).')',
                self::lessonUrl($data),
            ],
            DisputeOpenedAdminNotification::class => [
                'A dispute was opened: '.($data['tutor_display_name'] ?? 'a tutor').' and '.
                    ($data['learner_display_name'] ?? 'a learner'),
                $data['url'] ?? null,
            ],
            default => ['Notification', null],
        };

        return [
            'id' => $notification->id,
            'message' => $message,
            'url' => $url,
            'read' => $notification->read_at !== null,
            'created_at_label' => $notification->created_at?->copy()->setTimezone($viewer->timezone)->format('D, j M Y, g:i A'),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function lessonUrl(array $data): ?string
    {
        return isset($data['lesson_id']) ? route('lessons.show', $data['lesson_id']) : null;
    }

    private static function label(?string $iso, User $viewer): string
    {
        if ($iso === null) {
            return '';
        }

        return Carbon::parse($iso)->setTimezone($viewer->timezone)->format('D, j M Y, g:i A');
    }
}
