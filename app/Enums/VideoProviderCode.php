<?php

namespace App\Enums;

/**
 * The codes DATA_MODEL `video_providers` allows. Only `daily` and `fake` have a driver in v1
 * (PRD §9); `zoom`, `meet` and `teams` are named so a later driver has its slot, but a row for
 * one cannot be activated until its driver exists.
 */
enum VideoProviderCode: string
{
    case Daily = 'daily';
    case Zoom = 'zoom';
    case Meet = 'meet';
    case Teams = 'teams';
    case Fake = 'fake';

    public function hasDriver(): bool
    {
        return in_array($this, [self::Daily, self::Fake], true);
    }

    /**
     * The credential keys the admin enters for this driver, all required before it can be active.
     *
     * @return list<string>
     */
    public function credentialKeys(): array
    {
        return match ($this) {
            self::Daily => ['api_key', 'webhook_secret'],
            self::Fake => ['webhook_secret'],
            default => [],
        };
    }

    /**
     * The fake provider needs no credentials to run a lesson, only to accept a webhook, so only
     * these keys block activation.
     *
     * @return list<string>
     */
    public function requiredCredentialKeys(): array
    {
        return match ($this) {
            self::Daily => ['api_key', 'webhook_secret'],
            default => [],
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Daily => 'Daily.co',
            self::Zoom => 'Zoom',
            self::Meet => 'Google Meet',
            self::Teams => 'Microsoft Teams',
            self::Fake => 'Fake (local and rehearsal only)',
        };
    }
}
