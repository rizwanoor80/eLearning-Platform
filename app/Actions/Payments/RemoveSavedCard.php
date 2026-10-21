<?php

namespace App\Actions\Payments;

use App\Actions\RecordAuditLog;
use App\Enums\PaymentStatus;
use App\Enums\RecurringSlotStatus;
use App\Exceptions\CardRemovalException;
use App\Jobs\Payments\DetachRemovedCard;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\RecurringSlot;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * 24c (R261): a parent removes the saved card. The row is kept and stamped `removed_at`: payments and lessons
 * point at it, so history stays readable, and the unique index covers live rows only, so a new card can be
 * added at once. The stamp is written first, under the row lock, so nothing can charge the card from then on;
 * a Stripe card is then detached at the gateway by a queued job (`DetachRemovedCard`), retried while the
 * gateway is down. The gateway is never called inside the transaction.
 *
 * If an active weekly slot would lose its card, the parent must confirm first. After removal that slot's next
 * charge finds no usable card and fails through the existing failure path (`no_usable_card`: the charge is
 * retried or the lesson cancelled, and the parent is emailed), so nothing here touches slots. A card with a
 * charge in flight is not removed until that charge has finished, so a parent never causes a decline.
 *
 * Deliberately not on the frozen `PaymentGateway` interface: like `SaveStripeCard`, it reaches the detach call
 * outside `app/Services/Payments`.
 */
class RemoveSavedCard
{
    public function __construct(private readonly RecordAuditLog $audit) {}

    /** Whether removing the account's card would leave an active weekly slot without one. */
    public static function hasActiveSlot(User $account): bool
    {
        return RecurringSlot::query()
            ->where('status', RecurringSlotStatus::Active->value)
            ->whereHas('learner', fn ($q) => $q->where('account_user_id', $account->id))
            ->exists();
    }

    /**
     * @throws CardRemovalException
     */
    public function __invoke(User $account, bool $confirmed): PaymentMethod
    {
        if (! $confirmed && self::hasActiveSlot($account)) {
            throw CardRemovalException::confirmationNeeded();
        }

        $card = DB::transaction(function () use ($account): PaymentMethod {
            $locked = PaymentMethod::query()->live()->where('account_user_id', $account->id)->lockForUpdate()->first()
                ?? throw new CardRemovalException('You have no saved card to remove.');

            $inFlight = Payment::query()
                ->where('payment_method_id', $locked->id)
                ->where('status', PaymentStatus::Pending->value)
                ->exists();

            if ($inFlight) {
                throw new CardRemovalException('A payment with this card is in progress. Please try again in a few minutes.');
            }

            $locked->forceFill(['removed_at' => now()])->save();

            ($this->audit)($account, 'payment_method.removed', $locked, null, [
                'gateway' => $locked->gateway,
                'last4' => $locked->last4,
                'active_slot' => self::hasActiveSlot($account),
            ]);

            return $locked;
        });

        if ($card->gateway === 'stripe') {
            DetachRemovedCard::dispatch($card->id);
        }

        return $card;
    }
}
