<?php

use App\Enums\RecurringSlotStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Minimal recurring_slots table for SlotCalculator (CP2). CP4 extends it with
 * learner, subject, pause/end and charge-failure columns via `Schema::table`.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('recurring_slots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tutor_profile_id')->constrained()->restrictOnDelete();
            $table->unsignedTinyInteger('weekday');
            $table->time('start_time');
            $table->string('timezone');
            $table->date('starts_on');
            $table->date('ends_on')->nullable();
            $table->enum('status', array_column(RecurringSlotStatus::cases(), 'value'))->default(RecurringSlotStatus::Active->value);
            $table->timestamps();

            $table->index('tutor_profile_id');
        });

        DB::statement("CREATE UNIQUE INDEX recurring_slots_active_unique ON recurring_slots (tutor_profile_id, weekday, start_time, timezone) WHERE status = 'active'");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('recurring_slots');
    }
};
