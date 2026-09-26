<?php

namespace App\Services\Scheduling;

use App\Enums\LessonStatus;
use App\Enums\LessonType;
use App\Models\AvailabilityRule;
use App\Models\Learner;
use App\Models\Lesson;
use App\Models\ProgressReport;
use App\Models\TutorProfile;

/**
 * What the weekly-slot form starts with when it is reached from a trial report (the email link, the
 * learner page): the trial lesson's subject, the same weekday and hour if the tutor still offers it,
 * and the number of lessons a week the tutor recommended. Every value is taken from the learner's own
 * completed trial with this tutor and matched against what the form offers, so a value the form would
 * not accept is never suggested; nothing here reaches the action, which still checks everything.
 */
class TrialSlotPrefill
{
    /**
     * @param  array<int, array{curriculum_id: int, subject_id: int}>  $subjects  the tutor's teachable pairs
     * @param  array<int, array{value: string}>  $options  from `WeeklySlotOptions::forTutor()`
     * @return array{subject: string|null, slot: string|null, frequency: int|null}|null null when there is no completed trial
     */
    public function for(Learner $learner, TutorProfile $tutor, array $subjects, array $options): ?array
    {
        $trial = Lesson::query()
            ->where('learner_id', $learner->id)
            ->where('tutor_profile_id', $tutor->id)
            ->where('type', LessonType::Trial)
            ->whereIn('status', [LessonStatus::Completed, LessonStatus::CompletedReported, LessonStatus::Settled])
            ->orderByDesc('starts_at')
            ->orderByDesc('id')
            ->first();

        if ($trial === null) {
            return null;
        }

        $subject = collect($subjects)->first(
            fn (array $row): bool => $row['curriculum_id'] === $trial->curriculum_id && $row['subject_id'] === $trial->subject_id,
        );

        $offered = array_column($options, 'value');
        $slot = null;

        foreach (AvailabilityRule::query()->where('tutor_profile_id', $tutor->id)->distinct()->pluck('timezone') as $timezone) {
            $local = $trial->starts_at->setTimezone($timezone);
            $value = $local->dayOfWeek.'|'.$local->format('H:i');

            if (in_array($value, $offered, true)) {
                $slot = $value;
                break;
            }
        }

        $frequency = ProgressReport::query()->where('lesson_id', $trial->id)->value('trial_recommended_frequency');

        return [
            'subject' => $subject === null ? null : $subject['curriculum_id'].'|'.$subject['subject_id'],
            'slot' => $slot,
            'frequency' => $frequency === null ? null : (int) $frequency,
        ];
    }
}
