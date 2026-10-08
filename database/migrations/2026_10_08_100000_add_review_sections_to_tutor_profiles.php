<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * R185 (PLAN cycle 14 r1): "Request changes" becomes a checklist of onboarding sections plus an
 * optional note. The section keys (App\Enums\TutorReviewSection values) are stored as a JSON list
 * next to `review_note`; null means no sections were named (a note-only request, or no request).
 * Cleared wherever `review_note` is cleared.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tutor_profiles', function (Blueprint $table): void {
            $table->jsonb('review_sections')->nullable()->after('review_note');
        });
    }

    public function down(): void
    {
        Schema::table('tutor_profiles', function (Blueprint $table): void {
            $table->dropColumn('review_sections');
        });
    }
};
