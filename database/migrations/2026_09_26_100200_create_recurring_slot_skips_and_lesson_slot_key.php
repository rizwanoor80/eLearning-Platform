<?php

use App\Enums\LessonCancelReason;
use App\Enums\RecurringSlotSkipReason;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * CP4 (4a): the idempotency backstop for `recurring:generate` (invariant 14, R98) and the
 * skip record.
 *
 * `lessons_recurring_slot_starts_at_unique` keys a generated lesson on `(recurring_slot_id,
 * starts_at)`. It deliberately departs from R98's literal text by excluding rows cancelled
 * with `cancel_reason = 'slot_paused'` (PLAN R98 vs R99, CYCLE-LOG 2026-09-25 DEVIATION):
 * pause cancels every future `reserved` lesson and resume must be able to regenerate those
 * weeks. A parent's skip (`cancelled_by_parent` with any other reason) keeps its key, so the
 * next generation run cannot undo it. `cancel_reason` is `text NULL`, hence IS DISTINCT FROM.
 */
return new class extends Migration
{
    public function up(): void
    {
        $paused = LessonCancelReason::SlotPaused->value;
        DB::statement("CREATE UNIQUE INDEX lessons_recurring_slot_starts_at_unique ON lessons (recurring_slot_id, starts_at) WHERE recurring_slot_id IS NOT NULL AND cancel_reason IS DISTINCT FROM '{$paused}'");

        Schema::create('recurring_slot_skips', function (Blueprint $table) {
            $table->id();
            $table->foreignId('recurring_slot_id')->constrained()->restrictOnDelete();
            $table->timestamp('starts_at');
            $table->enum('reason', array_column(RecurringSlotSkipReason::cases(), 'value'));
            $table->timestamp('notified_at')->nullable();
            $table->timestamps();

            $table->unique(['recurring_slot_id', 'starts_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recurring_slot_skips');
        DB::statement('DROP INDEX IF EXISTS lessons_recurring_slot_starts_at_unique');
    }
};
