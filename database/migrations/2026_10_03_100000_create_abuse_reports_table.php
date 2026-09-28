<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * DATA_MODEL.md §abuse_reports (CP7, R137): a report is filed against a subject resolved
 * polymorphically WITHOUT Eloquent `morphTo()` or a Laravel morph map (neither exists in
 * this codebase) — `subject_type` stores the DATA_MODEL short alias directly, matched in
 * PHP by `App\Enums\AbuseReportSubjectType`. `AnonymizeUser` soft-deletes `users` (never
 * hard-deletes), so `restrictOnDelete()` on both `reporter_user_id` and `handled_by` needs
 * no special cascade handling.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('abuse_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reporter_user_id')->constrained('users')->restrictOnDelete();
            $table->string('subject_type');
            $table->unsignedBigInteger('subject_id');
            $table->string('reason');
            $table->text('description');
            $table->string('status')->default('open');
            $table->text('action_taken')->nullable();
            $table->foreignId('handled_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();

            $table->index('status');
            $table->index(['subject_type', 'subject_id']);
        });

        DB::unprepared("ALTER TABLE abuse_reports ADD CONSTRAINT abuse_reports_subject_type_check CHECK (subject_type IN ('tutor_profile', 'user', 'lesson', 'conversation'))");
        DB::unprepared("ALTER TABLE abuse_reports ADD CONSTRAINT abuse_reports_reason_check CHECK (reason IN ('safety', 'contact_sharing', 'conduct', 'other'))");
        DB::unprepared("ALTER TABLE abuse_reports ADD CONSTRAINT abuse_reports_status_check CHECK (status IN ('open', 'reviewing', 'closed'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('abuse_reports');
    }
};
