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
     * "next email" guarantee would break). `from_name` falls back to
     * `site_name` when unset, matching box 8's wording.
     */
    protected function settingsFromAddress(): Address
    {
        $name = Settings::get('from_name') ?: Settings::get('site_name', config('app.name'));
        $address = Settings::get('from_address') ?: config('mail.from.address');

        return new Address($address, $name);
    }

    protected function settingsReplyToAddress(): ?Address
    {
        $replyTo = Settings::get('reply_to');

        return $replyTo !== null ? new Address($replyTo) : null;
    }
}
