<?php

namespace App\Support\Mail;

use App\Support\Facades\Settings;
use Illuminate\Mail\Mailables\Address;

trait UsesSettingsSender
{
    /**
     * Reads the sender name/address fresh from `settings` at send time
     * (called from `envelope()`, never cached at provider boot — a queued
     * worker boots once, so a boot-time read would go stale and CP1 box 8's
     * "next email" guarantee would break). `from_name` defaults to `TrusTutor`
     * when empty (R174a) — the site name is not part of the chain, so a
     * renamed site or a stray site-name value cannot leak into the From header.
     */
    protected function settingsFromAddress(): Address
    {
        $address = Settings::get('from_address') ?: config('mail.from.address');

        return new Address($address, MailBrand::senderName());
    }

    protected function settingsReplyToAddress(): ?Address
    {
        $replyTo = Settings::get('reply_to');

        return $replyTo !== null ? new Address($replyTo) : null;
    }
}
