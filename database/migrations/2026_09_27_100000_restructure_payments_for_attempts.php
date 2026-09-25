<?php

use App\Enums\PaymentStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * CP4 (4e, R101): one lesson can now have several payment attempts, so `payments.lesson_id UNIQUE`
 * (the CP3 shape: one payment row per lesson) becomes `attempt_no` with `unique (lesson_id,
 * attempt_no)`. A failed attempt is its own row; the partial unique index keeps "one live payment
 * per lesson" — at most one row per lesson whose status is not `failed` — so a second capture on a
 * lesson is refused by the database, exactly as the old unique refused it, and `BookLesson`'s
 * idempotency guard (its `pending` row written before the gateway is called) still holds.
 *
 * `attempt_no` defaults to 1, so `BookLesson` (one attempt, never retried) is unchanged and any
 * existing row (rehearsal has none) is attempt 1. Also adds the foreign key
 * `payments.payment_method_id` -> `payment_methods` that the 4a migration left to this one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropUnique(['lesson_id']);
            $table->unsignedSmallInteger('attempt_no')->default(1)->after('lesson_id');
            $table->unique(['lesson_id', 'attempt_no']);
            $table->foreign('payment_method_id')->references('id')->on('payment_methods')->restrictOnDelete();
        });

        DB::statement(
            'CREATE UNIQUE INDEX payments_one_live_per_lesson ON payments (lesson_id) WHERE status <> '
            ."'".PaymentStatus::Failed->value."'"
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS payments_one_live_per_lesson');

        Schema::table('payments', function (Blueprint $table) {
            $table->dropForeign(['payment_method_id']);
            $table->dropUnique(['lesson_id', 'attempt_no']);
            $table->dropColumn('attempt_no');
            $table->unique('lesson_id');
        });
    }
};
