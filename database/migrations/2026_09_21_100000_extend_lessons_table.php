<?php

use App\Enums\LessonStatus;
use App\Enums\LessonType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * CP3 (3b): the full lessons table per DATA_MODEL, added to the CP2 stub with
 * `Schema::table` (the stub and its slot index are not touched). The new
 * NOT NULL columns have no honest default, so the migration refuses to run over
 * existing rows — there are none on any server (the stub was only read by
 * SlotCalculator).
 *
 * `payment_method_id` is a plain nullable column without a foreign key:
 * `payment_methods` arrives in CP5, which adds the constraint.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::table('lessons')->exists()) {
            throw new RuntimeException('lessons already holds rows; the CP3 columns need an explicit backfill, not a default.');
        }

        Schema::table('lessons', function (Blueprint $table) {
            $table->enum('type', array_column(LessonType::cases(), 'value'));
            $table->foreignId('recurring_slot_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('learner_id')->constrained()->restrictOnDelete();
            $table->foreignId('booked_by_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('curriculum_id')->constrained()->restrictOnDelete();
            $table->foreignId('subject_id')->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('duration_minutes')->default(60);

            // Frozen at creation (invariants #6 and #11): never re-read from settings.
            $table->unsignedBigInteger('price');
            $table->unsignedSmallInteger('commission_pct');
            $table->unsignedBigInteger('commission_amount');
            $table->unsignedBigInteger('tutor_amount');
            $table->unsignedSmallInteger('cancel_window_hours');
            $table->unsignedSmallInteger('student_grace_min');
            $table->unsignedSmallInteger('tutor_grace_min');

            $table->unsignedBigInteger('payment_method_id')->nullable();
            $table->unsignedSmallInteger('charge_attempts')->default(0);
            $table->timestamp('next_charge_at')->nullable();

            $table->string('room_provider')->nullable();
            $table->string('room_id')->nullable();
            $table->text('tutor_join_url')->nullable();
            $table->text('learner_join_url')->nullable();
            $table->timestamp('room_created_at')->nullable();
            $table->timestamp('tutor_joined_at')->nullable();
            $table->timestamp('learner_joined_at')->nullable();
            $table->timestamp('room_closed_at')->nullable();

            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->text('cancel_reason')->nullable();
            $table->timestamp('report_due_at')->nullable();
            $table->timestamp('escrow_released_at')->nullable();

            $table->index(['learner_id', 'starts_at']);
            $table->index('report_due_at');
        });

        DB::statement("CREATE INDEX lessons_next_charge_at_reserved ON lessons (next_charge_at) WHERE status = 'reserved'");

        // One trial per learner–tutor pair (invariant #12). "Cancelled" here is the same set that
        // frees the tutor's slot, so a cancelled, expired or refunded trial does not consume it.
        $freeing = implode("', '", LessonStatus::freeingSlotValues());
        DB::statement("CREATE UNIQUE INDEX lessons_one_trial_per_pair ON lessons (learner_id, tutor_profile_id) WHERE type = 'trial' AND status NOT IN ('{$freeing}')");
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS lessons_one_trial_per_pair');
        DB::statement('DROP INDEX IF EXISTS lessons_next_charge_at_reserved');

        Schema::table('lessons', function (Blueprint $table) {
            $table->dropIndex(['learner_id', 'starts_at']);
            $table->dropIndex(['report_due_at']);
            $table->dropConstrainedForeignId('cancelled_by_user_id');
            $table->dropConstrainedForeignId('recurring_slot_id');
            $table->dropConstrainedForeignId('learner_id');
            $table->dropConstrainedForeignId('booked_by_user_id');
            $table->dropConstrainedForeignId('curriculum_id');
            $table->dropConstrainedForeignId('subject_id');
            $table->dropColumn([
                'type', 'duration_minutes', 'price', 'commission_pct', 'commission_amount', 'tutor_amount',
                'cancel_window_hours', 'student_grace_min', 'tutor_grace_min', 'payment_method_id',
                'charge_attempts', 'next_charge_at', 'room_provider', 'room_id', 'tutor_join_url',
                'learner_join_url', 'room_created_at', 'tutor_joined_at', 'learner_joined_at',
                'room_closed_at', 'completed_at', 'cancelled_at', 'cancel_reason', 'report_due_at',
                'escrow_released_at',
            ]);
        });
    }
};
