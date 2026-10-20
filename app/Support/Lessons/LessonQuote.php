<?php

namespace App\Support\Lessons;

use App\Enums\LessonStatus;
use App\Enums\LessonType;
use App\Models\Learner;
use App\Models\Lesson;
use App\Models\TutorProfile;
use App\Support\Facades\Settings;
use App\Support\Money;

/**
 * What `BookLesson` will decide for a learner and tutor, in the same terms and from the same data: the
 * first lesson of the pair that is not in a slot-freeing status is the trial, at `trialPrice()`,
 * otherwise a regular lesson at the hourly rate. Display and checkout only — the action decides again
 * at booking time and freezes the price it computes. The booking screen (R114) and the Stripe checkout
 * (R248) both ask it, so the amount a parent authorises is the amount the page showed.
 */
class LessonQuote
{
    /**
     * @return array<string, mixed> `type`, `type_label`, `price` (formatted), `amount_fils` and `quote_token`
     */
    public function describe(Learner $learner, TutorProfile $tutor): array
    {
        [$isTrial, $price] = $this->decide($learner, $tutor);

        return [
            'type' => $isTrial ? LessonType::Trial->value : LessonType::Regular->value,
            'type_label' => $isTrial ? __('Trial lesson') : __('Lesson'),
            'price' => $price?->format((string) Settings::get('currency_code')),
            'amount_fils' => $price?->toFils(),
            'quote_token' => $this->token($learner, $tutor),
        ];
    }

    /**
     * @return array{0: bool, 1: Money|null} whether the next lesson would be the trial, and its price
     */
    public function decide(Learner $learner, TutorProfile $tutor): array
    {
        $isTrial = ! Lesson::query()
            ->where('learner_id', $learner->id)
            ->where('tutor_profile_id', $tutor->id)
            ->whereNotIn('status', LessonStatus::freeingSlotValues())
            ->exists();

        return [$isTrial, $isTrial ? $tutor->trialPrice() : $tutor->hourly_rate];
    }

    /**
     * An opaque, keyed fingerprint of the quote (learner, tutor, type, price in fils, currency). The page
     * carries it and the form returns it; the browser can neither read a type or price out of it nor
     * choose one — a wrong or stale token is only ever refused.
     */
    public function token(Learner $learner, TutorProfile $tutor): string
    {
        [$isTrial, $price] = $this->decide($learner, $tutor);

        return hash_hmac('sha256', implode('|', [
            $learner->id,
            $tutor->id,
            $isTrial ? LessonType::Trial->value : LessonType::Regular->value,
            $price?->toFils() ?? 'none',
            (string) Settings::get('currency_code'),
        ]), (string) config('app.key'));
    }

    public function isCurrent(Learner $learner, TutorProfile $tutor, string $token): bool
    {
        return hash_equals($this->token($learner, $tutor), $token);
    }
}
