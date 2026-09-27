<?php

namespace App\Services\Search;

use App\Models\Review;
use App\Models\TutorProfile;
use App\Services\Scheduling\Slot;
use App\Support\Facades\Settings;

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
            ...($showReviews ? ['reviews' => $this->reviews($tutor)] : []),
        ];
    }

    /**
     * Published reviews only, latest first, 10 per page (R136). The reviewer is never a learner
     * (invariant #7) — always the account holder, shown as "First L." only, never a full name; a
     * one-word name (or an anonymised "Deleted user") degrades to its first word alone rather than
     * guessing at a last name. `reviews_page` is a distinct query-string name so it can never collide
     * with another paginator on the same page.
     *
     * @return array<string, mixed>
     */
    private function reviews(TutorProfile $tutor): array
    {
        $reviews = Review::query()
            ->where('tutor_profile_id', $tutor->id)
            ->whereNotNull('published_at')
            ->with('account:id,name')
            ->latest('id')
            ->paginate(10, ['*'], 'reviews_page');

        return [
            'data' => $reviews->getCollection()->map(fn (Review $review): array => [
                'rating' => $review->rating,
                'comment' => $review->comment,
                'reviewer' => $this->reviewerLabel($review->account?->name),
                'published_at' => $review->published_at?->toIso8601String(),
                'date_label' => $review->published_at?->format('j M Y'),
            ])->all(),
            'current_page' => $reviews->currentPage(),
            'last_page' => $reviews->lastPage(),
        ];
    }

    /**
     * "First L." — never a full name, and never a guess at a surname a one-word name doesn't have.
     */
    private function reviewerLabel(?string $name): string
    {
        $name = trim((string) $name);

        if ($name === '') {
            return __('A parent');
        }

        $parts = preg_split('/\s+/u', $name) ?: [];

        if (count($parts) < 2) {
            return $parts[0];
        }

        $last = (string) end($parts);

        return $parts[0].' '.mb_substr($last, 0, 1).'.';
    }

    /**
     * @return array<string, mixed>
     */
    private function identity(TutorProfile $tutor): array
    {
        $currency = (string) Settings::get('currency_code');

        return [
            'id' => $tutor->id,
            // First name only (R32): one accessor, shared with the suggestions email.
            'name' => $tutor->displayName(),
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
                'level_min' => $row->levelMinLabel(),
                'level_max' => $row->levelMaxLabel(),
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
