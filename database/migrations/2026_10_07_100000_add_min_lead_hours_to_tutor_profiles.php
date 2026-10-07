<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * R179 (PLAN cycle 13 r1): the booking lead time becomes the tutor's choice. Nullable with no DB
 * default on purpose: null means "the platform default" (`booking_min_lead_hours`, 12 unless the
 * admin changed it), so every existing tutor is unchanged. Read only through `BookingLeadTime`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tutor_profiles', function (Blueprint $table): void {
            $table->unsignedSmallInteger('min_lead_hours')->nullable()->after('hourly_rate');
        });
    }

    public function down(): void
    {
        Schema::table('tutor_profiles', function (Blueprint $table): void {
            $table->dropColumn('min_lead_hours');
        });
    }
};
