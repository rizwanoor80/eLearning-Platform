<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * R179 (PLAN cycle 13 r1): demo tutors take bookings right away (lead 0) so rehearsal tests run
 * same-day. `DemoTutorSeeder` sets this for new demo tutors, but it skips a tutor whose email
 * already exists and a second seed run on rehearsal needs a new ruling — so the demo tutors already
 * on rehearsal get the value here, on deploy (R111). Touches only demo-pattern rows that have not
 * chosen a lead time; a no-op on a database without them (production, CI, a fresh local).
 * Disclosed in STATUS §6 (DEVIATION in CYCLE-LOG).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('tutor_profiles')
            ->whereNull('min_lead_hours')
            ->whereIn('user_id', DB::table('users')
                ->where('email', 'like', 'demo.%@example.test')
                ->select('id'))
            ->update(['min_lead_hours' => 0]);
    }

    public function down(): void
    {
        // Forward-only data change: not reversed (the value is a tutor-editable setting).
    }
};
