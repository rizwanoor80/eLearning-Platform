<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * DATA_MODEL v1.7 (CP7 8c, R136): one review per lesson, always for the tutor's `tutor_profile_id` and the
 * account holder that booked it (never the learner — invariant #7). `published_at` doubles as the
 * publish/unpublish state: set on create ("published immediately"), nulled by an admin unpublish,
 * reset by a republish. No separate flag or note column — the admin's note lives in the `audit_logs`
 * row `RecordAuditLog` writes alongside each unpublish/republish (PRD §12 audit discipline), not here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lesson_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('tutor_profile_id')->constrained()->restrictOnDelete();
            $table->foreignId('account_user_id')->constrained('users')->restrictOnDelete();
            $table->unsignedTinyInteger('rating');
            $table->string('comment', 1000)->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();

            $table->index('tutor_profile_id');
            $table->index(['tutor_profile_id', 'published_at']);
        });

        DB::unprepared('ALTER TABLE reviews ADD CONSTRAINT reviews_rating_between_1_and_5 CHECK (rating BETWEEN 1 AND 5)');
    }

    public function down(): void
    {
        Schema::dropIfExists('reviews');
    }
};
