<?php

namespace App\Console\Commands;

use App\Actions\RecordAuditLog;
use App\Enums\TutorProfileStatus;
use App\Events\Tutor\TutorPermitExpired;
use App\Events\Tutor\TutorPermitExpiring;
use App\Models\AuditLog;
use App\Models\TutorProfile;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Date;

class CheckTutorPermits extends Command
{
    protected $signature = 'tutors:check-permits';

    protected $description = 'Email approved tutors whose permit is expiring (30d, 7d) or has expired';

    /**
     * "Auto-hide on expiry" needs no code here: `TutorProfile::bookable()`
     * (invariant #5) already excludes a tutor whose permit is not strictly
     * in the future, so this job only notifies — it never changes status or
     * re-implements bookability.
     *
     * Idempotent (safe to run twice): each notice is recorded as an audit
     * row keyed by (profile, action, permit_expires_at), so a repeat run —
     * or a missed day catching up — never re-sends, while a renewed permit
     * (new expiry date) starts a fresh warning cycle.
     */
    public function handle(RecordAuditLog $recordAuditLog): int
    {
        $today = Date::today();

        TutorProfile::query()
            ->where('status', TutorProfileStatus::Approved)
            ->whereNotNull('permit_expires_at')
            ->each(function (TutorProfile $profile) use ($today, $recordAuditLog): void {
                $expiresAt = $profile->permit_expires_at;

                if ($expiresAt === null) {
                    return;
                }

                $daysRemaining = (int) $today->diffInDays($expiresAt, false);

                if ($daysRemaining <= 0) {
                    if ($this->alreadyNotified($profile, 'tutor.permit_expired')) {
                        return;
                    }

                    $recordAuditLog(null, 'tutor.permit_expired', $profile, null, [
                        'permit_expires_at' => $expiresAt->toDateString(),
                    ]);
                    TutorPermitExpired::dispatch($profile);

                    return;
                }

                $action = $daysRemaining <= 7 ? 'tutor.permit_warning_7d' : ($daysRemaining <= 30 ? 'tutor.permit_warning_30d' : null);

                if ($action === null || $this->alreadyNotified($profile, $action)) {
                    return;
                }

                $recordAuditLog(null, $action, $profile, null, [
                    'permit_expires_at' => $expiresAt->toDateString(),
                ]);
                TutorPermitExpiring::dispatch($profile, $daysRemaining);
            });

        return self::SUCCESS;
    }

    private function alreadyNotified(TutorProfile $profile, string $action): bool
    {
        return AuditLog::query()
            ->where('action', $action)
            ->where('subject_type', TutorProfile::class)
            ->where('subject_id', $profile->id)
            ->where('after->permit_expires_at', $profile->permit_expires_at?->toDateString())
            ->exists();
    }
}
