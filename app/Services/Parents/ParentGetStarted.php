<?php

namespace App\Services\Parents;

use App\Actions\Payments\SaveTestCard;
use App\Models\Learner;
use App\Models\User;

/**
 * R186: a parent's "Get started" checklist, in the same shape as the tutor's (`TutorOnboardingChecklist`)
 * so one Vue component draws both. Required to book: one learner. Needed at the first booking: a card
 * (test mode until CP5 — the fake gateway's add-card page, which exists only where the fake gateway
 * does). Optional: a school and notes for tutors, and more learners.
 *
 * Every tick reads the rows the booking flow itself reads (`learners`, `payment_methods`), never a
 * second definition. Learners belong to the parent's account — a minor never has a login — so
 * nothing here is addressed to a learner.
 *
 * An item's `href` is where it goes; `null` means it cannot be actioned in this environment.
 */
class ParentGetStarted
{
    public const GROUP_REQUIRED = 'required';

    public const GROUP_BOOKING = 'booking';

    public const GROUP_OPTIONAL = 'optional';

    /**
     * @return list<array{key: string, title: string, hint: string, required: bool, complete: bool, items: list<array{key: string, label: string, step: string, done: bool, href: string|null}>}>
     */
    public function groups(User $parent): array
    {
        $learners = Learner::query()->where('account_user_id', $parent->id)->orderBy('id')->get(['id', 'school', 'notes']);
        $hasLearner = $learners->isNotEmpty();
        $hasCard = $parent->paymentMethod()->exists();

        $filled = fn (?string $value): bool => trim((string) $value) !== '';
        $detailsLearner = $learners->first(fn (Learner $learner): bool => ! $filled($learner->school) || ! $filled($learner->notes))
            ?? $learners->first();

        return [
            $this->group(self::GROUP_REQUIRED, 'To book a lesson', 'Lessons are booked for a learner, so add one first. A learner never needs a login of their own.', true, [
                $this->item('learner', 'Add a learner', route('learners.create'), $hasLearner),
            ]),
            $this->group(self::GROUP_BOOKING, 'At your first booking', 'You pay by card when you book. Cards are in test mode for now, so no real money moves.', true, [
                $this->item('card', 'Add a payment card', SaveTestCard::available() ? route('payment-methods.create') : null, $hasCard),
            ]),
            $this->group(self::GROUP_OPTIONAL, 'Optional', 'Not needed to book, but they help tutors prepare.', false, [
                $this->item(
                    'details',
                    'School and notes for tutors',
                    $detailsLearner === null ? route('learners.create') : route('learners.edit', $detailsLearner),
                    $learners->contains(fn (Learner $learner): bool => $filled($learner->school) && $filled($learner->notes)),
                ),
                $this->item('more_learners', 'Add another learner', route('learners.create'), $learners->count() >= 2),
            ]),
        ];
    }

    /**
     * Whether a learner exists — the dashboard shows its reminder banner until then.
     */
    public function needsLearner(User $parent): bool
    {
        return ! Learner::query()->where('account_user_id', $parent->id)->exists();
    }

    /**
     * @return array{key: string, label: string, step: string, done: bool, href: string|null}
     */
    private function item(string $key, string $label, ?string $href, bool $done): array
    {
        return ['key' => $key, 'label' => $label, 'step' => $key, 'done' => $done, 'href' => $href];
    }

    /**
     * @param  list<array{key: string, label: string, step: string, done: bool, href: string|null}>  $items
     * @return array{key: string, title: string, hint: string, required: bool, complete: bool, items: list<array{key: string, label: string, step: string, done: bool, href: string|null}>}
     */
    private function group(string $key, string $title, string $hint, bool $required, array $items): array
    {
        return [
            'key' => $key,
            'title' => $title,
            'hint' => $hint,
            'required' => $required,
            'complete' => collect($items)->every(fn (array $item): bool => $item['done']),
            'items' => $items,
        ];
    }
}
