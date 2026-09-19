<?php

namespace App\Services\Search;

use App\Models\TutorProfile;
use App\Services\Scheduling\Slot;
use App\Support\Facades\Settings;
use Illuminate\Support\Str;

/**
 * The public face of a tutor. Every field that leaves the server on a public
 * route is listed here on purpose (an allow-list, never `toArray()`): no
 * contact details, permit, bank, status, review note or document data.
 * Money is formatted here, on the server — the browser does no arithmetic.
 */
class TutorPresenter
{
    /**
     * @param  list<Slot>  $slots
     * @return array<string, mixed>
     */
    public function card(TutorProfile $tutor, array $slots): array
    {
        return [
            ...$this->identity($tutor),
            'subjects' => $this->subjects($tutor),
            'next_slot' => $slots === [] ? null : $this->slot($slots[0]),
        ];
    }

    /**
     * @param  list<Slot>  $slots
     * @return array<string, mixed>
     */
    public function profile(TutorProfile $tutor, array $slots, bool $showReviews): array
    {
        $video = $tutor->intro_video_url;

        return [
            ...$this->identity($tutor),
            'bio' => $tutor->bio,
            'intro_video_url' => $video !== null && preg_match('#^https?://#i', $video) === 1 ? $video : null,
            'subjects' => $this->subjects($tutor),
            'next_slots' => array_map(fn (Slot $slot): array => $this->slot($slot), array_slice($slots, 0, 12)),
            ...($showReviews ? ['reviews' => []] : []),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function identity(TutorProfile $tutor): array
    {
        $currency = (string) Settings::get('currency_code');

        return [
            'id' => $tutor->id,
            // First name only: the account name's first word (PRD §2.2 lists no name field).
            'name' => Str::before($tutor->user->name, ' '),
            'headline' => $tutor->headline,
            'rate' => $tutor->hourly_rate?->format($currency),
            'trial_price' => $tutor->trialPrice()?->format($currency),
            'rating_avg' => $tutor->rating_count > 0 ? (string) $tutor->rating_avg : null,
            'rating_count' => $tutor->rating_count,
        ];
    }

    /**
     * @return list<array{curriculum: string|null, subject: string|null, level_min: string, level_max: string}>
     */
    private function subjects(TutorProfile $tutor): array
    {
        $subjects = [];

        foreach ($tutor->tutorSubjects as $row) {
            $subjects[] = [
                'curriculum' => $row->curriculum?->name,
                'subject' => $row->subject?->name,
                'level_min' => $row->level_min,
                'level_max' => $row->level_max,
            ];
        }

        return $subjects;
    }

    /**
     * @return array{starts_at: string, label: string}
     */
    private function slot(Slot $slot): array
    {
        return [
            'starts_at' => $slot->startsAt->toIso8601String(),
            'label' => $slot->startsAt->format('D j M, H:i'),
        ];
    }
}
