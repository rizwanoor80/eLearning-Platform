<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Setting defaults
    |--------------------------------------------------------------------------
    |
    | Fallback values `SettingsService::get()` returns when no row exists yet
    | for a key, keyed flat regardless of group. This is also the single
    | source `SettingsSeeder` reads from to populate the `settings` table —
    | keep it as the one place these values are written.
    |
    */

    'defaults' => [
        'commission_pct' => 25,
        'trial_discount_pct' => 50,
        'cancel_window_hours' => 24,
        'student_grace_min' => 15,
        'tutor_grace_min' => 10,
        'report_due_hours' => 24,
        'auto_release_hours' => 72,
        'payout_weekday' => 1,
        'payout_min' => 20000,
        'booking_min_lead_hours' => 12,
        'booking_max_days' => 30,
        'recurring_horizon_weeks' => 4,
        'recurring_charge_lead_hours' => 48,
        'recurring_retry_hours' => [36, 24],
        'recurring_pause_after_failures' => 2,
        'recurring_tutor_end_notice_days' => 7,
        'vat_pct' => 5,
        'currency_code' => 'AED',
        'currency_symbol' => 'AED',
        'default_timezone' => 'Asia/Dubai',

        'site_name' => 'project-elearning',
        'tagline' => null,
        'logo_path' => null,
        'favicon_path' => null,
        'contact_email' => null,
        'contact_phone' => null,
        'contact_address' => null,
        'social_links' => [],
        'footer_text' => null,
        'head_scripts' => null,
        'legal_entity_name' => null,
        'legal_entity_trn' => null,
        'legal_entity_address' => null,

        'from_name' => null,
        'from_address' => null,
        'reply_to' => null,
        'support_address' => null,
        'email_footer' => null,

        'match_requests' => true,
        'reviews' => true,
        'messaging' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Groups
    |--------------------------------------------------------------------------
    |
    | Which `SettingGroup` each key above belongs to. `SettingsService::get()`
    | ignores this — it reads by key regardless of group — this map exists
    | only so `SettingsSeeder` and the Filament settings editor's tabs can
    | agree on where a key lives without duplicating the key list.
    |
    | @var array<string, list<string>>
    */
    'groups' => [
        'platform' => [
            'commission_pct', 'trial_discount_pct', 'cancel_window_hours', 'student_grace_min',
            'tutor_grace_min', 'report_due_hours', 'auto_release_hours', 'payout_weekday', 'payout_min',
            'booking_min_lead_hours', 'booking_max_days', 'recurring_horizon_weeks',
            'recurring_charge_lead_hours', 'recurring_retry_hours', 'recurring_pause_after_failures',
            'recurring_tutor_end_notice_days', 'vat_pct', 'currency_code', 'currency_symbol', 'default_timezone',
        ],
        'site' => [
            'site_name', 'tagline', 'logo_path', 'favicon_path', 'contact_email', 'contact_phone',
            'contact_address', 'social_links', 'footer_text', 'head_scripts',
            'legal_entity_name', 'legal_entity_trn', 'legal_entity_address',
        ],
        'mail' => [
            'from_name', 'from_address', 'reply_to', 'support_address', 'email_footer',
        ],
        'features' => [
            'match_requests', 'reviews', 'messaging',
        ],
    ],

];
