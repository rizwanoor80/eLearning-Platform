<?php

use App\Enums\RecurringSlotPauseReason;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * CP4 (4a): the full `recurring_slots` table per DATA_MODEL v1.5, added to the CP2 stub
 * with `Schema::table`. The new NOT NULL columns have no honest default, so the migration
 * refuses to run over existing rows — there are none on any server (checked 2026-09-25).
 *
 * `price` (fils) is the weekly price agreed once and frozen on the slot (R95). `generated_until`
 * is the last date lessons were generated through (`starts_on − 1 day` at creation).
 *
 * The stub's `recurring_slots_active_unique` covered `status = 'active'` only. A paused slot
 * keeps its place (R99), so the index is replaced by one over `active` and `paused`; `ended`
 * frees the weekday/time.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::table('recurring_slots')->exists()) {
            throw new RuntimeException('recurring_slots already holds rows; the CP4 columns need an explicit backfill, not a default.');
        }

        Schema::table('recurring_slots', function (Blueprint $table) {
            $table->foreignId('learner_id')->constrained()->restrictOnDelete();
            $table->foreignId('curriculum_id')->constrained()->restrictOnDelete();
            $table->foreignId('subject_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('price');
            $table->enum('paused_reason', array_column(RecurringSlotPauseReason::cases(), 'value'))->nullable();
            $table->foreignId('ended_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('ended_at')->nullable();
            $table->date('end_effective_on')->nullable();
            $table->unsignedSmallInteger('consecutive_charge_failures')->default(0);
            $table->date('generated_until');
            $table->foreignId('created_by_user_id')->constrained('users')->restrictOnDelete();
        });

        DB::statement('DROP INDEX recurring_slots_active_unique');
        DB::statement("CREATE UNIQUE INDEX recurring_slots_live_unique ON recurring_slots (tutor_profile_id, weekday, start_time, timezone) WHERE status IN ('active', 'paused')");
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS recurring_slots_live_unique');
        DB::statement("CREATE UNIQUE INDEX recurring_slots_active_unique ON recurring_slots (tutor_profile_id, weekday, start_time, timezone) WHERE status = 'active'");

        Schema::table('recurring_slots', function (Blueprint $table) {
            $table->dropConstrainedForeignId('learner_id');
            $table->dropConstrainedForeignId('curriculum_id');
            $table->dropConstrainedForeignId('subject_id');
            $table->dropConstrainedForeignId('ended_by_user_id');
            $table->dropConstrainedForeignId('created_by_user_id');
            $table->dropColumn(['price', 'paused_reason', 'ended_at', 'end_effective_on', 'consecutive_charge_failures', 'generated_until']);
        });
    }
};
