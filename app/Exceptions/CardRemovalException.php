<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * `RemoveSavedCard` throws this when a saved card is not removed: the account has none, a weekly slot is
 * active and the parent has not confirmed, or a payment with the card is still in progress. The message is safe to
 * show the parent.
 */
class CardRemovalException extends RuntimeException
{
    /** Set when the only thing missing is the parent's confirmation, so the page can ask for it. */
    public bool $needsConfirmation = false;

    public static function confirmationNeeded(): self
    {
        $e = new self('A weekly slot is active. If you remove this card, the next automatic charge will fail until you add a new one.');
        $e->needsConfirmation = true;

        return $e;
    }
}
