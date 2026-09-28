<?php

use App\Enums\DisputeReason;
use App\Enums\DisputeStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * DATA_MODEL.md §disputes (CP8, R150). One dispute per lesson — enforced by a
 * DB unique index, not application logic (invariant #12's own concurrency
 * pattern for `type`). `refund_amount`, `tutor_paid_amount` and `platform_delta`
 * are the fils actually written by `LedgerService::settle()` at resolution —
 * a record of what happened, not an input; `platform_delta` is signed and may
 * be negative (a goodwill payout). `AnonymizeUser` soft-deletes `users`, never
 * hard-deletes, so `restrictOnDelete()` on both user references needs no
 * special cascade handling (same reasoning as `abuse_reports`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('disputes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lesson_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('opened_by_user_id')->constrained('users')->restrictOnDelete();
            $table->string('reason');
            $table->text('description');
            $table->string('status')->default(DisputeStatus::Open->value);
            $table->unsignedTinyInteger('parent_refund_pct')->nullable();
            $table->unsignedTinyInteger('tutor_pay_pct')->nullable();
            $table->bigInteger('refund_amount')->nullable();
            $table->bigInteger('tutor_paid_amount')->nullable();
            $table->bigInteger('platform_delta')->nullable();
            $table->text('admin_note')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index('status');
        });

        $reasons = implode("', '", array_column(DisputeReason::cases(), 'value'));
        $statuses = implode("', '", array_column(DisputeStatus::cases(), 'value'));

        DB::unprepared("ALTER TABLE disputes ADD CONSTRAINT disputes_reason_check CHECK (reason IN ('{$reasons}'))");
        DB::unprepared("ALTER TABLE disputes ADD CONSTRAINT disputes_status_check CHECK (status IN ('{$statuses}'))");
        DB::unprepared('ALTER TABLE disputes ADD CONSTRAINT disputes_pct_range_check CHECK (parent_refund_pct IS NULL OR parent_refund_pct BETWEEN 0 AND 100)');
        DB::unprepared('ALTER TABLE disputes ADD CONSTRAINT disputes_tutor_pct_range_check CHECK (tutor_pay_pct IS NULL OR tutor_pay_pct BETWEEN 0 AND 100)');
    }

    public function down(): void
    {
        Schema::dropIfExists('disputes');
    }
};
