<?php

use App\Enums\TrialSuitability;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DATA_MODEL v1.6 (CP6 7e, R124, R126): the progress report, and the two lesson columns the report
 * deadline needs. `auto_release_at` freezes the 72 h deadline on the lesson when it completes (a settings
 * change never moves it); `report_late_at` marks a lesson whose escrow the platform released because no
 * report came in time — the tutor's late flags are counted from these rows, never from a stored number.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('progress_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lesson_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('tutor_profile_id')->constrained()->restrictOnDelete();
            $table->text('topics_covered');
            $table->text('went_well');
            $table->text('work_on_next');
            $table->text('homework');
            $table->unsignedTinyInteger('engagement');
            $table->enum('trial_suitability', array_column(TrialSuitability::cases(), 'value'))->nullable();
            $table->unsignedTinyInteger('trial_recommended_frequency')->nullable();
            $table->text('trial_focus_areas')->nullable();
            $table->timestamp('submitted_at');
            $table->timestamp('emailed_at')->nullable();
            $table->timestamps();

            $table->index(['tutor_profile_id', 'submitted_at']);
        });

        Schema::table('lessons', function (Blueprint $table) {
            $table->timestamp('auto_release_at')->nullable();
            $table->timestamp('report_late_at')->nullable();
            $table->index(['tutor_profile_id', 'report_late_at']);
        });
    }

    public function down(): void
    {
        Schema::table('lessons', function (Blueprint $table) {
            $table->dropIndex(['tutor_profile_id', 'report_late_at']);
            $table->dropColumn(['auto_release_at', 'report_late_at']);
        });

        Schema::dropIfExists('progress_reports');
    }
};
