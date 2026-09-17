<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Setting defaults
    |--------------------------------------------------------------------------
    |
    | Fallback values `SettingsService::get()` returns when no row exists yet
    | for a key. This is also the single source the CP0 seeder reads from to
    | populate the `settings` table — keep it as the one place these values
    | are written. Only the platform group is populated in CP0; site, mail
    | and features keys land in CP1 alongside the Filament editor.
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
    ],

];
