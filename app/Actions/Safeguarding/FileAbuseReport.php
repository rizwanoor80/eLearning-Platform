<?php

namespace App\Actions\Safeguarding;

use App\Enums\AbuseReportReason;
use App\Enums\AbuseReportSubjectType;
use App\Enums\UserStatus;
use App\Events\Safeguarding\AbuseReportFiled;
use App\Exceptions\AbuseReportException;
use App\Models\AbuseReport;
use App\Models\Conversation;
use App\Models\Lesson;
use App\Models\TutorProfile;
use App\Models\User;

/**
 * CP7 8d (R137): files a report from the route-bound subject model, never a client-supplied
 * id — the controller passes the `TutorProfile`/`Lesson`/`Conversation` Laravel already
 * resolved and policy-checked from the URL, and `subject_id` here is always that model's own
 * key. `description` is never masked (invariant #8 masks messages/reviews for other users;
 * this text is admin-only). Filing never notifies the reported party — only
 * `AbuseReportFiled` (admins) is dispatched.
 */
class FileAbuseReport
{
    /**
     * @throws AbuseReportException
     */
    public function __invoke(
        User $reporter,
        TutorProfile|Lesson|Conversation|User $subject,
        AbuseReportReason $reason,
        string $description,
    ): AbuseReport {
        // Defence in depth: the route middleware already logs a suspended user out, but a
        // request already in flight when that happens must not still create a report.
        if ($reporter->status !== UserStatus::Active) {
            throw new AbuseReportException('Your account is not able to file a report right now.');
        }

        $subjectType = match (true) {
            $subject instanceof TutorProfile => AbuseReportSubjectType::TutorProfile,
            $subject instanceof User => AbuseReportSubjectType::User,
            $subject instanceof Lesson => AbuseReportSubjectType::Lesson,
            // The parameter's own union type is closed to these four classes, so by this arm
            // $subject can only be a Conversation — PHPStan flags a fourth `instanceof` check
            // (and the `default => throw` it would guard) as unreachable, not as a missing case.
            default => AbuseReportSubjectType::Conversation,
        };

        $report = AbuseReport::query()->create([
            'reporter_user_id' => $reporter->id,
            'subject_type' => $subjectType,
            'subject_id' => $subject->getKey(),
            'reason' => $reason,
            'description' => $description,
        ]);

        AbuseReportFiled::dispatch($report);

        return $report;
    }
}
