<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * R170/R171 (PLAN cycle 12 r1): tutors may be anywhere, so onboarding asks for a country, and
 * CV-or-LinkedIn becomes an alternative to the permit document. The permit (and every other
 * document type) stops being a hard requirement — `document_types.required` flips to `false` for
 * every existing row, and a new `cv` type is added, also not required (R171: "most other fields
 * optional", enforced minimums move to `ApproveTutor`/`TutorApprovalReadiness`, not to onboarding).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tutor_profiles', function (Blueprint $table): void {
            $table->string('country', 2)->nullable()->after('user_id');
            $table->string('linkedin_url')->nullable()->after('intro_video_url');
        });

        DB::table('document_types')->update(['required' => false]);

        DB::table('document_types')->updateOrInsert(
            ['code' => 'cv'],
            [
                'name' => 'CV / resume',
                'description' => 'A CV or resume, used for vetting instead of a tutoring permit.',
                'required' => false,
                'active' => true,
                'sort' => (int) DB::table('document_types')->max('sort') + 1,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );
    }

    public function down(): void
    {
        Schema::table('tutor_profiles', function (Blueprint $table): void {
            $table->dropColumn(['country', 'linkedin_url']);
        });
    }
};
