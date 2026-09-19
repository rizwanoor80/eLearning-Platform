<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * R36: the tutor queue's "Submitted" column sorted on `created_at` — the day
 * the profile was first opened, not the day it was (re)submitted. This column
 * is set at every submit (CompleteTutorOnboarding). Rows that are already past
 * `draft` are backfilled from `created_at`, the best date available.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tutor_profiles', function (Blueprint $table) {
            $table->timestamp('submitted_at')->nullable();
        });

        DB::table('tutor_profiles')->where('status', '!=', 'draft')->update(['submitted_at' => DB::raw('created_at')]);
    }

    public function down(): void
    {
        Schema::table('tutor_profiles', function (Blueprint $table) {
            $table->dropColumn('submitted_at');
        });
    }
};
