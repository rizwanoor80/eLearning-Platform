<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 23h (R251, R252): payout batches. A batch is one admin run; each line is one tutor's payout inside it,
 * in one method, for one amount in fils. A line is born `pending` (the tutor's "processing" money) and
 * ends `paid` (it needs an external reference and writes the `payout` ledger entries) or `failed` (the
 * money is available again; nothing is written to the ledger).
 *
 * `details` is the method's account details copied from the tutor's profile at generation (encrypted,
 * one JSON object), so the file an admin pays from and the money a tutor is owed cannot drift if the
 * tutor edits their bank details afterwards. The statuses are listed here, not built from the enum.
 * The foreign key from `ledger_entries.payout_id` that the ledger migration left for this table is added
 * in the next migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payout_batches', function (Blueprint $table) {
            $table->id();
            $table->date('period_start')->nullable();
            $table->date('period_end');
            $table->foreignId('created_by_user_id')->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });

        Schema::create('payout_batch_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payout_batch_id')->constrained()->restrictOnDelete();
            $table->foreignId('tutor_profile_id')->constrained()->restrictOnDelete();
            $table->string('payout_method');
            $table->unsignedBigInteger('amount');
            $table->string('currency', 3);
            $table->string('reference')->unique();
            $table->text('details');
            $table->string('status')->default('pending');
            $table->string('external_reference')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->string('failure_reason', 500)->nullable();
            $table->foreignId('resolved_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->index(['tutor_profile_id', 'status']);
            $table->index(['payout_batch_id', 'payout_method']);
            $table->unique(['payout_batch_id', 'tutor_profile_id']);
        });

        // One unpaid line per tutor at a time: a tutor with money in a batch that has not been paid out yet is not put in a
        // second one, and the "is there enough to pay" check never has to add up several lines of the same tutor.
        DB::unprepared("CREATE UNIQUE INDEX payout_batch_lines_one_pending_per_tutor ON payout_batch_lines (tutor_profile_id) WHERE status = 'pending'");

        DB::unprepared("ALTER TABLE payout_batch_lines ADD CONSTRAINT payout_batch_lines_status_check CHECK (status IN ('pending', 'paid', 'failed'))");
        DB::unprepared('ALTER TABLE payout_batch_lines ADD CONSTRAINT payout_batch_lines_amount_positive CHECK (amount > 0)');
        DB::unprepared("ALTER TABLE payout_batch_lines ADD CONSTRAINT payout_batch_lines_paid_needs_reference CHECK (status <> 'paid' OR (external_reference IS NOT NULL AND btrim(external_reference) <> '' AND paid_at IS NOT NULL))");
    }

    public function down(): void
    {
        Schema::dropIfExists('payout_batch_lines');
        Schema::dropIfExists('payout_batches');
    }
};
